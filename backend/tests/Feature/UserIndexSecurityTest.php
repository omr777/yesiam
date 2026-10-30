<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| YESIAM - USER INDEX SECURITY TESTS
|--------------------------------------------------------------------------
|
| FASE 19.4.5
|
| Pruebas de GET /api/users.
|
| Verificamos:
|
| - Autenticación.
| - Autorización RBAC.
| - Estado activo de la cuenta.
| - Paginación.
| - Búsqueda.
| - Filtros.
| - Validación de parámetros.
| - Protección de datos sensibles.
|
| IMPORTANTE:
|
| RefreshDatabase puede ejecutar migraciones.
| Por seguridad, solamente permitimos
| la base de datos MySQL yesiam_test.
|
*/

class UserIndexSecurityTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | PROTECCIÓN DE LA BASE DE DATOS
    |--------------------------------------------------------------------------
    |
    | Este método se ejecuta antes de que
    | RefreshDatabase prepare el esquema.
    |
    | Nunca ejecutar migraciones destructivas
    | sobre la base de desarrollo o producción.
    |
    */

    protected function beforeRefreshingDatabase(): void
    {
        if (
            config('database.default') !== 'mysql'
            || DB::connection()->getDatabaseName() !== 'yesiam_test'
        ) {
            throw new RuntimeException(
                'Prueba detenida: se requiere MySQL con la base yesiam_test.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PREPARACIÓN
    |--------------------------------------------------------------------------
    */

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR IDENTIDAD DE PRUEBA
    |--------------------------------------------------------------------------
    |
    | Reutilizamos los roles definidos
    | por el RBAC real de YESIAM.
    |
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

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 1 - USUARIO NO AUTENTICADO
    |--------------------------------------------------------------------------
    */

    public function test_guest_cannot_list_identities(): void
    {
        $this->getJson('/api/users')
            ->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 2 - EMPLEADO SIN PERMISOS
    |--------------------------------------------------------------------------
    */

    public function test_employee_cannot_list_identities(): void
    {
        $employee = $this->makeUser('employee');

        $this->actingAs($employee, 'web')
            ->getJson('/api/users')
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 3 - ADMINISTRADOR AUTORIZADO
    |--------------------------------------------------------------------------
    |
    | El rol iam_admin dispone del permiso
    | users.manage mediante RbacSeeder.
    |
    */

    public function test_admin_can_list_identities(): void
    {
        $admin = $this->makeUser('iam_admin');

        $employee = $this->makeUser('employee');

        $this->actingAs($admin, 'web')
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'email',
                        'status',
                        'department_id',
                    ],
                ],
                'current_page',
                'per_page',
                'total',
                'last_page',
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 2);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 4 - ADMINISTRADOR SUSPENDIDO
    |--------------------------------------------------------------------------
    |
    | Tener users.manage no debe permitir
    | acceder con una cuenta suspendida.
    |
    */

    public function test_suspended_admin_cannot_list_identities(): void
    {
        $admin = $this->makeUser(
            'iam_admin',
            'suspended'
        );

        $this->actingAs($admin, 'web')
            ->getJson('/api/users')
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 5 - PAGINACIÓN
    |--------------------------------------------------------------------------
    |
    | Creamos 23 identidades:
    |
    | - 1 administrador.
    | - 22 identidades adicionales.
    |
    | Solicitamos la segunda página
    | con 10 registros por página.
    |
    */

    public function test_admin_can_paginate_identities(): void
    {
        $admin = $this->makeUser('iam_admin');

        User::factory()
            ->count(22)
            ->create([
                'status' => 'active',
            ]);

        $this->actingAs($admin, 'web')
            ->getJson('/api/users?per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('per_page', 10)
            ->assertJsonPath('total', 23)
            ->assertJsonPath('last_page', 3);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 6 - FILTRAR POR ESTADO
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_filter_identities_by_status(): void
    {
        $admin = $this->makeUser('iam_admin');

        $suspended = User::factory()->create([
            'name' => 'Identidad suspendida',
            'status' => 'suspended',
        ]);

        User::factory()->create([
            'name' => 'Identidad desactivada',
            'status' => 'disabled',
        ]);

        $this->actingAs($admin, 'web')
            ->getJson('/api/users?status=suspended')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $suspended->id)
            ->assertJsonPath('data.0.status', 'suspended');
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 7 - BÚSQUEDA POR NOMBRE
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_search_identities_by_name(): void
    {
        $admin = $this->makeUser('iam_admin');

        $target = User::factory()->create([
            'name' => 'Ana Solis',
            'email' => 'ana.solis@example.test',
            'status' => 'active',
        ]);

        User::factory()->create([
            'name' => 'Carlos Rivera',
            'email' => 'carlos.rivera@example.test',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'web')
            ->getJson('/api/users?search=Ana%20Solis')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $target->id);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 8 - BÚSQUEDA POR CORREO
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_search_identities_by_email(): void
    {
        $admin = $this->makeUser('iam_admin');

        $target = User::factory()->create([
            'name' => 'Usuario de soporte',
            'email' => 'soporte.yesiam@example.test',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'web')
            ->getJson('/api/users?search=soporte.yesiam')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 9 - FILTROS COMBINADOS
    |--------------------------------------------------------------------------
    |
    | La búsqueda y el estado deben
    | aplicarse conjuntamente.
    |
    */

    public function test_admin_can_combine_search_and_status_filters(): void
    {
        $admin = $this->makeUser('iam_admin');

        $target = User::factory()->create([
            'name' => 'Analista de seguridad',
            'email' => 'analista.activo@example.test',
            'status' => 'active',
        ]);

        User::factory()->create([
            'name' => 'Analista suspendido',
            'email' => 'analista.suspendido@example.test',
            'status' => 'suspended',
        ]);

        $this->actingAs($admin, 'web')
            ->getJson(
                '/api/users?search=Analista&status=active'
            )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('data.0.status', 'active');
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 10 - ESTADO INVÁLIDO
    |--------------------------------------------------------------------------
    */

    public function test_invalid_status_is_rejected(): void
    {
        $admin = $this->makeUser('iam_admin');

        $this->actingAs($admin, 'web')
            ->getJson('/api/users?status=deleted')
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'status',
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 11 - PAGINACIÓN EXCESIVA
    |--------------------------------------------------------------------------
    |
    | El servidor no debe aceptar
    | tamaños superiores al máximo definido.
    |
    */

    public function test_excessive_page_size_is_rejected(): void
    {
        $admin = $this->makeUser('iam_admin');

        $this->actingAs($admin, 'web')
            ->getJson('/api/users?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'per_page',
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 12 - PÁGINA INVÁLIDA
    |--------------------------------------------------------------------------
    */

    public function test_invalid_page_is_rejected(): void
    {
        $admin = $this->makeUser('iam_admin');

        $this->actingAs($admin, 'web')
            ->getJson('/api/users?page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'page',
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 13 - PROTECCIÓN DE DATOS SENSIBLES
    |--------------------------------------------------------------------------
    |
    | El listado IAM no debe exponer:
    |
    | - Hash de contraseña.
    | - Token de recuperación.
    | - Remember token.
    | - Timestamps internos.
    |
    */

    public function test_identity_list_does_not_expose_sensitive_fields(): void
    {
        $admin = $this->makeUser('iam_admin');

        $this->actingAs($admin, 'web')
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token')
            ->assertJsonMissingPath('data.0.email_verified_at')
            ->assertJsonMissingPath('data.0.created_at')
            ->assertJsonMissingPath('data.0.updated_at')
            ->assertJsonMissingPath('data.0.roles');
    }
}
