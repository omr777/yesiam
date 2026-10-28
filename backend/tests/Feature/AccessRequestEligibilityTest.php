<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessRequestEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | PREPARACIÓN DE DATOS FICTICIOS
    |--------------------------------------------------------------------------
    */

    private function makeDepartment(
        string $name,
        string $code,
        bool $active = true
    ): Department {
        return Department::create([
            'name' => $name,
            'code' => $code,
            'is_active' => $active,
        ]);
    }

    private function makeUser(
        string $roleCode,
        ?Department $department = null
    ): User {
        $user = User::factory()->create([
            'status' => 'active',
            'department_id' => $department?->id,
        ]);

        $role = Role::query()
            ->where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeResource(): Resource
    {
        return Resource::create([
            'name' => 'Sistema contable',
            'code' => 'ACCOUNTING-TEST',
            'description' => 'Recurso ficticio de pruebas IAM',
            'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CONFIGURAR RESTRICCIÓN DE PRUEBA
    |--------------------------------------------------------------------------
    |
    | Creamos las relaciones directamente
    | para aislar la lógica de solicitudes.
    |
    | La API administrativa ya cuenta con
    | su propio conjunto de pruebas.
    |
    */

    private function restrictResource(
        Resource $resource,
        array $departments
    ): void {
        $admin = $this->makeUser('iam_admin');

        $resource->forceFill([
            'department_restricted' => true,
        ])->save();

        foreach ($departments as $department) {
            $resource->eligibleDepartments()->attach(
                $department->id,
                [
                    'assigned_by_user_id' => $admin->id,
                ]
            );
        }
    }

    private function requestAccess(
        User $employee,
        Resource $resource
    ) {
        return $this->actingAs($employee, 'web')
            ->postJson('/api/access-requests', [
                'resource_id' => $resource->id,
                'justification' =>
                    'Necesito este recurso para mis funciones.',
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 1
    | USUARIO DE DEPARTAMENTO NO AUTORIZADO
    |--------------------------------------------------------------------------
    */

    public function test_employee_from_other_department_cannot_request_resource(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $ventas = $this->makeDepartment(
            'Ventas',
            'VEN'
        );

        $resource = $this->makeResource();

        $this->restrictResource(
            $resource,
            [$finanzas]
        );

        $carlos = $this->makeUser(
            'employee',
            $ventas
        );

        $this->requestAccess($carlos, $resource)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'resource_id',
            ]);

        $this->assertDatabaseCount(
            'access_requests',
            0
        );

        $this->assertDatabaseCount(
            'audit_logs',
            0
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 2
    | USUARIO DE DEPARTAMENTO AUTORIZADO
    |--------------------------------------------------------------------------
    */

    public function test_eligible_employee_can_request_resource(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $resource = $this->makeResource();

        $this->restrictResource(
            $resource,
            [$finanzas]
        );

        $daniela = $this->makeUser(
            'employee',
            $finanzas
        );

        $this->requestAccess($daniela, $resource)
            ->assertCreated()
            ->assertJsonPath(
                'data.status',
                'pending'
            );

        $this->assertDatabaseHas(
            'access_requests',
            [
                'requester_user_id' => $daniela->id,
                'resource_id' => $resource->id,
                'status' => 'pending',
            ]
        );

        $this->assertDatabaseCount(
            'audit_logs',
            1
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 3
    | RECURSO SIN RESTRICCIÓN DEPARTAMENTAL
    |--------------------------------------------------------------------------
    */

    public function test_unrestricted_resource_preserves_previous_behavior(): void
    {
        $ventas = $this->makeDepartment(
            'Ventas',
            'VEN'
        );

        $resource = $this->makeResource();

        $carlos = $this->makeUser(
            'employee',
            $ventas
        );

        $this->assertFalse(
            (bool) $resource->department_restricted
        );

        $this->requestAccess($carlos, $resource)
            ->assertCreated();

        $this->assertDatabaseCount(
            'access_requests',
            1
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 4
    | USUARIO SIN DEPARTAMENTO
    |--------------------------------------------------------------------------
    */

    public function test_employee_without_department_cannot_request_restricted_resource(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $resource = $this->makeResource();

        $this->restrictResource(
            $resource,
            [$finanzas]
        );

        $employee = $this->makeUser(
            'employee'
        );

        $this->requestAccess($employee, $resource)
            ->assertUnprocessable();

        $this->assertDatabaseCount(
            'access_requests',
            0
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 5
    | RECURSO RESTRINGIDO SIN DEPARTAMENTOS
    |--------------------------------------------------------------------------
    |
    | Una lista vacía significa que nadie
    | es elegible: FAIL SECURE.
    |
    */

    public function test_empty_restricted_list_denies_request(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $resource = $this->makeResource();

        $this->restrictResource(
            $resource,
            []
        );

        $employee = $this->makeUser(
            'employee',
            $finanzas
        );

        $this->requestAccess($employee, $resource)
            ->assertUnprocessable();

        $this->assertDatabaseCount(
            'access_requests',
            0
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 6
    | DEPARTAMENTO DESACTIVADO
    |--------------------------------------------------------------------------
    */

    public function test_inactive_department_is_not_eligible(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $resource = $this->makeResource();

        $this->restrictResource(
            $resource,
            [$finanzas]
        );

        $employee = $this->makeUser(
            'employee',
            $finanzas
        );

        /*
         * Simulamos que el departamento
         * fue desactivado posteriormente.
         */
        $finanzas->update([
            'is_active' => false,
        ]);

        $this->requestAccess($employee, $resource)
            ->assertUnprocessable();

        $this->assertDatabaseCount(
            'access_requests',
            0
        );
    }
}
