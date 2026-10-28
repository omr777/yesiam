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

class ResourceDepartmentEligibilitySecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Preparar roles y permisos de YESIAM.
        $this->seed(RbacSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | DATOS DE PRUEBA
    |--------------------------------------------------------------------------
    |
    | Todos los registros son ficticios.
    | RefreshDatabase aísla cada escenario.
    |
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

    private function makeResource(
        bool $active = true
    ): Resource {
        return Resource::create([
            'name' => 'Sistema contable de prueba',
            'code' => 'CONTABILIDAD-TEST',
            'description' => 'Recurso ficticio de elegibilidad',
            'is_active' => $active,
        ]);
    }

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

    private function endpoint(Resource $resource): string
    {
        return "/api/resources/{$resource->id}/eligibility";
    }

    private function eligibilityRows(Resource $resource): int
    {
        return DB::table(
            'resource_department_eligibilities'
        )
            ->where('resource_id', $resource->id)
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 1: USUARIO NO AUTENTICADO
    |--------------------------------------------------------------------------
    */

    public function test_guest_cannot_consult_or_update_eligibility(): void
    {
        $resource = $this->makeResource();

        $this->getJson(
            $this->endpoint($resource)
        )->assertUnauthorized();

        $this->putJson(
            $this->endpoint($resource),
            [
                'department_restricted' => true,
                'department_ids' => [],
            ]
        )->assertUnauthorized();

        $this->assertFalse(
            (bool) $resource->fresh()->department_restricted
        );

        $this->assertSame(
            0,
            $this->eligibilityRows($resource)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 2: EMPLEADO SIN PERMISO ADMINISTRATIVO
    |--------------------------------------------------------------------------
    */

    public function test_employee_cannot_modify_eligibility(): void
    {
        $employee = $this->makeUser('employee');
        $resource = $this->makeResource();

        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $auditCount = DB::table('audit_logs')->count();

        $this->actingAs($employee, 'web')
            ->putJson(
                $this->endpoint($resource),
                [
                    'department_restricted' => true,
                    'department_ids' => [
                        $finanzas->id,
                    ],
                ]
            )
            ->assertForbidden();

        // El rechazo no debe modificar el recurso.
        $this->assertFalse(
            (bool) $resource->fresh()->department_restricted
        );

        // Tampoco debe crear relaciones.
        $this->assertSame(
            0,
            $this->eligibilityRows($resource)
        );

        // Ni registrar un cambio exitoso.
        $this->assertSame(
            $auditCount,
            DB::table('audit_logs')->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 3: ADMINISTRADOR IAM CONFIGURA ELEGIBILIDAD
    |--------------------------------------------------------------------------
    */

    public function test_iam_admin_can_configure_and_consult_eligibility(): void
    {
        $admin = $this->makeUser('iam_admin');
        $resource = $this->makeResource();

        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $auditCount = DB::table('audit_logs')->count();

        $this->actingAs($admin, 'web')
            ->putJson(
                $this->endpoint($resource),
                [
                    'department_restricted' => true,
                    'department_ids' => [
                        $finanzas->id,
                    ],
                ]
            )
            ->assertOk()
            ->assertJsonPath(
                'data.department_restricted',
                true
            )
            ->assertJsonPath(
                'data.changed',
                true
            );

        // Verificar persistencia real.
        $this->assertTrue(
            (bool) $resource->fresh()->department_restricted
        );

        $this->assertDatabaseHas(
            'resource_department_eligibilities',
            [
                'resource_id' => $resource->id,
                'department_id' => $finanzas->id,
                'assigned_by_user_id' => $admin->id,
            ]
        );

        // Una actualización válida debe auditarse.
        $this->assertSame(
            $auditCount + 1,
            DB::table('audit_logs')->count()
        );

        // Verificar la consulta HTTP.
        $this->getJson(
            $this->endpoint($resource)
        )
            ->assertOk()
            ->assertJsonPath(
                'data.department_restricted',
                true
            )
            ->assertJsonPath(
                'data.eligible_departments.0.id',
                $finanzas->id
            );

        // Comprobar el motor de elegibilidad.
        $this->assertTrue(
            $resource->fresh()->isEligibleForDepartment(
                $finanzas->id
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 4: DEPARTAMENTO INACTIVO
    |--------------------------------------------------------------------------
    */

    public function test_inactive_department_is_rejected(): void
    {
        $admin = $this->makeUser('iam_admin');
        $resource = $this->makeResource();

        $inactive = $this->makeDepartment(
            'Departamento cerrado',
            'CLOSED',
            false
        );

        $this->actingAs($admin, 'web')
            ->putJson(
                $this->endpoint($resource),
                [
                    'department_restricted' => true,
                    'department_ids' => [
                        $inactive->id,
                    ],
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'department_ids.0',
            ]);

        $this->assertSame(
            0,
            $this->eligibilityRows($resource)
        );

        $this->assertFalse(
            (bool) $resource->fresh()->department_restricted
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 5: DEPARTAMENTOS DUPLICADOS
    |--------------------------------------------------------------------------
    */

    public function test_duplicate_departments_are_rejected(): void
    {
        $admin = $this->makeUser('iam_admin');
        $resource = $this->makeResource();

        $ventas = $this->makeDepartment(
            'Ventas',
            'VEN'
        );

        $this->actingAs($admin, 'web')
            ->putJson(
                $this->endpoint($resource),
                [
                    'department_restricted' => true,
                    'department_ids' => [
                        $ventas->id,
                        $ventas->id,
                    ],
                ]
            )
            ->assertUnprocessable();

        $this->assertSame(
            0,
            $this->eligibilityRows($resource)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 6: CONFIGURACIÓN CONTRADICTORIA
    |--------------------------------------------------------------------------
    |
    | Si el recurso no tiene restricción,
    | no debe mantener una lista específica
    | de departamentos.
    |
    */

    public function test_unrestricted_resource_rejects_department_list(): void
    {
        $admin = $this->makeUser('iam_admin');
        $resource = $this->makeResource();

        $ventas = $this->makeDepartment(
            'Ventas',
            'VEN'
        );

        $this->actingAs($admin, 'web')
            ->putJson(
                $this->endpoint($resource),
                [
                    'department_restricted' => false,
                    'department_ids' => [
                        $ventas->id,
                    ],
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'department_ids',
            ]);

        $this->assertSame(
            0,
            $this->eligibilityRows($resource)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 7: FAIL SECURE
    |--------------------------------------------------------------------------
    |
    | Un recurso restringido sin departamentos
    | autorizados no permite elegibilidad.
    |
    */

    public function test_restricted_resource_with_empty_list_denies_eligibility(): void
    {
        $admin = $this->makeUser('iam_admin');
        $resource = $this->makeResource();

        $finanzas = $this->makeDepartment(
            'Finanzas',
            'FIN'
        );

        $this->actingAs($admin, 'web')
            ->putJson(
                $this->endpoint($resource),
                [
                    'department_restricted' => true,
                    'department_ids' => [],
                ]
            )
            ->assertOk();

        $resource->refresh();

        $this->assertTrue(
            $resource->department_restricted
        );

        $this->assertSame(
            0,
            $this->eligibilityRows($resource)
        );

        $this->assertFalse(
            $resource->isEligibleForDepartment(
                $finanzas->id
            )
        );

        $this->assertFalse(
            $resource->isEligibleForDepartment(null)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 8: IDEMPOTENCIA
    |--------------------------------------------------------------------------
    |
    | Repetir la misma configuración no debe
    | crear nuevas relaciones ni auditorías.
    |
    */

    public function test_repeating_same_configuration_is_idempotent(): void
    {
        $admin = $this->makeUser('iam_admin');
        $resource = $this->makeResource();

        $ventas = $this->makeDepartment(
            'Ventas',
            'VEN'
        );

        $payload = [
            'department_restricted' => true,
            'department_ids' => [
                $ventas->id,
            ],
        ];

        $this->actingAs($admin, 'web')
            ->putJson(
                $this->endpoint($resource),
                $payload
            )
            ->assertOk()
            ->assertJsonPath('data.changed', true);

        $auditCount = DB::table('audit_logs')->count();

        $this->putJson(
            $this->endpoint($resource),
            $payload
        )
            ->assertOk()
            ->assertJsonPath('data.changed', false);

        $this->assertSame(
            1,
            $this->eligibilityRows($resource)
        );

        $this->assertSame(
            $auditCount,
            DB::table('audit_logs')->count()
        );
    }
}
