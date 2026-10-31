<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessGrant;
use App\Models\AccessRequest;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| YESIAM - RESUMEN DEL DASHBOARD
|--------------------------------------------------------------------------
|
| FASE 20
|
| GET /api/dashboard
|
| Devuelve SOLO las secciones que la
| identidad puede consultar según sus
| permisos. Una sección ausente significa
| "no autorizado", nunca "cero".
|
| Solo se devuelven conteos y eventos
| resumidos; nunca datos sensibles.
|
*/

class DashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $actor = $request->user();

        $summary = [
            'my_access' => [
                'active_grants' => $actor->accessGrants()
                    ->where('status', 'active')
                    ->count(),
            ],
        ];

        /*
         * IDENTIDADES
         */
        if ($actor->hasPermission('users.manage')) {
            $byStatus = User::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $summary['identities'] = [
                'active' => (int) ($byStatus['active'] ?? 0),
                'suspended' => (int) ($byStatus['suspended'] ?? 0),
                'disabled' => (int) ($byStatus['disabled'] ?? 0),
            ];
        }

        /*
         * DEPARTAMENTOS
         */
        if ($actor->hasPermission('departments.view')) {
            $summary['departments'] = [
                'active' => Department::query()
                    ->where('is_active', true)
                    ->count(),
                'inactive' => Department::query()
                    ->where('is_active', false)
                    ->count(),
            ];
        }

        /*
         * RECURSOS
         */
        if ($actor->hasPermission('resources.view')) {
            $summary['resources'] = [
                'active' => Resource::query()
                    ->where('is_active', true)
                    ->count(),
                'restricted' => Resource::query()
                    ->where('is_active', true)
                    ->where('department_restricted', true)
                    ->count(),
            ];
        }

        /*
         * MIS SOLICITUDES
         */
        if ($actor->hasPermission('access.request')) {
            $mine = AccessRequest::query()
                ->where('requester_user_id', $actor->id)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $summary['my_requests'] = [
                'pending' => (int) ($mine['pending'] ?? 0),
                'approved' => (int) ($mine['approved'] ?? 0),
                'rejected' => (int) ($mine['rejected'] ?? 0),
            ];
        }

        /*
         * BANDEJA DE APROBACIÓN
         *
         * Mismos criterios que
         * GET /api/access-requests/assigned.
         */
        if ($actor->hasPermission('access.approve')) {
            $summary['approvals'] = [
                'pending' => AccessRequest::query()
                    ->where('status', 'pending')
                    ->where('requester_user_id', '!=', $actor->id)
                    ->whereHas(
                        'requester',
                        fn ($query) => $query->where('status', 'active')
                    )
                    ->whereHas(
                        'resource',
                        function ($query) use ($actor) {
                            $query->where('is_active', true)
                                ->whereHas(
                                    'approvers',
                                    fn ($approvers) =>
                                        $approvers->whereKey($actor->id)
                                );
                        }
                    )
                    ->count(),
            ];
        }

        /*
         * COLA DE CONCESIÓN
         *
         * Mismos criterios que
         * GET /api/access-requests/ready-to-grant.
         */
        if ($actor->hasPermission('access.grant')) {
            $summary['provisioning'] = [
                'ready_to_grant' => AccessRequest::query()
                    ->where('status', 'approved')
                    ->where('requester_user_id', '!=', $actor->id)
                    ->whereDoesntHave('accessGrant')
                    ->whereHas(
                        'requester',
                        fn ($query) => $query->where('status', 'active')
                    )
                    ->whereHas(
                        'resource',
                        fn ($query) => $query->where('is_active', true)
                    )
                    ->count(),
            ];
        }

        /*
         * ACCESOS DE LA ORGANIZACIÓN
         */
        if ($actor->hasPermission('access.view_all')) {
            $summary['access'] = [
                'active_grants' => AccessGrant::query()
                    ->where('status', 'active')
                    ->count(),
                'revoked_grants' => AccessGrant::query()
                    ->where('status', 'revoked')
                    ->count(),
            ];
        }

        /*
         * ACTIVIDAD RECIENTE
         */
        if ($actor->hasPermission('audit.view')) {
            $summary['recent_activity'] = AuditLog::query()
                ->with('actor:id,name')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(6)
                ->get([
                    'id',
                    'actor_user_id',
                    'action',
                    'entity_type',
                    'entity_id',
                    'outcome',
                    'created_at',
                ])
                ->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'entity_type' => $log->entity_type,
                    'entity_id' => $log->entity_id,
                    'outcome' => $log->outcome,
                    'actor' => $log->actor ? [
                        'id' => $log->actor->id,
                        'name' => $log->actor->name,
                    ] : null,
                    'created_at' => $log->created_at,
                ])
                ->values();
        }

        return response()->json([
            'data' => $summary,
        ]);
    }
}
