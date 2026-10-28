<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessGrant;
use App\Models\AccessRequest;
use App\Models\Resource;
use App\Models\User;
use App\Services\AccessEligibilityService;
use App\Services\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AccessGrantController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | DEPENDENCIAS
    |--------------------------------------------------------------------------
    */

    public function __construct(
        private readonly AuditService $audit,
        private readonly AccessEligibilityService $eligibility
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | CONCEDER ACCESO DESDE UNA SOLICITUD APROBADA
    |--------------------------------------------------------------------------
    */

    public function store(
        Request $request,
        AccessRequest $accessRequest
    ): JsonResponse {

        /*
         * 1. AUTORIZACIÓN INICIAL
         *
         * AccessGrantPolicy debe verificar:
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
         * 2. TRANSACCIÓN
         *
         * La concesión y su auditoría deben
         * confirmarse juntas.
         */

        try {

            $grant = DB::transaction(function () use (
                $request,
                $accessRequest,
                $actor
            ) {

                /*
                 * PASO 1: BLOQUEAR SOLICITUD
                 */

                $lockedRequest = AccessRequest::query()
                    ->whereKey($accessRequest->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * PASO 2: BLOQUEAR BENEFICIARIO
                 *
                 * Consultar los valores actuales
                 * de estado y departamento.
                 */

                $beneficiary = User::query()
                    ->whereKey(
                        $lockedRequest->requester_user_id
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * PASO 3: BLOQUEAR RECURSO
                 *
                 * Consultar la configuración
                 * actual del recurso.
                 */

                $resource = Resource::query()
                    ->whereKey(
                        $lockedRequest->resource_id
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * PASO 4: ACTUALIZAR RELACIONES
                 *
                 * La política debe evaluar
                 * los modelos actuales.
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
                 * PASO 5: REPETIR AUTORIZACIÓN
                 *
                 * No confiamos solamente
                 * en la comprobación inicial.
                 */

                Gate::authorize(
                    'grant',
                    $lockedRequest
                );

                /*
                 * PASO 6: EVALUAR ELEGIBILIDAD
                 *
                 * NUEVO - FASE 17.5.3
                 *
                 * Aunque la solicitud ya
                 * fue aprobada, verificamos
                 * que el beneficiario todavía
                 * cumpla las condiciones.
                 *
                 * Ejemplo:
                 *
                 * Finanzas -> Ventas
                 *
                 * Si el recurso solo permite
                 * Finanzas, se rechaza grant.
                 */

                $decision = $this->eligibility->evaluate(
                    $beneficiary,
                    $resource
                );

                if (! $decision['eligible']) {
                    throw ValidationException::withMessages([
                        'access_request_id' => [
                            'El beneficiario ya no cumple las condiciones de elegibilidad para recibir este recurso.',
                        ],
                    ]);
                }

                /*
                 * PASO 7: EVITAR CONCEDER
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
                 * PASO 8: EVITAR CONCESIONES
                 * ACTIVAS DUPLICADAS
                 *
                 * Además de esta consulta,
                 * MySQL protege la unicidad
                 * mediante su índice UNIQUE.
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
                 * PASO 9: CREAR CONCESIÓN
                 *
                 * Los atributos sensibles
                 * son establecidos por YESIAM.
                 *
                 * No se aceptan del cliente:
                 *
                 * - user_id
                 * - granted_by_user_id
                 * - status
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
                 * MySQL aplica sus restricciones
                 * de integridad y unicidad.
                 */

                $newGrant->save();

                /*
                 * PASO 10: RECUPERAR REGISTRO
                 */

                $newGrant->refresh();

                /*
                 * PASO 11: AUDITORÍA
                 *
                 * Solo registramos el acceso
                 * efectivamente concedido.
                 *
                 * La operación se revierte
                 * si esta escritura falla.
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
             * 3. MANEJO DE CONFLICTOS UNIQUE
             *
             * Conservamos las protecciones
             * establecidas en la Fase 15.
             *
             * Nunca exponemos mensajes SQL
             * directamente al cliente.
             */

            $conflictIndexes = [
                'access_grants_unique_active_user_resource',
                'access_grants_access_request_id_unique',
            ];

            /*
             * Si el error corresponde a otro
             * índice, no debemos ocultarlo.
             */

            if (! in_array(
                $exception->index,
                $conflictIndexes,
                true
            )) {
                throw $exception;
            }

            return response()->json([
                'message' =>
                    'El acceso ya fue concedido o existe una concesión activa para este recurso.',
            ], 409);
        }

        /*
         * 4. RESPUESTA EXITOSA
         *
         * Solo llegamos aquí cuando:
         *
         * - La autorización fue válida.
         * - El beneficiario es elegible.
         * - No existen duplicados.
         * - MySQL aceptó la concesión.
         * - Se registró la auditoría.
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
