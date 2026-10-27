<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use App\Models\AccessRequest;
use App\Models\AuditLog;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class UserDeprovisioningTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | PROTEGER LA BASE DE DATOS DE DESARROLLO
    |--------------------------------------------------------------------------
    */

    protected function beforeRefreshingDatabase(): void
    {
        if (
            config('database.default') !== 'mysql'
            || DB::connection()->getDatabaseName() !== 'yesiam_test'
        ) {
            throw new RuntimeException(
                'Esta prueba requiere MySQL con la base yesiam_test.'
            );
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.driver', 'database');

        $this->seed(RbacSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR UNA IDENTIDAD CON UN ROL
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
    | CREAR RECURSO
    |--------------------------------------------------------------------------
    */

    private function makeResource(string $code): Resource
    {
        return Resource::create([
            'name' => "Recurso {$code}",
            'code' => $code,
            'description' => 'Recurso para pruebas JML',
            'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR SOLICITUD APROBADA
    |--------------------------------------------------------------------------
    */

    private function makeApprovedRequest(
        User $employee,
        Resource $resource,
        User $approver
    ): AccessRequest {
        $accessRequest = AccessRequest::create([
            'requester_user_id' => $employee->id,
            'resource_id' => $resource->id,
            'justification' =>
                'Acceso necesario para realizar actividades laborales.',
        ]);

        $accessRequest->forceFill([
            'status' => 'approved',
            'decided_by_user_id' => $approver->id,
            'decision_reason' =>
                'Solicitud autorizada para las funciones asignadas.',
            'decided_at' => now(),
        ])->save();

        return $accessRequest->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR CONCESIÓN
    |--------------------------------------------------------------------------
    */

    private function makeGrant(
        User $employee,
        Resource $resource,
        User $admin,
        string $status = 'active'
    ): AccessGrant {
        $accessRequest = $this->makeApprovedRequest(
            $employee,
            $resource,
            $admin
        );

        $attributes = [
            'access_request_id' => $accessRequest->id,
            'user_id' => $employee->id,
            'resource_id' => $resource->id,
            'status' => $status,
            'granted_by_user_id' => $admin->id,
            'granted_at' => now(),
        ];

        if ($status === 'revoked') {
            $attributes['revoked_by_user_id'] = $admin->id;
            $attributes['revoked_at'] = now();
            $attributes['revocation_reason'] =
                'Revocación histórica anterior a la baja.';
        }

        $grant = new AccessGrant();

        $grant->forceFill($attributes)->save();

        return $grant->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR SESIÓN PERSISTIDA
    |--------------------------------------------------------------------------
    */

    private function makeSession(
        User $user,
        string $sessionId
    ): void {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'YESIAM Test',
            'payload' => 'test-payload',
            'last_activity' => time(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | TEST 1
    | LA BAJA REVOCA TODOS LOS ACCESOS ACTIVOS
    |--------------------------------------------------------------------------
    */

    public function test_deactivation_revokes_all_active_grants_and_credentials(): void
    {
        $admin = $this->makeUser('iam_admin');
        $employee = $this->makeUser('employee');

        $vpn = $this->makeResource('VPN');
        $crm = $this->makeResource('CRM');

        /*
         * Dos concesiones activas.
         */
        $vpnGrant = $this->makeGrant(
            $employee,
            $vpn,
            $admin
        );

        $crmGrant = $this->makeGrant(
            $employee,
            $crm,
            $admin
        );

        /*
         * Una concesión ya revocada.
         *
         * Debe conservarse sin modificaciones.
         */
        $historicGrant = $this->makeGrant(
            $employee,
            $vpn,
            $admin,
            'revoked'
        );

        $historicRevokedAt = $historicGrant->revoked_at;

        /*
         * Simular credenciales vigentes.
         */
        $token = $employee->createToken(
            'token-previo-a-la-baja'
        );

        $tokenId = $token->accessToken->id;

        $this->makeSession(
            $employee,
            'employee-session-1'
        );

        $this->makeSession(
            $employee,
            'employee-session-2'
        );

        /*
         * Esta sesión pertenece al administrador.
         * No debe eliminarse.
         */
        $this->makeSession(
            $admin,
            'admin-session'
        );

        /*
         * Ejecutar la baja.
         */
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson(
            "/api/users/{$employee->id}/deactivate"
        )
            ->assertOk()
            ->assertJsonPath('data.id', $employee->id)
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath(
                'data.revoked_access_grants_count',
                2
            );

        /*
         * La identidad ya no debe estar activa.
         */
        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'status' => 'disabled',
        ]);

        /*
         * Las dos concesiones activas
         * deben quedar revocadas.
         */
        foreach ([$vpnGrant, $crmGrant] as $grant) {
            $grant->refresh();

            $this->assertSame(
                'revoked',
                $grant->status
            );

            $this->assertSame(
                (int) $admin->id,
                (int) $grant->revoked_by_user_id
            );

            $this->assertNotNull(
                $grant->revoked_at
            );

            $this->assertSame(
                'Revocación automática por desactivación de identidad.',
                $grant->revocation_reason
            );
        }

        /*
         * La concesión revocada anteriormente
         * debe conservar su información histórica.
         */
        $historicGrant->refresh();

        $this->assertSame(
            'revoked',
            $historicGrant->status
        );

        $this->assertSame(
            'Revocación histórica anterior a la baja.',
            $historicGrant->revocation_reason
        );

        $this->assertEquals(
            $historicRevokedAt,
            $historicGrant->revoked_at
        );

        /*
         * Ninguna concesión debe continuar activa.
         */
        $this->assertSame(
            0,
            AccessGrant::query()
                ->where('user_id', $employee->id)
                ->where('status', 'active')
                ->count()
        );

        /*
         * Conservar los tres registros
         * para mantener la trazabilidad.
         */
        $this->assertDatabaseCount(
            'access_grants',
            3
        );

        /*
         * Eliminar el token del empleado.
         */
        $this->assertDatabaseMissing(
            'personal_access_tokens',
            [
                'id' => $tokenId,
            ]
        );

        /*
         * Eliminar las sesiones del empleado.
         */
        $this->assertSame(
            0,
            DB::table('sessions')
                ->where('user_id', $employee->id)
                ->count()
        );

        /*
         * Mantener la sesión del administrador.
         */
        $this->assertDatabaseHas('sessions', [
            'id' => 'admin-session',
            'user_id' => $admin->id,
        ]);

        /*
         * Dos auditorías de revocación.
         */
        $this->assertSame(
            2,
            AuditLog::query()
                ->where('action', 'access.revoked')
                ->where('actor_user_id', $admin->id)
                ->count()
        );

        /*
         * Comprobar que cada auditoría
         * referencia su solicitud original.
         */
        foreach ([$vpnGrant, $crmGrant] as $grant) {
            $audit = AuditLog::query()
                ->where('action', 'access.revoked')
                ->where('entity_type', 'access_request')
                ->where('entity_id', $grant->access_request_id)
                ->firstOrFail();

            $this->assertSame(
                (int) $grant->id,
                (int) $audit->metadata['access_grant_id']
            );

            $this->assertSame(
                'revoked',
                $audit->metadata['new_status']
            );
        }

        /*
         * Auditoría de desactivación.
         */
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'identity.deactivated',
            'entity_type' => 'user',
            'entity_id' => $employee->id,
            'outcome' => 'success',
        ]);

        /*
         * Total esperado:
         *
         * 2 revocaciones
         * 1 desactivación
         */
        $this->assertDatabaseCount(
            'audit_logs',
            3
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TEST 2
    | UNA IDENTIDAD SIN ACCESOS TAMBIÉN PUEDE DESACTIVARSE
    |--------------------------------------------------------------------------
    */

    public function test_deactivation_without_active_grants_succeeds(): void
    {
        $admin = $this->makeUser('iam_admin');
        $employee = $this->makeUser('employee');

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson(
            "/api/users/{$employee->id}/deactivate"
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath(
                'data.revoked_access_grants_count',
                0
            );

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'status' => 'disabled',
        ]);

        $this->assertDatabaseCount(
            'access_grants',
            0
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'identity.deactivated',
            'entity_id' => $employee->id,
        ]);

        $this->assertDatabaseCount(
            'audit_logs',
            1
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TEST 3
    | UN EMPLEADO NO PUEDE DESACTIVAR A OTRO USUARIO
    |--------------------------------------------------------------------------
    */

    public function test_employee_cannot_deactivate_another_identity(): void
    {
        $admin = $this->makeUser('iam_admin');
        $employee = $this->makeUser('employee');
        $otherEmployee = $this->makeUser('employee');

        $vpn = $this->makeResource('VPN');

        $grant = $this->makeGrant(
            $otherEmployee,
            $vpn,
            $admin
        );

        Sanctum::actingAs($employee, ['*']);

        $this->patchJson(
            "/api/users/{$otherEmployee->id}/deactivate"
        )->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $otherEmployee->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('access_grants', [
            'id' => $grant->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseCount(
            'audit_logs',
            0
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TEST 4
    | EL ADMINISTRADOR NO PUEDE DESACTIVARSE A SÍ MISMO
    |--------------------------------------------------------------------------
    */

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = $this->makeUser('iam_admin');

        Sanctum::actingAs($admin, ['*']);

        $this->patchJson(
            "/api/users/{$admin->id}/deactivate"
        )->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseCount(
            'audit_logs',
            0
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TEST 5
    | UNA SEGUNDA DESACTIVACIÓN NO REPITE REVOCACIONES
    |--------------------------------------------------------------------------
    */

    public function test_deactivation_is_not_processed_twice(): void
    {
        $admin = $this->makeUser('iam_admin');
        $employee = $this->makeUser('employee');

        $vpn = $this->makeResource('VPN');

        $grant = $this->makeGrant(
            $employee,
            $vpn,
            $admin
        );

        Sanctum::actingAs($admin, ['*']);

        /*
         * Primera desactivación.
         */
        $this->patchJson(
            "/api/users/{$employee->id}/deactivate"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.revoked_access_grants_count',
                1
            );

        /*
         * La segunda debe rechazarse.
         */
        $this->patchJson(
            "/api/users/{$employee->id}/deactivate"
        )->assertStatus(409);

        $this->assertDatabaseHas('access_grants', [
            'id' => $grant->id,
            'status' => 'revoked',
        ]);

        /*
         * No se deben duplicar auditorías.
         *
         * 1 revocación
         * 1 desactivación
         */
        $this->assertDatabaseCount(
            'audit_logs',
            2
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TEST 6
    | ERROR EN AUDITORÍA REVIERTE TODO EL DESPROVISIONAMIENTO
    |--------------------------------------------------------------------------
    */

    public function test_audit_failure_rolls_back_entire_deprovisioning(): void
    {
        $admin = $this->makeUser('iam_admin');
        $employee = $this->makeUser('employee');

        $vpn = $this->makeResource('VPN');

        $grant = $this->makeGrant(
            $employee,
            $vpn,
            $admin
        );

        $token = $employee->createToken(
            'token-que-debe-conservarse'
        );

        $tokenId = $token->accessToken->id;

        $this->makeSession(
            $employee,
            'rollback-session'
        );

        /*
         * Simular un fallo de auditoría
         * durante la revocación.
         *
         * El controlador debe revertir
         * toda la transacción.
         */
        $auditMock = Mockery::mock(
            AuditService::class
        )->makePartial();

        $auditMock
            ->shouldReceive('accessRevoked')
            ->once()
            ->andThrow(
                new RuntimeException(
                    'Fallo simulado de auditoría.'
                )
            );

        $this->app->instance(
            AuditService::class,
            $auditMock
        );

        Sanctum::actingAs($admin, ['*']);

        /*
         * Permitir que la excepción de prueba
         * llegue al test para comprobarla.
         */
        $this->withoutExceptionHandling();

        try {
            $this->patchJson(
                "/api/users/{$employee->id}/deactivate"
            );

            $this->fail(
                'La auditoría debía generar una excepción.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Fallo simulado de auditoría.',
                $exception->getMessage()
            );
        }

        /*
         * La identidad debe seguir activa.
         */
        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'status' => 'active',
        ]);

        /*
         * La concesión debe seguir activa.
         */
        $this->assertDatabaseHas('access_grants', [
            'id' => $grant->id,
            'status' => 'active',
            'revoked_at' => null,
        ]);

        /*
         * Tokens y sesiones deben conservarse,
         * porque toda la transacción falló.
         */
        $this->assertDatabaseHas(
            'personal_access_tokens',
            [
                'id' => $tokenId,
            ]
        );

        $this->assertDatabaseHas('sessions', [
            'id' => 'rollback-session',
            'user_id' => $employee->id,
        ]);

        /*
         * Ninguna auditoría de baja
         * debe quedar registrada.
         */
        $this->assertDatabaseCount(
            'audit_logs',
            0
        );
    }
}
