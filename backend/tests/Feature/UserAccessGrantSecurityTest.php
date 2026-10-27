<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use App\Models\AccessRequest;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAccessGrantSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | MÉTODOS AUXILIARES
    |--------------------------------------------------------------------------
    */

    private function makeUser(
        string $roleCode = 'employee',
        string $status = 'active'
    ): User {
        $user = User::factory()->create([
            'status' => $status,
        ]);

        $role = Role::query()
            ->where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeResource(
        string $code = 'VPN',
        string $name = 'VPN corporativa'
    ): Resource {
        return Resource::create([
            'name' => $name,
            'code' => $code,
            'description' => 'Recurso utilizado en pruebas.',
            'is_active' => true,
        ]);
    }

    private function makeGrant(
        User $beneficiary,
        Resource $resource,
        User $admin,
        string $status = 'active'
    ): AccessGrant {
        /*
         * Crear la solicitud original.
         */
        $accessRequest = AccessRequest::create([
            'requester_user_id' => $beneficiary->id,
            'resource_id' => $resource->id,
            'justification' => 'Necesito acceso para trabajar.',
        ]);

        /*
         * Preparar la solicitud como aprobada.
         *
         * Estas pruebas se concentran
         * en consultar el inventario,
         * no en el proceso de aprobación.
         */
        $accessRequest->forceFill([
            'status' => 'approved',
            'decided_by_user_id' => $admin->id,
            'decision_reason' => 'Solicitud autorizada.',
            'decided_at' => now(),
        ])->save();

        /*
         * Preparar la concesión.
         */
        $grant = new AccessGrant();

        $attributes = [
            'access_request_id' => $accessRequest->id,
            'user_id' => $beneficiary->id,
            'resource_id' => $resource->id,
            'status' => $status,
            'granted_by_user_id' => $admin->id,
            'granted_at' => now(),
        ];

        if ($status === 'revoked') {
            $attributes['revoked_by_user_id'] = $admin->id;
            $attributes['revoked_at'] = now();
            $attributes['revocation_reason'] =
                'El usuario ya no requiere este acceso.';
        }

        $grant->forceFill($attributes)->save();

        return $grant->refresh();
    }

    private function endpoint(User $user): string
    {
        return "/api/users/{$user->id}/access-grants";
    }

    /*
    |--------------------------------------------------------------------------
    | 1. VISITANTE NO PUEDE CONSULTAR
    |--------------------------------------------------------------------------
    */

    public function test_guest_cannot_view_access_inventory(): void
    {
        $employee = $this->makeUser();

        $this->getJson(
            $this->endpoint($employee)
        )->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | 2. EMPLEADO PUEDE CONSULTAR SUS ACCESOS
    |--------------------------------------------------------------------------
    */

    public function test_employee_can_view_own_access_grants(): void
    {
        $employee = $this->makeUser();
        $admin = $this->makeUser('iam_admin');
        $resource = $this->makeResource();

        $grant = $this->makeGrant(
            $employee,
            $resource,
            $admin
        );

        $this->actingAs($employee, 'web')
            ->getJson($this->endpoint($employee))
            ->assertOk()
            ->assertJsonPath('user.id', $employee->id)
            ->assertJsonPath('data.0.id', $grant->id)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath(
                'data.0.resource.code',
                'VPN'
            )
            ->assertJsonPath('pagination.total', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. BOLA: EMPLEADO NO PUEDE CONSULTAR DATOS AJENOS
    |--------------------------------------------------------------------------
    */

    public function test_employee_cannot_view_another_users_grants(): void
    {
        $employee = $this->makeUser();
        $otherEmployee = $this->makeUser();

        $admin = $this->makeUser('iam_admin');
        $resource = $this->makeResource();

        $this->makeGrant(
            $otherEmployee,
            $resource,
            $admin
        );

        /*
         * El empleado modifica el identificador
         * de la URL para consultar otro usuario.
         */
        $this->actingAs($employee, 'web')
            ->getJson($this->endpoint($otherEmployee))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | 4. ADMINISTRADOR IAM PUEDE CONSULTAR OTROS USUARIOS
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_view_another_users_grants(): void
    {
        $admin = $this->makeUser('iam_admin');
        $employee = $this->makeUser();

        $resource = $this->makeResource();

        $grant = $this->makeGrant(
            $employee,
            $resource,
            $admin
        );

        $this->actingAs($admin, 'web')
            ->getJson($this->endpoint($employee))
            ->assertOk()
            ->assertJsonPath('user.id', $employee->id)
            ->assertJsonPath('data.0.id', $grant->id)
            ->assertJsonPath('pagination.total', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. RESOURCE MANAGER NO PUEDE CONSULTAR DATOS AJENOS
    |--------------------------------------------------------------------------
    */

    public function test_resource_manager_cannot_view_others_inventory(): void
    {
        $manager = $this->makeUser('resource_manager');
        $employee = $this->makeUser();

        $this->actingAs($manager, 'web')
            ->getJson($this->endpoint($employee))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | 6. CUENTAS DESACTIVADAS NO PUEDEN CONSULTAR
    |--------------------------------------------------------------------------
    */

    public function test_disabled_user_cannot_view_inventory(): void
    {
        $employee = $this->makeUser(
            'employee',
            'disabled'
        );

        $this->actingAs($employee, 'web')
            ->getJson($this->endpoint($employee))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | 7. USUARIO INEXISTENTE
    |--------------------------------------------------------------------------
    */

    public function test_unknown_user_returns_not_found(): void
    {
        $admin = $this->makeUser('iam_admin');

        $this->actingAs($admin, 'web')
            ->getJson('/api/users/999999/access-grants')
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | 8. FILTRO DE CONCESIONES ACTIVAS
    |--------------------------------------------------------------------------
    */

    public function test_active_filter_returns_only_active_grants(): void
    {
        $admin = $this->makeUser('iam_admin');
        $employee = $this->makeUser();

        $activeResource = $this->makeResource(
            'VPN',
            'VPN corporativa'
        );

        $revokedResource = $this->makeResource(
            'FIN',
            'Sistema financiero'
        );

        $activeGrant = $this->makeGrant(
            $employee,
            $activeResource,
            $admin,
            'active'
        );

        $this->makeGrant(
            $employee,
            $revokedResource,
            $admin,
            'revoked'
        );

        $this->actingAs($employee, 'web')
            ->getJson(
                $this->endpoint($employee) . '?status=active'
            )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activeGrant->id)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('pagination.total', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | 9. FILTRO DE CONCESIONES REVOCADAS
    |--------------------------------------------------------------------------
    */

    public function test_revoked_filter_returns_only_revoked_grants(): void
    {
        $admin = $this->makeUser('iam_admin');
        $employee = $this->makeUser();

        $activeResource = $this->makeResource(
            'VPN',
            'VPN corporativa'
        );

        $revokedResource = $this->makeResource(
            'FIN',
            'Sistema financiero'
        );

        $this->makeGrant(
            $employee,
            $activeResource,
            $admin,
            'active'
        );

        $revokedGrant = $this->makeGrant(
            $employee,
            $revokedResource,
            $admin,
            'revoked'
        );

        $this->actingAs($employee, 'web')
            ->getJson(
                $this->endpoint($employee) . '?status=revoked'
            )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $revokedGrant->id)
            ->assertJsonPath('data.0.status', 'revoked')
            ->assertJsonPath('pagination.total', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | 10. ESTADOS DESCONOCIDOS NO SON VÁLIDOS
    |--------------------------------------------------------------------------
    */

    public function test_invalid_status_filter_is_rejected(): void
    {
        $employee = $this->makeUser();

        $this->actingAs($employee, 'web')
            ->getJson(
                $this->endpoint($employee) . '?status=superadmin'
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    /*
    |--------------------------------------------------------------------------
    | 11. LÍMITE MÁXIMO DE RESULTADOS POR PÁGINA
    |--------------------------------------------------------------------------
    */

    public function test_per_page_cannot_exceed_fifty(): void
    {
        $employee = $this->makeUser();

        $this->actingAs($employee, 'web')
            ->getJson(
                $this->endpoint($employee) . '?per_page=100'
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    /*
    |--------------------------------------------------------------------------
    | 12. PAGINACIÓN Y ORDENAMIENTO
    |--------------------------------------------------------------------------
    */

    public function test_inventory_supports_pagination(): void
    {
        $admin = $this->makeUser('iam_admin');
        $employee = $this->makeUser();

        $first = $this->makeGrant(
            $employee,
            $this->makeResource('VPN', 'VPN corporativa'),
            $admin
        );

        $second = $this->makeGrant(
            $employee,
            $this->makeResource('FIN', 'Sistema financiero'),
            $admin
        );

        $third = $this->makeGrant(
            $employee,
            $this->makeResource('CRM', 'Sistema CRM'),
            $admin
        );

        /*
         * La consulta ordena por ID descendente.
         *
         * Página 1: tercer y segundo registro.
         * Página 2: primer registro.
         */
        $this->actingAs($employee, 'web');

        $this->getJson(
            $this->endpoint($employee) . '?per_page=2&page=1'
        )
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $third->id)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('pagination.total', 3)
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 2);

        $this->getJson(
            $this->endpoint($employee) . '?per_page=2&page=2'
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('pagination.current_page', 2);
    }

    /*
    |--------------------------------------------------------------------------
    | 13. NO EXPONER DATOS SENSIBLES O INNECESARIOS
    |--------------------------------------------------------------------------
    */

    public function test_inventory_exposes_only_expected_fields(): void
    {
        $admin = $this->makeUser('iam_admin');
        $employee = $this->makeUser();

        $resource = $this->makeResource();

        $this->makeGrant(
            $employee,
            $resource,
            $admin,
            'revoked'
        );

        $this->actingAs($employee, 'web')
            ->getJson($this->endpoint($employee))
            ->assertOk()
            ->assertJsonMissingPath('user.email')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath(
                'data.0.revocation_reason'
            )
            ->assertJsonMissingPath(
                'data.0.access_request.justification'
            )
            ->assertJsonMissingPath(
                'data.0.access_request.decision_reason'
            )
            ->assertJsonMissingPath(
                'data.0.granted_by.email'
            )
            ->assertJsonMissingPath(
                'data.0.revoked_by.email'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | 14. INVENTARIO SIN CONCESIONES
    |--------------------------------------------------------------------------
    */

    public function test_user_without_grants_gets_empty_inventory(): void
    {
        $employee = $this->makeUser();

        $this->actingAs($employee, 'web')
            ->getJson($this->endpoint($employee))
            ->assertOk()
            ->assertJsonPath('user.id', $employee->id)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('pagination.total', 0);
    }
}
