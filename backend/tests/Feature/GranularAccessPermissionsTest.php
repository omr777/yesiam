<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use App\Models\AccessRequest;
use App\Models\Permission;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GranularAccessPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR USUARIO CON PERMISOS ESPECÍFICOS
    |--------------------------------------------------------------------------
    */

    private function operatorWithPermissions(
        array $permissionCodes
    ): User {
        $role = Role::create([
            'code' => 'test_operator',
            'name' => 'Operador de pruebas',
        ]);

        $permissions = Permission::query()
            ->whereIn('code', $permissionCodes)
            ->get();

        $this->assertCount(
            count($permissionCodes),
            $permissions,
            'Algún permiso no existe en RbacSeeder.'
        );

        $role->permissions()->sync(
            $permissions->pluck('id')
        );

        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $user->roles()->attach($role->id);

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR USUARIO CON ROL PREDETERMINADO
    |--------------------------------------------------------------------------
    */

    private function makeUser(string $roleCode): User
    {
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $role = Role::query()
            ->where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR SOLICITUD APROBADA
    |--------------------------------------------------------------------------
    */

    private function approvedRequest(
        User $beneficiary,
        Resource $resource,
        User $approver
    ): AccessRequest {
        $request = AccessRequest::create([
            'requester_user_id' => $beneficiary->id,
            'resource_id' => $resource->id,
            'justification' =>
                'Necesito el recurso para realizar mi trabajo.',
        ]);

        $request->forceFill([
            'status' => 'approved',
            'decided_by_user_id' => $approver->id,
            'decision_reason' =>
                'Solicitud aprobada por necesidad laboral.',
            'decided_at' => now(),
        ])->save();

        return $request->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR CONCESIÓN ACTIVA DE PRUEBA
    |--------------------------------------------------------------------------
    */

    private function activeGrant(
        AccessRequest $request,
        User $admin
    ): AccessGrant {
        $grant = new AccessGrant();

        $grant->forceFill([
            'access_request_id' => $request->id,
            'user_id' => $request->requester_user_id,
            'resource_id' => $request->resource_id,
            'status' => 'active',
            'granted_by_user_id' => $admin->id,
            'granted_at' => now(),
        ])->save();

        return $grant->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | ESCENARIO PARA PROBAR PERMISOS INDEPENDIENTES
    |--------------------------------------------------------------------------
    */

    private function scenario(array $permissions): array
    {
        $operator = $this->operatorWithPermissions(
            $permissions
        );

        $admin = $this->makeUser('iam_admin');
        $approver = $this->makeUser('resource_manager');

        $employee = $this->makeUser('employee');
        $otherEmployee = $this->makeUser('employee');

        /*
         * Primera solicitud:
         * aprobada, pero todavía sin concesión.
         */
        $vpn = Resource::create([
            'name' => 'VPN corporativa',
            'code' => 'VPN',
            'description' => 'Acceso remoto',
            'is_active' => true,
        ]);

        $requestToGrant = $this->approvedRequest(
            $employee,
            $vpn,
            $approver
        );

        /*
         * Segunda solicitud:
         * ya tiene una concesión activa.
         *
         * Utilizamos otro beneficiario
         * y otro recurso para evitar duplicados.
         */
        $crm = Resource::create([
            'name' => 'Sistema CRM',
            'code' => 'CRM',
            'description' => 'Gestión comercial',
            'is_active' => true,
        ]);

        $existingRequest = $this->approvedRequest(
            $otherEmployee,
            $crm,
            $approver
        );

        $existingGrant = $this->activeGrant(
            $existingRequest,
            $admin
        );

        return compact(
            'operator',
            'admin',
            'employee',
            'otherEmployee',
            'requestToGrant',
            'existingGrant'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 1. resources.manage NO AUTORIZA OPERACIONES IAM
    |--------------------------------------------------------------------------
    */

    public function test_resource_management_permission_does_not_grant_iam_privileges(): void
    {
        $data = $this->scenario([
            'resources.manage',
        ]);

        $operator = $data['operator'];
        $request = $data['requestToGrant'];
        $grant = $data['existingGrant'];
        $otherEmployee = $data['otherEmployee'];

        Sanctum::actingAs($operator, ['*']);

        /*
         * Administrar recursos no autoriza
         * la concesión de accesos.
         */
        $this->postJson(
            "/api/access-requests/{$request->id}/grant"
        )->assertForbidden();

        /*
         * Tampoco autoriza revocaciones.
         */
        $this->postJson(
            "/api/access-grants/{$grant->id}/revoke",
            [
                'revocation_reason' =>
                    'El usuario ya no necesita el recurso.',
            ]
        )->assertForbidden();

        /*
         * Tampoco autoriza consultar
         * inventarios de terceros.
         */
        $this->getJson(
            "/api/users/{$otherEmployee->id}/access-grants"
        )->assertForbidden();

        $this->assertDatabaseCount('access_grants', 1);

        $this->assertDatabaseHas('access_grants', [
            'id' => $grant->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. access.grant SOLO AUTORIZA CONCESIONES
    |--------------------------------------------------------------------------
    */

    public function test_grant_permission_is_independent(): void
    {
        $data = $this->scenario([
            'access.grant',
        ]);

        $operator = $data['operator'];
        $request = $data['requestToGrant'];
        $grant = $data['existingGrant'];
        $otherEmployee = $data['otherEmployee'];

        $this->assertFalse(
            $operator->hasPermission('resources.manage')
        );

        Sanctum::actingAs($operator, ['*']);

        /*
         * Puede conceder una solicitud aprobada.
         */
        $this->postJson(
            "/api/access-requests/{$request->id}/grant"
        )
            ->assertCreated()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('access_grants', [
            'access_request_id' => $request->id,
            'granted_by_user_id' => $operator->id,
            'status' => 'active',
        ]);

        /*
         * No puede revocar.
         */
        $this->postJson(
            "/api/access-grants/{$grant->id}/revoke",
            [
                'revocation_reason' =>
                    'El usuario ya no requiere acceso.',
            ]
        )->assertForbidden();

        /*
         * No puede consultar inventarios ajenos.
         */
        $this->getJson(
            "/api/users/{$otherEmployee->id}/access-grants"
        )->assertForbidden();

        $this->assertDatabaseCount('access_grants', 2);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access.granted',
            'actor_user_id' => $operator->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. access.revoke SOLO AUTORIZA REVOCACIONES
    |--------------------------------------------------------------------------
    */

    public function test_revoke_permission_is_independent(): void
    {
        $data = $this->scenario([
            'access.revoke',
        ]);

        $operator = $data['operator'];
        $request = $data['requestToGrant'];
        $grant = $data['existingGrant'];
        $otherEmployee = $data['otherEmployee'];

        $this->assertFalse(
            $operator->hasPermission('resources.manage')
        );

        Sanctum::actingAs($operator, ['*']);

        /*
         * Puede revocar una concesión ajena.
         */
        $this->postJson(
            "/api/access-grants/{$grant->id}/revoke",
            [
                'revocation_reason' =>
                    'El acceso dejó de ser necesario.',
            ]
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $this->assertDatabaseHas('access_grants', [
            'id' => $grant->id,
            'status' => 'revoked',
            'revoked_by_user_id' => $operator->id,
        ]);

        /*
         * No puede crear nuevas concesiones.
         */
        $this->postJson(
            "/api/access-requests/{$request->id}/grant"
        )->assertForbidden();

        /*
         * No puede consultar inventarios ajenos.
         */
        $this->getJson(
            "/api/users/{$otherEmployee->id}/access-grants"
        )->assertForbidden();

        $this->assertDatabaseCount('access_grants', 1);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access.revoked',
            'actor_user_id' => $operator->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. access.view_all SOLO AUTORIZA CONSULTAS
    |--------------------------------------------------------------------------
    */

    public function test_view_all_permission_is_independent(): void
    {
        $data = $this->scenario([
            'access.view_all',
        ]);

        $operator = $data['operator'];
        $request = $data['requestToGrant'];
        $grant = $data['existingGrant'];
        $otherEmployee = $data['otherEmployee'];

        Sanctum::actingAs($operator, ['*']);

        /*
         * Puede consultar los accesos ajenos.
         */
        $this->getJson(
            "/api/users/{$otherEmployee->id}/access-grants"
        )
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', $grant->id);

        /*
         * No puede conceder.
         */
        $this->postJson(
            "/api/access-requests/{$request->id}/grant"
        )->assertForbidden();

        /*
         * No puede revocar.
         */
        $this->postJson(
            "/api/access-grants/{$grant->id}/revoke",
            [
                'revocation_reason' =>
                    'El acceso ya no corresponde.',
            ]
        )->assertForbidden();

        $this->assertDatabaseHas('access_grants', [
            'id' => $grant->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. INVENTARIO PROPIO SIN PERMISOS ADMINISTRATIVOS
    |--------------------------------------------------------------------------
    */

    public function test_user_can_view_own_inventory_without_admin_permissions(): void
    {
        $data = $this->scenario([]);

        $operator = $data['operator'];
        $otherEmployee = $data['otherEmployee'];

        Sanctum::actingAs($operator, ['*']);

        /*
         * Un usuario activo puede consultar
         * su propio inventario vacío.
         */
        $this->getJson(
            "/api/users/{$operator->id}/access-grants"
        )
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);

        /*
         * Pero no puede consultar inventarios ajenos.
         */
        $this->getJson(
            "/api/users/{$otherEmployee->id}/access-grants"
        )->assertForbidden();
    }
}
