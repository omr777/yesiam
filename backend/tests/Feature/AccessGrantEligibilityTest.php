<?php

namespace Tests\Feature;

use App\Models\AccessRequest;
use App\Models\Department;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessGrantEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | DATOS DE PRUEBA
    |--------------------------------------------------------------------------
    */

    private function makeDepartment(
        string $name,
        string $code
    ): Department {
        return Department::create([
            'name' => $name,
            'code' => $code,
            'is_active' => true,
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

    private function makeResource(
        string $code = 'CONTABILIDAD-TEST'
    ): Resource {
        return Resource::create([
            'name' => 'Sistema contable',
            'code' => $code,
            'description' => 'Recurso para pruebas IAM',
            'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CONFIGURACIÓN DE ELEGIBILIDAD
    |--------------------------------------------------------------------------
    */

    private function restrictResource(
        Resource $resource,
        array $departments,
        User $admin
    ): void {
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

    /*
    |--------------------------------------------------------------------------
    | SOLICITUD APROBADA
    |--------------------------------------------------------------------------
    |
    | Preparamos directamente una solicitud
    | aprobada para aislar el proceso de grant.
    |
    */

    private function makeApprovedRequest(
        User $employee,
        Resource $resource,
        User $admin
    ): AccessRequest {
        $accessRequest = AccessRequest::create([
            'requester_user_id' => $employee->id,
            'resource_id' => $resource->id,
            'justification' =>
                'Necesito acceso para realizar mis funciones.',
        ]);

        $accessRequest->forceFill([
            'status' => 'approved',
            'decided_by_user_id' => $admin->id,
            'decision_reason' => 'Solicitud autorizada.',
            'decided_at' => now(),
        ])->save();

        return $accessRequest->fresh();
    }

    private function grantAccess(
        User $admin,
        AccessRequest $accessRequest
    ) {
        return $this->actingAs($admin, 'web')
            ->postJson(
                "/api/access-requests/{$accessRequest->id}/grant"
            );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 1
    | EL EMPLEADO CAMBIÓ DE DEPARTAMENTO
    |--------------------------------------------------------------------------
    */

    public function test_department_change_blocks_previously_approved_grant(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $ventas = $this->makeDepartment(
            'Ventas',
            'VEN'
        );

        $admin = $this->makeUser('iam_admin');

        $daniela = $this->makeUser(
            'employee',
            $finanzas
        );

        $resource = $this->makeResource();

        $this->restrictResource(
            $resource,
            [$finanzas],
            $admin
        );

        /*
         * La solicitud se aprueba mientras
         * Daniela pertenece a Finanzas.
         */
        $approvedRequest = $this->makeApprovedRequest(
            $daniela,
            $resource,
            $admin
        );

        /*
         * Simular un cambio de departamento.
         *
         * Esta modificación directa a la base
         * de pruebas nos permite aislar el
         * control de elegibilidad en grant.
         */
        $daniela->forceFill([
            'department_id' => $ventas->id,
        ])->save();

        $auditCount = DB::table('audit_logs')->count();

        /*
         * La concesión debe rechazarse porque
         * Daniela ya no es elegible.
         */
        $this->grantAccess($admin, $approvedRequest)
            ->assertUnprocessable();

        /*
         * No debe existir una concesión.
         */
        $this->assertDatabaseCount(
            'access_grants',
            0
        );

        /*
         * La solicitud aprobada se conserva.
         */
        $this->assertDatabaseHas(
            'access_requests',
            [
                'id' => $approvedRequest->id,
                'status' => 'approved',
            ]
        );

        /*
         * No debe registrarse una concesión
         * exitosa que nunca ocurrió.
         */
        $this->assertSame(
            $auditCount,
            DB::table('audit_logs')->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 2
    | EMPLEADO TODAVÍA ELEGIBLE
    |--------------------------------------------------------------------------
    */

    public function test_eligible_employee_can_receive_approved_grant(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $admin = $this->makeUser('iam_admin');

        $daniela = $this->makeUser(
            'employee',
            $finanzas
        );

        $resource = $this->makeResource();

        $this->restrictResource(
            $resource,
            [$finanzas],
            $admin
        );

        $approvedRequest = $this->makeApprovedRequest(
            $daniela,
            $resource,
            $admin
        );

        $this->grantAccess($admin, $approvedRequest)
            ->assertCreated();

        $this->assertDatabaseHas(
            'access_grants',
            [
                'access_request_id' => $approvedRequest->id,
                'user_id' => $daniela->id,
                'resource_id' => $resource->id,
                'status' => 'active',
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 3
    | RECURSO SIN RESTRICCIÓN
    |--------------------------------------------------------------------------
    */

    public function test_unrestricted_resource_allows_approved_grant(): void
    {
        $ventas = $this->makeDepartment(
            'Ventas',
            'VEN'
        );

        $admin = $this->makeUser('iam_admin');

        $carlos = $this->makeUser(
            'employee',
            $ventas
        );

        $resource = $this->makeResource();

        $this->assertFalse(
            (bool) $resource->department_restricted
        );

        $approvedRequest = $this->makeApprovedRequest(
            $carlos,
            $resource,
            $admin
        );

        $this->grantAccess($admin, $approvedRequest)
            ->assertCreated();

        $this->assertDatabaseHas(
            'access_grants',
            [
                'access_request_id' => $approvedRequest->id,
                'status' => 'active',
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 4
    | RECURSO RESTRINGIDO SIN DEPARTAMENTOS
    |--------------------------------------------------------------------------
    */

    public function test_empty_restricted_list_blocks_grant(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $admin = $this->makeUser('iam_admin');

        $employee = $this->makeUser(
            'employee',
            $finanzas
        );

        $resource = $this->makeResource();

        $this->restrictResource(
            $resource,
            [],
            $admin
        );

        $approvedRequest = $this->makeApprovedRequest(
            $employee,
            $resource,
            $admin
        );

        /*
         * Recurso restringido + lista vacía:
         * nadie debe recibir una concesión.
         */
        $this->grantAccess($admin, $approvedRequest)
            ->assertUnprocessable();

        $this->assertDatabaseCount(
            'access_grants',
            0
        );
    }
}
