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

class ResourceDepartmentEligibilityConflictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | USUARIOS Y ROLES DE PRUEBA
    |--------------------------------------------------------------------------
    */

    private function makeUser(
        string $roleCode,
        Department $department
    ): User {
        $user = User::factory()->create([
            'status' => 'active',
            'department_id' => $department->id,
        ]);

        $role = Role::query()
            ->where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | DEPARTAMENTOS
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

    /*
    |--------------------------------------------------------------------------
    | RECURSOS
    |--------------------------------------------------------------------------
    */

    private function makeResource(
        string $code = 'CRM-TEST'
    ): Resource {
        return Resource::create([
            'name' => "Recurso {$code}",
            'code' => $code,
            'description' => 'Recurso ficticio de pruebas IAM',
            'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR CONCESIÓN DE PRUEBA
    |--------------------------------------------------------------------------
    |
    | Primero se crea una solicitud aprobada.
    |
    | Después se inserta su concesión con
    | todos los campos necesarios.
    |
    | No insertamos active_resource_id:
    | MySQL calcula esa columna generada.
    |
    | Estados:
    | - active
    | - revoked
    |
    */

    private function makeGrant(
        User $employee,
        Resource $resource,
        User $admin,
        string $status = 'active'
    ): int {
        $now = now();

        $requestId = DB::table('access_requests')
            ->insertGetId([
                'requester_user_id' => $employee->id,
                'resource_id' => $resource->id,
                'justification' =>
                    'Acceso aprobado para probar elegibilidad.',
                'status' => 'approved',
                'decided_by_user_id' => $admin->id,
                'decision_reason' =>
                    'Aprobado para prueba automatizada.',
                'decided_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $revoked = $status === 'revoked';

        return DB::table('access_grants')
            ->insertGetId([
                'access_request_id' => $requestId,
                'user_id' => $employee->id,
                'resource_id' => $resource->id,
                'granted_by_user_id' => $admin->id,
                'status' => $status,
                'granted_at' => $now,
                'revoked_by_user_id' =>
                    $revoked ? $admin->id : null,
                'revoked_at' =>
                    $revoked ? $now : null,
                'revocation_reason' =>
                    $revoked
                        ? 'Revocación de prueba automatizada.'
                        : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function endpoint(Resource $resource): string
    {
        return "/api/resources/{$resource->id}/eligibility";
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 1
    | CONCESIÓN ACTIVA INCOMPATIBLE -> HTTP 409
    |--------------------------------------------------------------------------
    */

    public function test_active_incompatible_grant_blocks_new_restriction(): void
    {
        $ventas = $this->makeDepartment('Ventas', 'VEN');
        $finanzas = $this->makeDepartment('Finanzas', 'FIN');

        $admin = $this->makeUser('iam_admin', $ventas);
        $daniela = $this->makeUser('employee', $finanzas);

        $crm = $this->makeResource();

        $grantId = $this->makeGrant(
            $daniela,
            $crm,
            $admin
        );

        $auditCount = DB::table('audit_logs')->count();

        /*
         * El CRM pasaría a permitir solamente
         * empleados del departamento Ventas.
         *
         * Daniela pertenece a Finanzas y
         * todavía tiene un acceso activo.
         */
        $this->actingAs($admin, 'web')
            ->putJson(
                $this->endpoint($crm),
                [
                    'department_restricted' => true,
                    'department_ids' => [
                        $ventas->id,
                    ],
                ]
            )
            ->assertStatus(409);

        /*
         * La política anterior debe conservarse.
         */
        $this->assertFalse(
            (bool) $crm->fresh()->department_restricted
        );

        $this->assertDatabaseMissing(
            'resource_department_eligibilities',
            [
                'resource_id' => $crm->id,
                'department_id' => $ventas->id,
            ]
        );

        /*
         * La concesión no debe desaparecer.
         */
        $this->assertDatabaseHas(
            'access_grants',
            [
                'id' => $grantId,
                'status' => 'active',
            ]
        );

        /*
         * Un cambio rechazado no debe generar
         * un evento de actualización exitosa.
         */
        $this->assertSame(
            $auditCount,
            DB::table('audit_logs')->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 2
    | CONCESIÓN COMPATIBLE -> HTTP 200
    |--------------------------------------------------------------------------
    */

    public function test_active_compatible_grant_allows_restriction(): void
    {
        $ventas = $this->makeDepartment('Ventas', 'VEN');

        $admin = $this->makeUser('iam_admin', $ventas);
        $employee = $this->makeUser('employee', $ventas);

        $crm = $this->makeResource();

        $grantId = $this->makeGrant(
            $employee,
            $crm,
            $admin
        );

        /*
         * El usuario pertenece al mismo
         * departamento que será autorizado.
         */
        $this->actingAs($admin, 'web')
            ->putJson(
                $this->endpoint($crm),
                [
                    'department_restricted' => true,
                    'department_ids' => [
                        $ventas->id,
                    ],
                ]
            )
            ->assertOk()
            ->assertJsonPath(
                'data.changed',
                true
            );

        $this->assertTrue(
            (bool) $crm->fresh()->department_restricted
        );

        $this->assertDatabaseHas(
            'resource_department_eligibilities',
            [
                'resource_id' => $crm->id,
                'department_id' => $ventas->id,
            ]
        );

        $this->assertDatabaseHas(
            'access_grants',
            [
                'id' => $grantId,
                'status' => 'active',
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 3
    | CONCESIÓN REVOCADA -> NO BLOQUEA
    |--------------------------------------------------------------------------
    */

    public function test_revoked_incompatible_grant_does_not_block_restriction(): void
    {
        $ventas = $this->makeDepartment('Ventas', 'VEN');
        $finanzas = $this->makeDepartment('Finanzas', 'FIN');

        $admin = $this->makeUser('iam_admin', $ventas);
        $employee = $this->makeUser('employee', $finanzas);

        $crm = $this->makeResource();

        $grantId = $this->makeGrant(
            $employee,
            $crm,
            $admin,
            'revoked'
        );

        /*
         * El historial de una concesión revocada
         * no debe impedir cambios futuros.
         */
        $this->actingAs($admin, 'web')
            ->putJson(
                $this->endpoint($crm),
                [
                    'department_restricted' => true,
                    'department_ids' => [
                        $ventas->id,
                    ],
                ]
            )
            ->assertOk();

        $this->assertTrue(
            (bool) $crm->fresh()->department_restricted
        );

        /*
         * La concesión revocada se conserva
         * como evidencia histórica.
         */
        $this->assertDatabaseHas(
            'access_grants',
            [
                'id' => $grantId,
                'status' => 'revoked',
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 4
    | LISTA VACÍA CON ACCESO ACTIVO -> HTTP 409
    |--------------------------------------------------------------------------
    */

    public function test_empty_restricted_list_conflicts_with_active_grant(): void
    {
        $ventas = $this->makeDepartment('Ventas', 'VEN');

        $admin = $this->makeUser('iam_admin', $ventas);
        $employee = $this->makeUser('employee', $ventas);

        $crm = $this->makeResource();

        $this->makeGrant(
            $employee,
            $crm,
            $admin
        );

        /*
         * Una lista vacía significa que nadie
         * es elegible.
         *
         * Por eso cualquier concesión activa
         * es incompatible.
         */
        $this->actingAs($admin, 'web')
            ->putJson(
                $this->endpoint($crm),
                [
                    'department_restricted' => true,
                    'department_ids' => [],
                ]
            )
            ->assertStatus(409);

        $this->assertFalse(
            (bool) $crm->fresh()->department_restricted
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 5
    | CONCESIÓN DE OTRO RECURSO -> NO BLOQUEA
    |--------------------------------------------------------------------------
    */

    public function test_grant_for_another_resource_does_not_block_restriction(): void
    {
        $ventas = $this->makeDepartment('Ventas', 'VEN');
        $finanzas = $this->makeDepartment('Finanzas', 'FIN');

        $admin = $this->makeUser('iam_admin', $ventas);
        $employee = $this->makeUser('employee', $finanzas);

        $crm = $this->makeResource('CRM-TEST');
        $vpn = $this->makeResource('VPN-TEST');

        /*
         * El empleado tiene VPN, no CRM.
         */
        $this->makeGrant(
            $employee,
            $vpn,
            $admin
        );

        /*
         * Restringir el CRM no debe verse
         * bloqueado por accesos a otros recursos.
         */
        $this->actingAs($admin, 'web')
            ->putJson(
                $this->endpoint($crm),
                [
                    'department_restricted' => true,
                    'department_ids' => [
                        $ventas->id,
                    ],
                ]
            )
            ->assertOk();

        $this->assertTrue(
            (bool) $crm->fresh()->department_restricted
        );

        /*
         * La concesión de VPN sigue activa.
         */
        $this->assertDatabaseHas(
            'access_grants',
            [
                'resource_id' => $vpn->id,
                'user_id' => $employee->id,
                'status' => 'active',
            ]
        );
    }
}
