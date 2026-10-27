<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use App\Models\AccessRequest;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessGrantIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Estas pruebas comprueban una característica
         * específica de MySQL y nuestra migración.
         */
        $this->assertSame(
            'mysql',
            DB::connection()->getDriverName(),
            'Estas pruebas requieren MySQL.'
        );

        $this->assertTrue(
            Schema::hasColumn(
                'access_grants',
                'active_resource_id'
            ),
            'Falta la columna generada active_resource_id.'
        );

        $this->assertTrue(
            Schema::hasIndex(
                'access_grants',
                'access_grants_unique_active_user_resource'
            ),
            'Falta el índice UNIQUE de accesos activos.'
        );

        $this->seed(RbacSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | MÉTODO AUXILIAR: CREAR USUARIO
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
    | MÉTODO AUXILIAR: CREAR RECURSO
    |--------------------------------------------------------------------------
    */

    private function makeResource(
        string $code = 'VPN'
    ): Resource {
        return Resource::create([
            'name' => "Recurso {$code}",
            'code' => $code,
            'description' => 'Recurso de prueba de integridad',
            'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | MÉTODO AUXILIAR: CREAR SOLICITUD APROBADA
    |--------------------------------------------------------------------------
    */

    private function approvedRequest(
        User $employee,
        Resource $resource,
        User $approver
    ): AccessRequest {
        $accessRequest = AccessRequest::create([
            'requester_user_id' => $employee->id,
            'resource_id' => $resource->id,
            'justification' =>
                'Necesito este recurso para realizar mis funciones.',
        ]);

        $accessRequest->forceFill([
            'status' => 'approved',
            'decided_by_user_id' => $approver->id,
            'decision_reason' =>
                'Acceso necesario para las actividades laborales.',
            'decided_at' => now(),
        ])->save();

        return $accessRequest->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | MÉTODO AUXILIAR: INSERTAR CONCESIÓN
    |--------------------------------------------------------------------------
    |
    | Insertamos directamente mediante Eloquent.
    |
    | No llamamos al controlador, porque queremos
    | comprobar las restricciones de MySQL.
    |
    */

    private function insertGrant(
        AccessRequest $accessRequest,
        User $admin,
        string $status = 'active'
    ): AccessGrant {
        $grant = new AccessGrant();

        $attributes = [
            'access_request_id' => $accessRequest->id,
            'user_id' => $accessRequest->requester_user_id,
            'resource_id' => $accessRequest->resource_id,
            'status' => $status,
            'granted_by_user_id' => $admin->id,
            'granted_at' => now(),
        ];

        if ($status === 'revoked') {
            $attributes['revoked_by_user_id'] = $admin->id;
            $attributes['revoked_at'] = now();
            $attributes['revocation_reason'] =
                'Acceso revocado para prueba de integridad.';
        }

        $grant->forceFill($attributes);

        $grant->save();

        return $grant->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | MÉTODO AUXILIAR: ESCENARIO BASE
    |--------------------------------------------------------------------------
    */

    private function scenario(): array
    {
        $admin = $this->makeUser('iam_admin');

        $approver = $this->makeUser('resource_manager');

        $employee = $this->makeUser('employee');

        $resource = $this->makeResource();

        return compact(
            'admin',
            'approver',
            'employee',
            'resource'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TEST 1
    | MYSQL RECHAZA DOS CONCESIONES ACTIVAS IGUALES
    |--------------------------------------------------------------------------
    */

    public function test_mysql_rejects_duplicate_active_grants(): void
    {
        $data = $this->scenario();

        $firstRequest = $this->approvedRequest(
            $data['employee'],
            $data['resource'],
            $data['approver']
        );

        $secondRequest = $this->approvedRequest(
            $data['employee'],
            $data['resource'],
            $data['approver']
        );

        /*
         * Primera concesión:
         * MySQL debe aceptarla.
         */
        $firstGrant = $this->insertGrant(
            $firstRequest,
            $data['admin']
        );

        $this->assertDatabaseCount(
            'access_grants',
            1
        );

        /*
         * Segunda concesión:
         * misma persona, mismo recurso,
         * pero una solicitud diferente.
         *
         * Así comprobamos específicamente
         * el nuevo índice UNIQUE y no el
         * índice de access_request_id.
         */
        try {
            $this->insertGrant(
                $secondRequest,
                $data['admin']
            );

            $this->fail(
                'MySQL permitió dos concesiones activas del mismo recurso.'
            );

        } catch (UniqueConstraintViolationException $exception) {

            $this->assertSame(
                'access_grants_unique_active_user_resource',
                $exception->index,
                'Se violó un índice distinto al esperado.'
            );
        }

        /*
         * La segunda inserción no debe
         * haber creado ninguna concesión.
         */
        $this->assertDatabaseCount(
            'access_grants',
            1
        );

        $this->assertDatabaseHas('access_grants', [
            'id' => $firstGrant->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseMissing('access_grants', [
            'access_request_id' => $secondRequest->id,
        ]);

        /*
         * No utilizamos el controlador,
         * así que tampoco debe existir
         * una auditoría de concesión.
         */
        $this->assertDatabaseCount(
            'audit_logs',
            0
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TEST 2
    | MYSQL PERMITE MÚLTIPLES CONCESIONES REVOCADAS
    |--------------------------------------------------------------------------
    */

    public function test_mysql_allows_multiple_revoked_grants(): void
    {
        $data = $this->scenario();

        $firstRequest = $this->approvedRequest(
            $data['employee'],
            $data['resource'],
            $data['approver']
        );

        $secondRequest = $this->approvedRequest(
            $data['employee'],
            $data['resource'],
            $data['approver']
        );

        /*
         * Ambas concesiones son históricas.
         */
        $firstGrant = $this->insertGrant(
            $firstRequest,
            $data['admin'],
            'revoked'
        );

        $secondGrant = $this->insertGrant(
            $secondRequest,
            $data['admin'],
            'revoked'
        );

        $this->assertDatabaseCount(
            'access_grants',
            2
        );

        /*
         * Comprobar el cálculo automático
         * de la columna generada.
         *
         * Las concesiones revocadas
         * deben producir NULL.
         */
        $firstCalculatedValue = DB::table('access_grants')
            ->where('id', $firstGrant->id)
            ->value('active_resource_id');

        $secondCalculatedValue = DB::table('access_grants')
            ->where('id', $secondGrant->id)
            ->value('active_resource_id');

        $this->assertNull(
            $firstCalculatedValue
        );

        $this->assertNull(
            $secondCalculatedValue
        );

        $this->assertSame(
            2,
            AccessGrant::query()
                ->where('user_id', $data['employee']->id)
                ->where('resource_id', $data['resource']->id)
                ->where('status', 'revoked')
                ->count()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TEST 3
    | UNA REVOCACIÓN PERMITE CONCEDER NUEVAMENTE
    |--------------------------------------------------------------------------
    */

    public function test_revocation_allows_new_active_grant(): void
    {
        $data = $this->scenario();

        $firstRequest = $this->approvedRequest(
            $data['employee'],
            $data['resource'],
            $data['approver']
        );

        /*
         * Concesión inicial.
         */
        $firstGrant = $this->insertGrant(
            $firstRequest,
            $data['admin']
        );

        $this->assertSame(
            1,
            AccessGrant::query()
                ->where('status', 'active')
                ->count()
        );

        /*
         * Revocar la concesión inicial.
         *
         * MySQL recalculará:
         *
         * active_resource_id = NULL
         */
        $firstGrant->forceFill([
            'status' => 'revoked',
            'revoked_by_user_id' => $data['admin']->id,
            'revoked_at' => now(),
            'revocation_reason' =>
                'El acceso inicial dejó de ser necesario.',
        ])->save();

        $firstGrant->refresh();

        $this->assertSame(
            'revoked',
            $firstGrant->status
        );

        $this->assertNull(
            DB::table('access_grants')
                ->where('id', $firstGrant->id)
                ->value('active_resource_id')
        );

        /*
         * Crear una solicitud diferente
         * para el mismo usuario y recurso.
         */
        $secondRequest = $this->approvedRequest(
            $data['employee'],
            $data['resource'],
            $data['approver']
        );

        /*
         * Esta nueva concesión debe
         * permitirse porque la anterior
         * ya está revocada.
         */
        $secondGrant = $this->insertGrant(
            $secondRequest,
            $data['admin']
        );

        $this->assertDatabaseCount(
            'access_grants',
            2
        );

        $this->assertDatabaseHas('access_grants', [
            'id' => $firstGrant->id,
            'status' => 'revoked',
        ]);

        $this->assertDatabaseHas('access_grants', [
            'id' => $secondGrant->id,
            'status' => 'active',
        ]);

        $this->assertSame(
            1,
            AccessGrant::query()
                ->where('user_id', $data['employee']->id)
                ->where('resource_id', $data['resource']->id)
                ->where('status', 'active')
                ->count()
        );

        /*
         * Comprobar que la nueva concesión
         * ocupa el espacio único del recurso.
         */
        $this->assertEquals(
            $data['resource']->id,
            DB::table('access_grants')
                ->where('id', $secondGrant->id)
                ->value('active_resource_id')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TEST 4
    | DIFERENTES USUARIOS PUEDEN ACCEDER AL MISMO RECURSO
    |--------------------------------------------------------------------------
    */

    public function test_different_users_can_have_same_active_resource(): void
    {
        $data = $this->scenario();

        $secondEmployee = $this->makeUser('employee');

        $firstRequest = $this->approvedRequest(
            $data['employee'],
            $data['resource'],
            $data['approver']
        );

        $secondRequest = $this->approvedRequest(
            $secondEmployee,
            $data['resource'],
            $data['approver']
        );

        $firstGrant = $this->insertGrant(
            $firstRequest,
            $data['admin']
        );

        $secondGrant = $this->insertGrant(
            $secondRequest,
            $data['admin']
        );

        /*
         * Ambos deben tener concesiones
         * activas para el mismo recurso.
         *
         * El índice UNIQUE está compuesto
         * por user_id y active_resource_id.
         */
        $this->assertDatabaseCount(
            'access_grants',
            2
        );

        $this->assertDatabaseHas('access_grants', [
            'id' => $firstGrant->id,
            'user_id' => $data['employee']->id,
            'resource_id' => $data['resource']->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('access_grants', [
            'id' => $secondGrant->id,
            'user_id' => $secondEmployee->id,
            'resource_id' => $data['resource']->id,
            'status' => 'active',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | TEST 5
    | LA API RESPONDE 409 SIN CREAR UNA AUDITORÍA FALSA
    |--------------------------------------------------------------------------
    */

    public function test_api_rejects_duplicate_grant_without_false_audit(): void
    {
        $data = $this->scenario();

        $firstRequest = $this->approvedRequest(
            $data['employee'],
            $data['resource'],
            $data['approver']
        );

        $secondRequest = $this->approvedRequest(
            $data['employee'],
            $data['resource'],
            $data['approver']
        );

        /*
         * Preparar una concesión activa
         * directamente en la base de datos.
         *
         * No se genera auditoría porque
         * no se utiliza el controlador.
         */
        $existingGrant = $this->insertGrant(
            $firstRequest,
            $data['admin']
        );

        /*
         * Autenticarnos como administrador IAM.
         */
        Sanctum::actingAs(
            $data['admin'],
            ['*']
        );

        /*
         * Intentar conceder nuevamente
         * el mismo recurso al mismo usuario.
         */
        $this->postJson(
            "/api/access-requests/{$secondRequest->id}/grant"
        )
            ->assertStatus(409)
            ->assertJsonStructure([
                'message',
            ]);

        /*
         * No debe haberse generado
         * una segunda concesión.
         */
        $this->assertDatabaseCount(
            'access_grants',
            1
        );

        $this->assertDatabaseHas('access_grants', [
            'id' => $existingGrant->id,
            'user_id' => $data['employee']->id,
            'resource_id' => $data['resource']->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseMissing('access_grants', [
            'access_request_id' => $secondRequest->id,
        ]);

        /*
         * No debe existir un registro falso
         * que afirme que el acceso se concedió.
         */
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'access.granted',
            'entity_id' => $secondRequest->id,
        ]);

        $this->assertDatabaseCount(
            'audit_logs',
            0
        );
    }
}
