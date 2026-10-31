<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Primero comprobamos si puede consultar auditoría.
        Gate::authorize('viewAny', AuditLog::class);

        // Validamos los filtros recibidos.
        $validated = $request->validate([
            'action' => [
                'sometimes',
                'string',
                'max:100',
            ],
            'entity_type' => [
                'sometimes',
                'string',
                'max:100',
            ],
            'entity_id' => [
                'sometimes',
                'integer',
                'min:1',
            ],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:50',
            ],
        ]);

        $query = AuditLog::query()
            ->select([
                'id',
                'actor_user_id',
                'action',
                'entity_type',
                'entity_id',
                'outcome',
                'metadata',
                'ip_address',
                'created_at',
            ]);

        // Filtrar por tipo de evento.
        if (isset($validated['action'])) {
            $query->where('action', $validated['action']);
        }

        // Filtrar por tipo de entidad.
        if (isset($validated['entity_type'])) {
            $query->where('entity_type', $validated['entity_type']);
        }

        // Filtrar por identificador de entidad.
        if (isset($validated['entity_id'])) {
            $query->where('entity_id', $validated['entity_id']);
        }

        // Mostrar primero los eventos más recientes.
        /*
         * FASE 20: incluir el nombre del actor
         * para que la bitácora sea legible.
         * Solo id y nombre; nunca el correo.
         */
        $logs = $query
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json($logs);
    }
}
