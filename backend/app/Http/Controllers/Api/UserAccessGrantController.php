<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessGrant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserAccessGrantController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | INVENTARIO DE ACCESOS DE UNA IDENTIDAD
    |--------------------------------------------------------------------------
    */

    public function index(
        Request $request,
        User $user
    ): JsonResponse {
        /*
         * 1. AUTORIZACIÓN
         *
         * Un empleado puede consultar sus accesos.
         * Consultar accesos ajenos requiere
         * privilegios administrativos.
         */
        Gate::authorize(
            'viewForUser',
            [AccessGrant::class, $user]
        );

        /*
         * 2. VALIDAR FILTROS
         *
         * Solo permitimos estados conocidos.
         * Limitamos los resultados por página.
         */
        $validated = $request->validate([
            'status' => [
                'sometimes',
                'required',
                Rule::in(['active', 'revoked']),
            ],

            'per_page' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:50',
            ],

            'page' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);

        /*
         * 3. CONSULTAR CONCESIONES
         *
         * Partimos de la relación del usuario.
         * Nunca recibimos el user_id
         * desde el cuerpo de la petición.
         */
        $query = $user->accessGrants()
            ->with([
                'resource:id,name,code,is_active',
                'accessRequest:id,status,decided_by_user_id,decided_at',
                'grantedBy:id,name',
                'revokedBy:id,name',
            ]);

        /*
         * 4. FILTRO OPCIONAL
         */
        if (isset($validated['status'])) {
            $query->where(
                'status',
                $validated['status']
            );
        }

        /*
         * 5. PAGINACIÓN
         *
         * Ordenamos del registro más reciente
         * al más antiguo.
         */
        $grants = $query
            ->orderByDesc('id')
            ->paginate($perPage);

        /*
         * 6. RESPUESTA CONTROLADA
         *
         * Devolvemos únicamente los campos
         * necesarios para el inventario.
         */
        $grants->through(function (AccessGrant $grant) {
            return [
                'id' => $grant->id,

                'status' => $grant->status,

                'resource' => $grant->resource ? [
                    'id' => $grant->resource->id,
                    'name' => $grant->resource->name,
                    'code' => $grant->resource->code,
                    'is_active' => $grant->resource->is_active,
                ] : null,

                'access_request' => $grant->accessRequest ? [
                    'id' => $grant->accessRequest->id,
                    'status' => $grant->accessRequest->status,
                    'decided_at' => $grant->accessRequest->decided_at,
                ] : null,

                'granted_at' => $grant->granted_at,

                'granted_by' => $grant->grantedBy ? [
                    'id' => $grant->grantedBy->id,
                    'name' => $grant->grantedBy->name,
                ] : null,

                'revoked_at' => $grant->revoked_at,

                'revoked_by' => $grant->revokedBy ? [
                    'id' => $grant->revokedBy->id,
                    'name' => $grant->revokedBy->name,
                ] : null,
            ];
        });

        /*
         * 7. RESPUESTA JSON
         */
        return response()->json([
            'message' => 'Inventario de accesos obtenido correctamente.',

            'user' => [
                'id' => $user->id,
                'name' => $user->name,
            ],

            'data' => $grants->items(),

            'pagination' => [
                'current_page' => $grants->currentPage(),
                'per_page' => $grants->perPage(),
                'total' => $grants->total(),
                'last_page' => $grants->lastPage(),
            ],
        ]);
    }
}
