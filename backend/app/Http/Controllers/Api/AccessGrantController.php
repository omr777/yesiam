<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessGrant;
use App\Models\AccessRequest;
use App\Models\Resource;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AccessGrantController extends Controller
{
    public function __construct(
        private readonly AuditService $audit
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | CONCEDER ACCESO A PARTIR DE UNA SOLICITUD APROBADA
    |--------------------------------------------------------------------------
    */

    public function store(
        Request $request,
        AccessRequest $accessRequest
    ): JsonResponse {
        /*
         * Primera autorización:
         * rechazar rápidamente operaciones no permitidas.
         */
        Gate::authorize('grant', $accessRequest);

        $actor = $request->user();

        /*
         * Toda la operación se ejecuta en una transacción.
         *
         * Si falla la concesión o la auditoría,
         * ningún cambio debe persistir.
         */
        $grant = DB::transaction(function () use (
            $request,
            $accessRequest,
            $actor
        ) {
            /*
             * 1. Bloquear la solicitud.
             *
             * Evita que dos operaciones modifiquen
             * esta misma solicitud simultáneamente.
             */
            $lockedRequest = AccessRequest::query()
                ->whereKey($accessRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * 2. Bloquear al usuario beneficiario.
             *
             * Todos los grantings realizados mediante
             * este flujo bloquearán la misma fila
             * para el mismo usuario.
             */
            $beneficiary = User::query()
                ->whereKey($lockedRequest->requester_user_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * 3. Bloquear el recurso.
             */
            $resource = Resource::query()
                ->whereKey($lockedRequest->resource_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * 4. Proporcionar a la política los datos
             * actualizados que acabamos de bloquear.
             */
            $lockedRequest->setRelation(
                'requester',
                $beneficiary
            );

            $lockedRequest->setRelation(
                'resource',
                $resource
            );

            /*
             * 5. Repetir autorización dentro
             * de la transacción.
             *
             * No confiamos solamente en la validación
             * realizada antes de adquirir los bloqueos.
             */
            Gate::authorize('grant', $lockedRequest);

            /*
             * 6. Impedir que esta solicitud genere
             * más de una concesión.
             *
             * Además existe una restricción UNIQUE
             * en access_request_id.
             */
            $alreadyGranted = AccessGrant::query()
                ->where('access_request_id', $lockedRequest->id)
                ->lockForUpdate()
                ->first();

            if ($alreadyGranted !== null) {
                abort(
                    409,
                    'Esta solicitud ya tiene una concesión registrada.'
                );
            }

            /*
             * 7. Impedir otra concesión ACTIVA
             * para el mismo usuario y recurso.
             *
             * El bloqueo del beneficiario serializa
             * este flujo de concesión.
             */
            $existingActiveGrant = AccessGrant::query()
                ->where('user_id', $beneficiary->id)
                ->where('resource_id', $resource->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($existingActiveGrant !== null) {
                abort(
                    409,
                    'El usuario ya tiene acceso activo a este recurso.'
                );
            }

            /*
             * 8. Crear la concesión.
             *
             * Los identificadores y el administrador
             * provienen del backend, no del cliente.
             */
            $newGrant = new AccessGrant();

            $newGrant->forceFill([
                'access_request_id' => $lockedRequest->id,
                'user_id' => $beneficiary->id,
                'resource_id' => $resource->id,
                'status' => 'active',
                'granted_by_user_id' => $actor->id,
                'granted_at' => now(),
            ]);

            $newGrant->save();

            /*
             * 9. Actualizar el modelo con los valores
             * definitivos de la base de datos.
             */
            $newGrant->refresh();

            /*
             * 10. Registrar auditoría dentro
             * de la misma transacción.
             */
            $this->audit->accessGranted(
                $actor,
                $lockedRequest,
                $newGrant,
                $request->ip()
            );

            return $newGrant;
        }, 3);

        /*
         * 201 Created: nueva concesión registrada.
         */
        return response()->json([
            'message' => 'Acceso concedido correctamente.',
            'data' => $grant->load([
                'user:id,name',
                'resource:id,name,code',
                'grantedBy:id,name',
            ]),
        ], 201);
    }
}
