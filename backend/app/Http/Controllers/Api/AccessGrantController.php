<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessGrant;
use App\Models\AccessRequest;
use App\Models\Resource;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
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
         * 1. AUTORIZACIÓN INICIAL
         *
         * La política debe verificar:
         *
         * - Operador activo.
         * - Permiso access.grant.
         * - Solicitud aprobada.
         * - Beneficiario activo.
         * - Recurso activo.
         * - Prohibición de autoconcesión.
         */

        Gate::authorize('grant', $accessRequest);

        $actor = $request->user();

        /*
         * 2. TRANSACCIÓN DE PROVISIONAMIENTO
         *
         * La concesión y su auditoría deben
         * confirmarse juntas.
         *
         * Si algo falla, Laravel revierte
         * los cambios de esta transacción.
         */

        try {

            $grant = DB::transaction(function () use (
                $request,
                $accessRequest,
                $actor
            ) {

                /*
                 * PASO 1: BLOQUEAR LA SOLICITUD
                 *
                 * Dos operaciones sobre una misma
                 * solicitud no deben procesarse
                 * simultáneamente.
                 */

                $lockedRequest = AccessRequest::query()
                    ->whereKey($accessRequest->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * PASO 2: BLOQUEAR AL BENEFICIARIO
                 *
                 * Este bloqueo ayuda a serializar
                 * las concesiones del mismo usuario
                 * cuando utilizan este flujo.
                 */

                $beneficiary = User::query()
                    ->whereKey(
                        $lockedRequest->requester_user_id
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * PASO 3: BLOQUEAR EL RECURSO
                 */

                $resource = Resource::query()
                    ->whereKey(
                        $lockedRequest->resource_id
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * PASO 4: ACTUALIZAR LAS RELACIONES
                 *
                 * Proporcionamos a la política
                 * los modelos bloqueados y actuales.
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
                 * PASO 5: REPETIR LA AUTORIZACIÓN
                 *
                 * No confiamos únicamente en
                 * la comprobación inicial.
                 *
                 * Se verifica el estado después
                 * de adquirir los bloqueos.
                 */

                Gate::authorize(
                    'grant',
                    $lockedRequest
                );

                /*
                 * PASO 6: EVITAR CONCEDER
                 * DOS VECES LA MISMA SOLICITUD
                 */

                $alreadyGranted = AccessGrant::query()
                    ->where(
                        'access_request_id',
                        $lockedRequest->id
                    )
                    ->lockForUpdate()
                    ->first();

                if ($alreadyGranted !== null) {

                    abort(
                        409,
                        'Esta solicitud ya tiene una concesión registrada.'
                    );
                }

                /*
                 * PASO 7: EVITAR CONCESIONES
                 * ACTIVAS DUPLICADAS
                 *
                 * Comprobación a nivel de aplicación.
                 *
                 * El índice UNIQUE de MySQL,
                 * creado en la Fase 15.1,
                 * proporciona protección adicional.
                 */

                $existingActiveGrant = AccessGrant::query()
                    ->where(
                        'user_id',
                        $beneficiary->id
                    )
                    ->where(
                        'resource_id',
                        $resource->id
                    )
                    ->where(
                        'status',
                        'active'
                    )
                    ->lockForUpdate()
                    ->first();

                if ($existingActiveGrant !== null) {

                    abort(
                        409,
                        'El usuario ya tiene acceso activo a este recurso.'
                    );
                }

                /*
                 * PASO 8: CREAR LA CONCESIÓN
                 *
                 * Los datos sensibles se establecen
                 * desde el backend.
                 *
                 * No aceptamos user_id,
                 * granted_by_user_id ni status
                 * enviados por el cliente.
                 */

                $newGrant = new AccessGrant();

                $newGrant->forceFill([
                    'access_request_id' =>
                        $lockedRequest->id,

                    'user_id' =>
                        $beneficiary->id,

                    'resource_id' =>
                        $resource->id,

                    'status' =>
                        'active',

                    'granted_by_user_id' =>
                        $actor->id,

                    'granted_at' =>
                        now(),
                ]);

                /*
                 * MySQL comprobará sus restricciones
                 * UNIQUE durante esta inserción.
                 *
                 * Si detecta un duplicado, Laravel
                 * lanzará una excepción específica.
                 */

                $newGrant->save();

                /*
                 * PASO 9: RECUPERAR EL REGISTRO
                 * CON LOS VALORES DEFINITIVOS
                 */

                $newGrant->refresh();

                /*
                 * PASO 10: REGISTRAR AUDITORÍA
                 *
                 * El evento se crea dentro
                 * de la misma transacción.
                 *
                 * Si la concesión falla,
                 * no debe quedar una auditoría
                 * falsa de acceso concedido.
                 */

                $this->audit->accessGranted(
                    $actor,
                    $lockedRequest,
                    $newGrant,
                    $request->ip()
                );

                return $newGrant;

            }, 3);

        } catch (UniqueConstraintViolationException $exception) {

            /*
             * 3. MANEJAR CONFLICTOS DE UNICIDAD
             *
             * Laravel identifica las violaciones
             * de restricciones UNIQUE.
             *
             * Solo convertimos a HTTP 409
             * los índices relacionados con
             * una concesión duplicada.
             *
             * Nunca devolvemos mensajes SQL
             * directamente al cliente.
             */

            $conflictIndexes = [

                /*
                 * Restricción de la Fase 15.1:
                 *
                 * Un único acceso activo
                 * por usuario y recurso.
                 */
                'access_grants_unique_active_user_resource',

                /*
                 * Restricción original:
                 *
                 * Una concesión por solicitud.
                 */
                'access_grants_access_request_id_unique',
            ];

            /*
             * Si la violación corresponde
             * a otro índice, o Laravel no pudo
             * identificarlo, no ocultamos el error.
             */

            if (! in_array(
                $exception->index,
                $conflictIndexes,
                true
            )) {
                throw $exception;
            }

            /*
             * Conflicto de negocio:
             *
             * La operación no puede completarse
             * porque ya existe una concesión
             * incompatible con la nueva.
             */

            return response()->json([
                'message' =>
                    'El acceso ya fue concedido o existe una concesión activa para este recurso.',
            ], 409);
        }

        /*
         * 4. RESPUESTA EXITOSA
         *
         * Solo llegamos aquí si:
         *
         * - La autorización fue válida.
         * - No se encontró un duplicado.
         * - MySQL aceptó la concesión.
         * - Se registró la auditoría.
         * - La transacción se confirmó.
         */

        return response()->json([
            'message' =>
                'Acceso concedido correctamente.',

            'data' => $grant->load([
                'user:id,name',
                'resource:id,name,code',
                'grantedBy:id,name',
            ]),
        ], 201);
    }
}
