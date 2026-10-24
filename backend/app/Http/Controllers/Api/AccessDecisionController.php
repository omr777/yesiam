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
    */

    private function decide(
        Request $request,
        AccessRequest $accessRequest,
        string $decision
    ): JsonResponse {

        // 1. Autorizar antes de procesar los datos.
        Gate::authorize('decide', $accessRequest);

        // 2. Exigir un motivo para ambas decisiones.
        $validated = $request->validate([
            'decision_reason' => [
                'required',
                'string',
                'min:10',
                'max:1000',
            ],
        ]);

        // 3. Obtener al responsable autenticado.
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
             * 4. Bloquear la solicitud.
             *
             * Impide que dos responsables modifiquen
             * simultáneamente la misma solicitud.
             */
            $lockedRequest = AccessRequest::query()
                ->whereKey($accessRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * 5. Bloquear el recurso relacionado
             * y recuperar su estado actualizado.
             */
            $resource = Resource::query()
                ->whereKey($lockedRequest->resource_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * 6. Bloquear la identidad solicitante.
             */
            $requester = User::query()
                ->whereKey($lockedRequest->requester_user_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * 7. Actualizar las relaciones que revisa
             * nuestra AccessRequestPolicy.
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
             * 8. Volver a autorizar con información
             * actualizada dentro de la transacción.
             */
            Gate::authorize('decide', $lockedRequest);

            /*
             * 9. Guardar exclusivamente los campos
             * controlados por nuestro backend.
             */
            $lockedRequest->forceFill([
                'status' => $decision,
                'decided_by_user_id' => $actor->id,
                'decision_reason' => $validated['decision_reason'],
                'decided_at' => now(),
            ])->save();

            /*
             * 10. Actualizar el objeto.
             */
            $lockedRequest->refresh();

            /*
             * 11. Registrar la decisión.
             *
             * Este método se implementará en
             * AuditService en el siguiente paso.
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
        | RESPUESTA
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
