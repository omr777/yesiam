<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ResourceController;
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

class ResourceDeactivationGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | PREPARACIÓN DE DATOS
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
                'Recurso utilizado en pruebas de seguridad.',
            'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR UNA CONCESIÓN DE PRUEBA
    |--------------------------------------------------------------------------
    |
    | Preparamos directamente la solicitud
    | aprobada y la concesión correspondiente.
    |
    | Esto permite aislar el comportamiento
    | de ResourceController::deactivate().
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
                    'Solicitud aprobada para pruebas.',
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

    /*
    |--------------------------------------------------------------------------
    | EJECUTAR DESACTIVACIÓN
    |--------------------------------------------------------------------------
    |
    | Recuperamos la ruta real asociada
    | con ResourceController::deactivate().
    |
    | Así no asumimos un verbo HTTP
    | ni una URL que podrían ser diferentes.
    |
    */

    private function deactivateResource(
        User $admin,
        Resource $resource
    ) {
        $action = ResourceController::class
            . '@deactivate';

        $route = Route::getRoutes()
            ->getByAction($action);

        $this->assertNotNull(
            $route,
            'No se encontró la ruta para desactivar recursos.'
        );

        $uri = str_replace(
            '{resource}',
            (string) $resource->id,
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
    | REVOCAR CONCESIONES ACTIVAS
    |--------------------------------------------------------------------------
    */

    public function test_deactivation_revokes_all_active_grants(): void
    {
        $department = $this->makeDepartment(
            'Tecnología',
            'TECH'
        );

        $admin = $this->makeUser(
            'iam_admin',
            $department
        );

        $carlos = $this->makeUser(
            'employee',
            $department
        );

        $daniela = $this->makeUser(
            'employee',
            $department
        );

        $resource = $this->makeResource(
            'ERP-TEST'
        );

        $firstGrant = $this->makeGrant(
            $carlos,
            $resource,
            $admin
        );

        $secondGrant = $this->makeGrant(
            $daniela,
            $resource,
            $admin
        );

        $auditBefore = DB::table('audit_logs')->count();

        $this->deactivateResource(
            $admin,
            $resource
        )
            ->assertOk()
            ->assertJsonPath(
                'revoked_access_grants_count',
                2
            )
            ->assertJsonPath(
                'data.is_active',
                false
            );

        $this->assertDatabaseHas('resources', [
            'id' => $resource->id,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('access_grants', [
            'id' => $firstGrant,
            'status' => 'revoked',
            'revoked_by_user_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('access_grants', [
            'id' => $secondGrant,
            'status' => 'revoked',
            'revoked_by_user_id' => $admin->id,
        ]);

        /*
         * Dos auditorías de revocación
         * y una de desactivación.
         */
        $this->assertSame(
            $auditBefore + 3,
            DB::table('audit_logs')->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 2
    | RESPETAR EL HISTORIAL Y OTROS RECURSOS
    |--------------------------------------------------------------------------
    */

    public function test_deactivation_preserves_historical_and_unrelated_grants(): void
    {
        $department = $this->makeDepartment(
            'Tecnología',
            'TECH'
        );

        $admin = $this->makeUser(
            'iam_admin',
            $department
        );

        $employee = $this->makeUser(
            'employee',
            $department
        );

        $erp = $this->makeResource(
            'ERP-TEST'
        );

        $vpn = $this->makeResource(
            'VPN-TEST'
        );

        /*
         * Acceso ya revocado del ERP.
         */
        $historicalGrant = $this->makeGrant(
            $employee,
            $erp,
            $admin,
            'revoked'
        );

        /*
         * Acceso activo de un recurso distinto.
         */
        $vpnGrant = $this->makeGrant(
            $employee,
            $vpn,
            $admin
        );

        $this->deactivateResource(
            $admin,
            $erp
        )
            ->assertOk()
            ->assertJsonPath(
                'revoked_access_grants_count',
                0
            );

        $this->assertDatabaseHas('access_grants', [
            'id' => $historicalGrant,
            'status' => 'revoked',
            'revocation_reason' =>
                'Revocación histórica de prueba.',
        ]);

        $this->assertDatabaseHas('access_grants', [
            'id' => $vpnGrant,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('resources', [
            'id' => $vpn->id,
            'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 3
    | RECURSO SIN CONCESIONES
    |--------------------------------------------------------------------------
    */

    public function test_resource_without_grants_can_be_deactivated(): void
    {
        $department = $this->makeDepartment(
            'Tecnología',
            'TECH'
        );

        $admin = $this->makeUser(
            'iam_admin',
            $department
        );

        $resource = $this->makeResource(
            'EMPTY-RESOURCE'
        );

        $auditBefore = DB::table('audit_logs')->count();

        $this->deactivateResource(
            $admin,
            $resource
        )
            ->assertOk()
            ->assertJsonPath(
                'revoked_access_grants_count',
                0
            );

        $this->assertDatabaseHas('resources', [
            'id' => $resource->id,
            'is_active' => false,
        ]);

        $this->assertSame(
            $auditBefore + 1,
            DB::table('audit_logs')->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 4
    | DESACTIVACIÓN REPETIDA
    |--------------------------------------------------------------------------
    |
    | Algunas políticas pueden devolver
    | 403 antes de que el controlador
    | alcance su comprobación de 409.
    |
    | En cualquiera de esos casos,
    | no debe producirse otra desactivación.
    |
    */

    public function test_repeated_deactivation_has_no_extra_effects(): void
    {
        $department = $this->makeDepartment(
            'Tecnología',
            'TECH'
        );

        $admin = $this->makeUser(
            'iam_admin',
            $department
        );

        $resource = $this->makeResource(
            'REPEAT-TEST'
        );

        $this->deactivateResource(
            $admin,
            $resource
        )->assertOk();

        $auditBefore = DB::table('audit_logs')->count();

        $response = $this->deactivateResource(
            $admin,
            $resource
        );

        $this->assertContains(
            $response->status(),
            [403, 409]
        );

        $this->assertDatabaseHas('resources', [
            'id' => $resource->id,
            'is_active' => false,
        ]);

        $this->assertSame(
            $auditBefore,
            DB::table('audit_logs')->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 5
    | FALLO DURANTE AUDITORÍA DE REVOCACIÓN
    |--------------------------------------------------------------------------
    |
    | Si falla la auditoría de una concesión,
    | el recurso debe seguir activo y
    | la concesión también.
    |
    */

    public function test_revocation_audit_failure_rolls_back_deactivation(): void
    {
        $department = $this->makeDepartment(
            'Tecnología',
            'TECH'
        );

        $admin = $this->makeUser(
            'iam_admin',
            $department
        );

        $employee = $this->makeUser(
            'employee',
            $department
        );

        $resource = $this->makeResource(
            'ROLLBACK-GRANT'
        );

        $grantId = $this->makeGrant(
            $employee,
            $resource,
            $admin
        );

        $auditBefore = DB::table('audit_logs')->count();

        /*
         * Simulamos un fallo de auditoría.
         * No modificamos AuditService real.
         */
        $this->mock(
            AuditService::class,
            function (MockInterface $mock) {
                $mock->shouldReceive('accessRevoked')
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Fallo simulado de auditoría de revocación.'
                        )
                    );
            }
        );

        $this->withoutExceptionHandling();

        try {
            $this->deactivateResource(
                $admin,
                $resource
            );

            $this->fail(
                'Se esperaba un error de auditoría.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Fallo simulado de auditoría de revocación.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('resources', [
            'id' => $resource->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('access_grants', [
            'id' => $grantId,
            'status' => 'active',
        ]);

        $this->assertSame(
            $auditBefore,
            DB::table('audit_logs')->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 6
    | FALLO EN AUDITORÍA DE DESACTIVACIÓN
    |--------------------------------------------------------------------------
    |
    | La operación puede fallar después
    | de haber cambiado el estado del recurso.
    |
    | Incluso entonces, la transacción
    | debe restaurar su estado anterior.
    |
    */

    public function test_resource_audit_failure_rolls_back_deactivation(): void
    {
        $department = $this->makeDepartment(
            'Tecnología',
            'TECH'
        );

        $admin = $this->makeUser(
            'iam_admin',
            $department
        );

        $resource = $this->makeResource(
            'ROLLBACK-RESOURCE'
        );

        $auditBefore = DB::table('audit_logs')->count();

        $this->mock(
            AuditService::class,
            function (MockInterface $mock) {
                $mock->shouldReceive('resourceDeactivated')
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Fallo simulado de auditoría del recurso.'
                        )
                    );
            }
        );

        $this->withoutExceptionHandling();

        try {
            $this->deactivateResource(
                $admin,
                $resource
            );

            $this->fail(
                'Se esperaba un error de auditoría.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Fallo simulado de auditoría del recurso.',
                $exception->getMessage()
            );
        }

        /*
         * La actualización del recurso
         * debe haberse revertido.
         */
        $this->assertDatabaseHas('resources', [
            'id' => $resource->id,
            'is_active' => true,
        ]);

        $this->assertSame(
            $auditBefore,
            DB::table('audit_logs')->count()
        );
    }
}
