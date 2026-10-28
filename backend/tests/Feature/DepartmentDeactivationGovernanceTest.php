<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DepartmentController;
use App\Models\Department;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class DepartmentDeactivationGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | PREPARAR DATOS DE PRUEBA
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
            'description' => 'Recurso para pruebas IAM.',
            'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR RESTRICCIÓN DEPARTAMENTAL
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
    | CREAR UNA CONCESIÓN EXISTENTE
    |--------------------------------------------------------------------------
    |
    | La solicitud y concesión se insertan
    | directamente para aislar el proceso
    | de desactivación departamental.
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
                    'Solicitud autorizada para pruebas.',
                'status' => 'approved',
                'decided_by_user_id' => $admin->id,
                'decision_reason' =>
                    'Aprobación de prueba.',
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
                        ? 'Revocación histórica.'
                        : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | EJECUTAR DESACTIVACIÓN
    |--------------------------------------------------------------------------
    |
    | Recuperamos la ruta registrada
    | para el método deactivate().
    |
    | Así respetamos el verbo HTTP real
    | definido en routes/api.php.
    |
    */

    private function deactivateDepartment(
        User $admin,
        Department $department
    ) {
        $action = DepartmentController::class
            . '@deactivate';

        $route = Route::getRoutes()
            ->getByAction($action);

        $this->assertNotNull(
            $route,
            'No se encontró la ruta de desactivación departamental.'
        );

        $uri = str_replace(
            '{department}',
            (string) $department->id,
            $route->uri()
        );

        $method = $route->methods()[0];

        return $this->actingAs($admin, 'web')
            ->json(
                $method,
                '/' . ltrim($uri, '/')
            );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 1
    | REVOCAR ACCESO DE RECURSO RESTRINGIDO
    |--------------------------------------------------------------------------
    */

    public function test_deactivation_revokes_restricted_grant(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
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
            'ACCOUNTING-01'
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

        $auditBefore = DB::table('audit_logs')->count();

        $this->deactivateDepartment(
            $admin,
            $finanzas
        )
            ->assertOk()
            ->assertJsonPath(
                'revoked_access_grants_count',
                1
            );

        $this->assertDatabaseHas('departments', [
            'id' => $finanzas->id,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('access_grants', [
            'id' => $grantId,
            'status' => 'revoked',
            'revoked_by_user_id' => $admin->id,
        ]);

        /*
         * Una auditoría de revocación
         * y otra de desactivación.
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

    public function test_deactivation_preserves_unrestricted_grant(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $admin = $this->makeUser(
            'iam_admin',
            $finanzas
        );

        $employee = $this->makeUser(
            'employee',
            $finanzas
        );

        $vpn = $this->makeResource('VPN-01');

        $grantId = $this->makeGrant(
            $employee,
            $vpn,
            $admin
        );

        $this->deactivateDepartment(
            $admin,
            $finanzas
        )
            ->assertOk()
            ->assertJsonPath(
                'revoked_access_grants_count',
                0
            );

        $this->assertDatabaseHas('access_grants', [
            'id' => $grantId,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('departments', [
            'id' => $finanzas->id,
            'is_active' => false,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 3
    | NO AFECTAR OTROS DEPARTAMENTOS
    |--------------------------------------------------------------------------
    */

    public function test_deactivation_does_not_revoke_other_department_grants(): void
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

        $carlos = $this->makeUser(
            'employee',
            $finanzas
        );

        $daniela = $this->makeUser(
            'employee',
            $ventas
        );

        $finanzasResource = $this->makeResource(
            'FIN-SYSTEM'
        );

        $ventasResource = $this->makeResource(
            'VEN-SYSTEM'
        );

        $this->restrictResource(
            $finanzasResource,
            [$finanzas],
            $admin
        );

        $this->restrictResource(
            $ventasResource,
            [$ventas],
            $admin
        );

        $finanzasGrant = $this->makeGrant(
            $carlos,
            $finanzasResource,
            $admin
        );

        $ventasGrant = $this->makeGrant(
            $daniela,
            $ventasResource,
            $admin
        );

        $this->deactivateDepartment(
            $admin,
            $finanzas
        )
            ->assertOk()
            ->assertJsonPath(
                'revoked_access_grants_count',
                1
            );

        $this->assertDatabaseHas('access_grants', [
            'id' => $finanzasGrant,
            'status' => 'revoked',
        ]);

        $this->assertDatabaseHas('access_grants', [
            'id' => $ventasGrant,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('departments', [
            'id' => $ventas->id,
            'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 4
    | REVOCAR MÚLTIPLES ACCESOS RESTRINGIDOS
    |--------------------------------------------------------------------------
    */

    public function test_deactivation_revokes_multiple_incompatible_grants(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
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
            'ACCOUNTING-MULTI'
        );

        $payroll = $this->makeResource(
            'PAYROLL-MULTI'
        );

        $vpn = $this->makeResource(
            'VPN-MULTI'
        );

        $this->restrictResource(
            $accounting,
            [$finanzas],
            $admin
        );

        $this->restrictResource(
            $payroll,
            [$finanzas],
            $admin
        );

        $accountingGrant = $this->makeGrant(
            $employee,
            $accounting,
            $admin
        );

        $payrollGrant = $this->makeGrant(
            $employee,
            $payroll,
            $admin
        );

        $vpnGrant = $this->makeGrant(
            $employee,
            $vpn,
            $admin
        );

        $auditBefore = DB::table('audit_logs')->count();

        $this->deactivateDepartment(
            $admin,
            $finanzas
        )
            ->assertOk()
            ->assertJsonPath(
                'revoked_access_grants_count',
                2
            );

        $this->assertDatabaseHas('access_grants', [
            'id' => $accountingGrant,
            'status' => 'revoked',
        ]);

        $this->assertDatabaseHas('access_grants', [
            'id' => $payrollGrant,
            'status' => 'revoked',
        ]);

        $this->assertDatabaseHas('access_grants', [
            'id' => $vpnGrant,
            'status' => 'active',
        ]);

        /*
         * Dos revocaciones
         * más una desactivación.
         */
        $this->assertSame(
            $auditBefore + 3,
            DB::table('audit_logs')->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 5
    | DESACTIVACIÓN REPETIDA
    |--------------------------------------------------------------------------
    */

    public function test_deactivation_is_not_repeated(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $admin = $this->makeUser(
            'iam_admin',
            $finanzas
        );

        $this->deactivateDepartment(
            $admin,
            $finanzas
        )->assertOk();

        $auditBefore = DB::table('audit_logs')->count();

        /*
         * Repetir la operación debe producir
         * conflicto y no nueva auditoría.
         */
        $this->deactivateDepartment(
            $admin,
            $finanzas
        )->assertStatus(409);

        $this->assertSame(
            $auditBefore,
            DB::table('audit_logs')->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 6
    | ERROR DE AUDITORÍA REVIERTE TODO
    |--------------------------------------------------------------------------
    */

    public function test_audit_failure_rolls_back_department_deactivation(): void
    {
        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
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
            'ACCOUNTING-ROLLBACK'
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

        $auditBefore = DB::table('audit_logs')->count();

        /*
         * Simulamos un fallo al registrar
         * una revocación.
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

        $this->withoutExceptionHandling();

        try {
            $this->deactivateDepartment(
                $admin,
                $finanzas
            );

            $this->fail(
                'Se esperaba un fallo de auditoría.'
            );

        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Fallo simulado de auditoría.',
                $exception->getMessage()
            );
        }

        /*
         * El departamento debe continuar activo.
         */
        $this->assertDatabaseHas('departments', [
            'id' => $finanzas->id,
            'is_active' => true,
        ]);

        /*
         * La concesión también debe continuar activa.
         */
        $this->assertDatabaseHas('access_grants', [
            'id' => $grantId,
            'status' => 'active',
        ]);

        /*
         * Sin eventos adicionales.
         */
        $this->assertSame(
            $auditBefore,
            DB::table('audit_logs')->count()
        );
    }
}
