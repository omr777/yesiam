<?php

namespace Tests\Feature;

use App\Models\AccessRequest;
use App\Models\AuditLog;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessRequestAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    private function makeUser(
        string $roleCode = 'employee'
    ): User {
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $role = Role::where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeResource(
        bool $active = true
    ): Resource {
        return Resource::create([
            'name' => 'VPN corporativa',
            'code' => 'VPN',
            'description' => 'Acceso remoto a la empresa',
            'is_active' => $active,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 1: SOLICITUD VÁLIDA GENERA AUDITORÍA
    |--------------------------------------------------------------------------
    */

    public function test_valid_request_creates_one_audit_event(): void
    {
        $employee = $this->makeUser();
        $resource = $this->makeResource();

        $this->actingAs($employee, 'web')
            ->postJson('/api/access-requests', [
                'resource_id' => $resource->id,
                'justification' => 'Necesito acceso remoto para trabajar.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $accessRequest = AccessRequest::firstOrFail();
        $log = AuditLog::firstOrFail();

        $this->assertEquals(
            'access.requested',
            $log->action
        );

        $this->assertEquals(
            'access_request',
            $log->entity_type
        );

        $this->assertEquals(
            $accessRequest->id,
            $log->entity_id
        );

        $this->assertEquals(
            'success',
            $log->outcome
        );

        $this->assertDatabaseCount('access_requests', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 2: ACTOR Y RECURSO CORRECTOS
    |--------------------------------------------------------------------------
    */

    public function test_audit_records_correct_actor_and_resource(): void
    {
        $daniela = $this->makeUser();
        $resource = $this->makeResource();

        $this->actingAs($daniela, 'web')
            ->postJson('/api/access-requests', [
                'resource_id' => $resource->id,
                'justification' => 'Necesito utilizar la VPN de la empresa.',
            ])
            ->assertCreated();

        $accessRequest = AccessRequest::firstOrFail();
        $log = AuditLog::firstOrFail();

        $this->assertEquals(
            $daniela->id,
            $log->actor_user_id
        );

        $this->assertEquals(
            $accessRequest->id,
            $log->entity_id
        );

        $this->assertEquals(
            $resource->id,
            $log->metadata['resource_id']
        );

        $this->assertEquals(
            'pending',
            $log->metadata['status']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 3: PROTEGER LA JUSTIFICACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_audit_does_not_copy_request_justification(): void
    {
        $employee = $this->makeUser();
        $resource = $this->makeResource();

        $justification = 'JUSTIFICACION_PRIVADA_ABC987';

        $this->actingAs($employee, 'web')
            ->postJson('/api/access-requests', [
                'resource_id' => $resource->id,
                'justification' => $justification,
            ])
            ->assertCreated();

        $log = AuditLog::firstOrFail();
        $accessRequest = AccessRequest::firstOrFail();

        // La justificación sí pertenece a la solicitud.
        $this->assertEquals(
            $justification,
            $accessRequest->justification
        );

        // Pero no debe copiarse a audit_logs.
        $this->assertArrayNotHasKey(
            'justification',
            $log->metadata
        );

        $this->assertStringNotContainsString(
            $justification,
            json_encode($log->metadata)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 4: SOLICITUD DUPLICADA NO GENERA AUDITORÍA
    |--------------------------------------------------------------------------
    */

    public function test_duplicate_request_does_not_create_extra_audit(): void
    {
        $employee = $this->makeUser();
        $resource = $this->makeResource();

        // Primera solicitud: válida.
        $this->actingAs($employee, 'web')
            ->postJson('/api/access-requests', [
                'resource_id' => $resource->id,
                'justification' => 'Necesito acceso remoto para trabajar.',
            ])
            ->assertCreated();

        $this->assertDatabaseCount('audit_logs', 1);

        // Segunda solicitud: duplicada.
        $this->postJson('/api/access-requests', [
            'resource_id' => $resource->id,
            'justification' => 'Necesito nuevamente acceso remoto.',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resource_id']);

        // Sigue existiendo una solicitud y un evento.
        $this->assertDatabaseCount('access_requests', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 5: OPERACIÓN RECHAZADA NO GENERA EVENTO
    |--------------------------------------------------------------------------
    */

    public function test_unauthorized_request_creates_no_audit_event(): void
    {
        $resource = $this->makeResource();

        $user = User::factory()->create([
            'status' => 'active',
        ]);

        // Este usuario no tiene roles ni access.request.
        $this->actingAs($user, 'web')
            ->postJson('/api/access-requests', [
                'resource_id' => $resource->id,
                'justification' => 'Necesito acceso a la VPN.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('access_requests', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
