<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class MoverAccessGovernanceTest extends TestCase
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

    private function makeResource(
        string $code
    ): Resource {
        return Resource::create([
            'name' => "Recurso {$code}",
            'code' => $code,
            'description' =>
                'Recurso ficticio para pruebas Mover',
            'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CONFIGURAR ELEGIBILIDAD
    |--------------------------------------------------------------------------
    |
    | Se configura directamente para aislar
    | las reglas del proceso Mover.
    |
    */

    private function restrictResource(
        Resource $resource,
        array $departments,
        User $admin
    ): void {
        $resource->forceFill([
            'department_restricted' => true,
        ])->save();

        $departmentIds = array_map(
            fn (Department $department) => $department->id,
            $departments
        );

        $resource->eligibleDepartments()
            ->syncWithPivotValues(
                $departmentIds,
                [
                    'assigned_by_user_id' => $admin->id,
                ]
            );
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR CONCESIÓN FICTICIA
    |--------------------------------------------------------------------------
    |
    | Insertamos una solicitud aprobada
    | y su concesión correspondiente.
    |
    | active_resource_id es generado por MySQL;
    | no debemos asignarlo manualmente.
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
                    'Acceso autorizado para prueba Mover.',
                'status' => 'approved',
                'decided_by_user_id' => $admin->id,
                'decision_reason' =>
                    'Aprobación de prueba automatizada.',
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
                        ? 'Revocación histórica de prueba.'
                        : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function moveUser(
        User $admin,
        User $employee,
        Department $destination
    ) {
        return $this->actingAs($admin, 'web')
            ->patchJson(
                "/api/users/{$employee->id}/department",
                [
                    'department_id' => $destination->id,
                ]
            );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 1
    | REVOCAR ACCESO INCOMPATIBLE
    |--------------------------------------------------------------------------
    */

    public function test_mover_revokes_access_not_allowed_in_new_department(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $ventas = $this->makeDepartment(
            'Ventas',
            'VEN'
        );

        $admin = $this->makeUser(
            'iam_admin',
            $finanzas
        );

        $daniela = $this->makeUser(
            'employee',
            $finanzas
        );

        $contabilidad = $this->makeResource(
            'CONTABILIDAD-TEST'
        );

        $this->restrictResource(
            $contabilidad,
            [$finanzas],
            $admin
        );

        $grantId = $this->makeGrant(
            $daniela,
            $contabilidad,
            $admin
        );

        $auditBefore = DB::table('audit_logs')->count();

        $this->moveUser(
            $admin,
            $daniela,
            $ventas
        )
            ->assertOk()
            ->assertJsonPath(
                'data.department_id',
                $ventas->id
            )
            ->assertJsonPath(
                'data.revoked_access_grants_count',
                1
            );

        $this->assertDatabaseHas('users', [
            'id' => $daniela->id,
            'department_id' => $ventas->id,
        ]);

        $this->assertDatabaseHas('access_grants', [
            'id' => $grantId,
            'status' => 'revoked',
            'revoked_by_user_id' => $admin->id,
        ]);

        /*
         * Un evento de revocación
         * y un evento de movimiento.
         */
        $this->assertSame(
            $auditBefore + 2,
            DB::table('audit_logs')->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 2
    | CONSERVAR RECURSO SIN RESTRICCIONES
    |--------------------------------------------------------------------------
    */

    public function test_mover_preserves_unrestricted_access(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $ventas = $this->makeDepartment(
            'Ventas',
            'VEN'
        );

        $admin = $this->makeUser(
            'iam_admin',
            $finanzas
        );

        $employee = $this->makeUser(
            'employee',
            $finanzas
        );

        $vpn = $this->makeResource('VPN-TEST');

        $grantId = $this->makeGrant(
            $employee,
            $vpn,
            $admin
        );

        $auditBefore = DB::table('audit_logs')->count();

        $this->moveUser(
            $admin,
            $employee,
            $ventas
        )
            ->assertOk()
            ->assertJsonPath(
                'data.revoked_access_grants_count',
                0
            );

        $this->assertDatabaseHas('access_grants', [
            'id' => $grantId,
            'status' => 'active',
        ]);

        /*
         * Solamente se audita el movimiento.
         */
        $this->assertSame(
            $auditBefore + 1,
            DB::table('audit_logs')->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 3
    | CONSERVAR ACCESO PERMITIDO EN AMBOS
    |--------------------------------------------------------------------------
    */

    public function test_mover_preserves_access_eligible_in_both_departments(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $ventas = $this->makeDepartment(
            'Ventas',
            'VEN'
        );

        $admin = $this->makeUser(
            'iam_admin',
            $finanzas
        );

        $employee = $this->makeUser(
            'employee',
            $finanzas
        );

        $crm = $this->makeResource('CRM-TEST');

        $this->restrictResource(
            $crm,
            [$finanzas, $ventas],
            $admin
        );

        $grantId = $this->makeGrant(
            $employee,
            $crm,
            $admin
        );

        $this->moveUser(
            $admin,
            $employee,
            $ventas
        )
            ->assertOk()
            ->assertJsonPath(
                'data.revoked_access_grants_count',
                0
            );

        $this->assertDatabaseHas('access_grants', [
            'id' => $grantId,
            'status' => 'active',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 4
    | REVOCACIÓN SELECTIVA
    |--------------------------------------------------------------------------
    |
    | El usuario tiene tres accesos.
    |
    | Uno debe revocarse.
    | Dos deben conservarse.
    |
    */

    public function test_mover_only_revokes_incompatible_grants(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $ventas = $this->makeDepartment(
            'Ventas',
            'VEN'
        );

        $admin = $this->makeUser(
            'iam_admin',
            $finanzas
        );

        $employee = $this->makeUser(
            'employee',
            $finanzas
        );

        $accounting = $this->makeResource(
            'ACCOUNTING-TEST'
        );

        $crm = $this->makeResource(
            'CRM-TEST'
        );

        $vpn = $this->makeResource(
            'VPN-TEST'
        );

        /*
         * Contabilidad: solo Finanzas.
         */
        $this->restrictResource(
            $accounting,
            [$finanzas],
            $admin
        );

        /*
         * CRM: ambos departamentos.
         */
        $this->restrictResource(
            $crm,
            [$finanzas, $ventas],
            $admin
        );

        /*
         * VPN: sin restricciones.
         */
        $accountingGrant = $this->makeGrant(
            $employee,
            $accounting,
            $admin
        );

        $crmGrant = $this->makeGrant(
            $employee,
            $crm,
            $admin
        );

        $vpnGrant = $this->makeGrant(
            $employee,
            $vpn,
            $admin
        );

        $auditBefore = DB::table('audit_logs')->count();

        $this->moveUser(
            $admin,
            $employee,
            $ventas
        )
            ->assertOk()
            ->assertJsonPath(
                'data.revoked_access_grants_count',
                1
            );

        /*
         * Contabilidad: revocado.
         */
        $this->assertDatabaseHas('access_grants', [
            'id' => $accountingGrant,
            'status' => 'revoked',
        ]);

        /*
         * CRM: conservado.
         */
        $this->assertDatabaseHas('access_grants', [
            'id' => $crmGrant,
            'status' => 'active',
        ]);

        /*
         * VPN: conservado.
         */
        $this->assertDatabaseHas('access_grants', [
            'id' => $vpnGrant,
            'status' => 'active',
        ]);

        /*
         * Una revocación y un movimiento.
         */
        $this->assertSame(
            $auditBefore + 2,
            DB::table('audit_logs')->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 5
    | UNA CONCESIÓN REVOCADA NO SE MODIFICA
    |--------------------------------------------------------------------------
    */

    public function test_mover_preserves_previously_revoked_grant(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $ventas = $this->makeDepartment(
            'Ventas',
            'VEN'
        );

        $admin = $this->makeUser(
            'iam_admin',
            $finanzas
        );

        $employee = $this->makeUser(
            'employee',
            $finanzas
        );

        $resource = $this->makeResource(
            'LEGACY-TEST'
        );

        $this->restrictResource(
            $resource,
            [$finanzas],
            $admin
        );

        $grantId = $this->makeGrant(
            $employee,
            $resource,
            $admin,
            'revoked'
        );

        $this->moveUser(
            $admin,
            $employee,
            $ventas
        )
            ->assertOk()
            ->assertJsonPath(
                'data.revoked_access_grants_count',
                0
            );

        /*
         * No reactivamos ni sobrescribimos
         * concesiones históricamente revocadas.
         */
        $this->assertDatabaseHas('access_grants', [
            'id' => $grantId,
            'status' => 'revoked',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 6
    | ERROR DE AUDITORÍA REVIERTE EL MOVIMIENTO
    |--------------------------------------------------------------------------
    |
    | Simulamos un fallo en AuditService
    | justo cuando intenta registrar
    | una revocación.
    |
    | La transacción debe revertir:
    |
    | - La modificación del departamento.
    | - La revocación de la concesión.
    |
    */

    public function test_audit_failure_rolls_back_entire_move(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $ventas = $this->makeDepartment(
            'Ventas',
            'VEN'
        );

        $admin = $this->makeUser(
            'iam_admin',
            $finanzas
        );

        $employee = $this->makeUser(
            'employee',
            $finanzas
        );

        $resource = $this->makeResource(
            'ACCOUNTING-ROLLBACK-TEST'
        );

        $this->restrictResource(
            $resource,
            [$finanzas],
            $admin
        );

        $grantId = $this->makeGrant(
            $employee,
            $resource,
            $admin
        );

        /*
         * Simular error de auditoría.
         *
         * No se cambia el código real
         * de AuditService.
         */
        $this->mock(
            AuditService::class,
            function (MockInterface $mock) {
                $mock->shouldReceive('accessRevoked')
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Fallo simulado de auditoría.'
                        )
                    );
            }
        );

        /*
         * Permitir que la excepción de prueba
         * salga del manejador HTTP.
         */
        $this->withoutExceptionHandling();

        try {
            $this->moveUser(
                $admin,
                $employee,
                $ventas
            );

            $this->fail(
                'Se esperaba que fallara la auditoría.'
            );

        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Fallo simulado de auditoría.',
                $exception->getMessage()
            );
        }

        /*
         * El usuario debe permanecer
         * en su departamento original.
         */
        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'department_id' => $finanzas->id,
        ]);

        /*
         * El acceso debe permanecer activo.
         */
        $this->assertDatabaseHas('access_grants', [
            'id' => $grantId,
            'status' => 'active',
        ]);
    }
}
