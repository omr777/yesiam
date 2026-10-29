<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Models\Resource;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AccessDecisionController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | SERVICIO DE AUDITORÍA
    |--------------------------------------------------------------------------
    */

    public function __construct(
        private readonly AuditService $audit
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | APROBAR SOLICITUD
    |--------------------------------------------------------------------------
    */

    public function approve(
        Request $request,
        AccessRequest $accessRequest
    ): JsonResponse {
        return $this->decide(
            $request,
            $accessRequest,
            'approved'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RECHAZAR SOLICITUD
    |--------------------------------------------------------------------------
    */

    public function reject(
        Request $request,
        AccessRequest $accessRequest
    ): JsonResponse {
        return $this->decide(
            $request,
            $accessRequest,
            'rejected'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PROCESAR DECISIÓN
    |--------------------------------------------------------------------------
    |
    | FASE 18.1.1
    |
    | Protocolo de bloqueos:
    |
    | 1. AccessRequest
    | 2. User (solicitante)
    | 3. Resource
    |
    | Este orden es compatible con el utilizado
    | actualmente por AccessGrantController.
    |
    | La decisión y su auditoría permanecen
    | dentro de una única transacción.
    |
    */

    private function decide(
        Request $request,
        AccessRequest $accessRequest,
        string $decision
    ): JsonResponse {

        /*
         * 1. AUTORIZACIÓN INICIAL
         */
        Gate::authorize(
            'decide',
            $accessRequest
        );

        /*
         * 2. VALIDAR MOTIVO
         *
         * El motivo es obligatorio tanto
         * para aprobar como para rechazar.
         */
        $validated = $request->validate([
            'decision_reason' => [
                'required',
                'string',
                'min:10',
                'max:1000',
            ],
        ]);

        /*
         * 3. RESPONSABLE AUTENTICADO
         */
        $actor = $request->user();

        /*
        |--------------------------------------------------------------------------
        | TRANSACCIÓN: DECISIÓN + AUDITORÍA
        |--------------------------------------------------------------------------
        */

        $result = DB::transaction(function () use (
            $accessRequest,
            $validated,
            $actor,
            $request,
            $decision
        ) {

            /*
             * 4. BLOQUEAR SOLICITUD
             *
             * Primera entidad del protocolo.
             *
             * Dos responsables no deben decidir
             * simultáneamente sobre la misma
             * solicitud utilizando estados antiguos.
             */
            $lockedRequest = AccessRequest::query()
                ->whereKey($accessRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * 5. BLOQUEAR IDENTIDAD SOLICITANTE
             *
             * FASE 18.1.1
             *
             * CORRECCIÓN:
             *
             * El usuario se bloquea ANTES
             * del recurso.
             *
             * Orden:
             *
             * AccessRequest -> User -> Resource
             *
             * Evitamos invertir el orden
             * utilizado en AccessGrantController.
             */
            $requester = User::query()
                ->whereKey(
                    $lockedRequest->requester_user_id
                )
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * 6. BLOQUEAR RECURSO
             *
             * Recuperamos el estado actual
             * después de bloquear al usuario.
             */
            $resource = Resource::query()
                ->whereKey(
                    $lockedRequest->resource_id
                )
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * 7. ACTUALIZAR RELACIONES
             *
             * La política de solicitudes
             * debe evaluar las entidades
             * recuperadas dentro de la
             * transacción.
             */
            $lockedRequest->setRelation(
                'resource',
                $resource
            );

            $lockedRequest->setRelation(
                'requester',
                $requester
            );

            /*
             * 8. REVALIDAR AUTORIZACIÓN
             *
             * La solicitud puede haber cambiado
             * de estado mientras esperábamos
             * adquirir sus bloqueos.
             *
             * La política se ejecuta otra vez
             * con la solicitud y sus relaciones
             * actualizadas.
             */
            Gate::authorize(
                'decide',
                $lockedRequest
            );

            /*
             * 9. APLICAR DECISIÓN
             *
             * Los valores de estado, responsable
             * y fecha los establece el backend.
             */
            $lockedRequest->forceFill([
                'status' => $decision,

                'decided_by_user_id' =>
                    $actor->id,

                'decision_reason' =>
                    $validated['decision_reason'],

                'decided_at' => now(),
            ])->save();

            /*
             * 10. RECUPERAR VALORES DEFINITIVOS
             */
            $lockedRequest->refresh();

            /*
             * 11. AUDITORÍA
             *
             * Conservamos el servicio existente.
             *
             * Si falla la auditoría,
             * la transacción revierte también
             * la decisión.
             */
            $this->audit->accessDecisionRecorded(
                $actor,
                $lockedRequest,
                $request->ip()
            );

            return $lockedRequest;

        }, 3);

        /*
        |--------------------------------------------------------------------------
        | RESPUESTA HTTP
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'message' => $decision === 'approved'
                ? 'Solicitud aprobada correctamente.'
                : 'Solicitud rechazada correctamente.',

            'data' => $result->load([
                'resource:id,name,code',
                'requester:id,name',
            ]),
        ]);
    }
}
