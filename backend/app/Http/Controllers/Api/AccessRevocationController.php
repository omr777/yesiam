<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessGrant;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AccessRevocationController extends Controller
{
    public function __construct(
        private readonly AuditService $audit
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | REVOCAR UNA CONCESIÓN DE ACCESO
    |--------------------------------------------------------------------------
    */

    public function revoke(
        Request $request,
        AccessGrant $accessGrant
    ): JsonResponse {
        /*
         * 1. Primera autorización.
         *
         * Solo un operador autorizado
         * puede intentar una revocación.
         */
        Gate::authorize('revoke', $accessGrant);

        /*
         * 2. Validar el motivo.
         *
         * La justificación es obligatoria
         * para mantener trazabilidad.
         */
        $validated = $request->validate([
            'revocation_reason' => [
                'required',
                'string',
                'min:10',
                'max:1000',
            ],
        ]);

        $actor = $request->user();

        /*
         * 3. Ejecutar revocación y auditoría
         * dentro de una transacción.
         */
        $revokedGrant = DB::transaction(function () use (
            $request,
            $accessGrant,
            $validated,
            $actor
        ) {
            /*
             * 4. Recuperar y bloquear la concesión.
             *
             * Esto permite serializar los intentos
             * simultáneos de revocar el mismo grant.
             */
            $lockedGrant = AccessGrant::query()
                ->whereKey($accessGrant->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * 5. Volver a comprobar autorización
             * utilizando el registro bloqueado.
             */
            Gate::authorize('revoke', $lockedGrant);

            /*
             * 6. Comprobar estado actual.
             *
             * Una concesión revocada no puede
             * revocarse nuevamente.
             */
            if ($lockedGrant->status !== 'active') {
                abort(
                    409,
                    'Esta concesión ya no se encuentra activa.'
                );
            }

            /*
             * 7. Registrar la revocación.
             *
             * Los datos del operador provienen
             * de la sesión autenticada.
             */
            $lockedGrant->forceFill([
                'status' => 'revoked',
                'revoked_by_user_id' => $actor->id,
                'revoked_at' => now(),
                'revocation_reason' =>
                    $validated['revocation_reason'],
            ])->save();

            /*
             * 8. Recuperar los valores guardados.
             */
            $lockedGrant->refresh();

            /*
             * 9. Registrar auditoría dentro
             * de la misma transacción.
             */
            $this->audit->accessRevoked(
                $actor,
                $lockedGrant,
                $request->ip()
            );

            return $lockedGrant;
        }, 3);

        /*
         * 10. Respuesta exitosa.
         */
        return response()->json([
            'message' => 'Acceso revocado correctamente.',
            'data' => $revokedGrant->load([
                'user:id,name',
                'resource:id,name,code',
                'grantedBy:id,name',
                'revokedBy:id,name',
            ]),
        ], 200);
    }
}
