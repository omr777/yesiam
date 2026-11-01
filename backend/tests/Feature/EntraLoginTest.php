<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| YESIAM - INICIO DE SESIÓN CON MICROSOFT ENTRA ID
|--------------------------------------------------------------------------
|
| FASE 22
|
| Ninguna prueba contacta a Microsoft: el endpoint de token
| se simula con Http::fake().
|
*/

class EntraLoginTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-1111-4111-8111-111111111111';
    private const CLIENT = '22222222-2222-4222-8222-222222222222';
    private const OID = '33333333-3333-4333-8333-333333333333';
    private const FRONT = 'http://localhost:4200';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.entra.tenant_id' => self::TENANT,
            'services.entra.client_id' => self::CLIENT,
            'services.entra.client_secret' => 'secreto-de-prueba',
            'services.entra.redirect_uri' => 'http://localhost:8000/auth/microsoft/callback',
            'services.entra.frontend_url' => self::FRONT,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Utilidades
    |--------------------------------------------------------------------------
    */

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function idToken(string $nonce, array $overrides = []): string
    {
        $claims = array_merge([
            'iss' => 'https://login.microsoftonline.com/'.self::TENANT.'/v2.0',
            'aud' => self::CLIENT,
            'tid' => self::TENANT,
            'oid' => self::OID,
            'nonce' => $nonce,
            'exp' => time() + 3600,
            'preferred_username' => 'Ana.Torres@lab.onmicrosoft.com',
            'name' => 'Ana Torres',
        ], $overrides);

        return $this->b64('{"alg":"RS256"}')
            .'.'.$this->b64(json_encode($claims))
            .'.firma';
    }

    /**
     * Inicia el flujo y devuelve state, nonce y verifier.
     *
     * @return array{state: string, nonce: string, verifier: string, challenge: string}
     */
    private function startFlow(): array
    {
        $response = $this->get('/auth/microsoft/redirect');

        $response->assertRedirect();

        parse_str(
            (string) parse_url($response->headers->get('Location'), PHP_URL_QUERY),
            $query
        );

        $flow = session('entra');

        return [
            'state' => $query['state'],
            'nonce' => $query['nonce'],
            'verifier' => $flow['verifier'],
            'challenge' => $query['code_challenge'],
        ];
    }

    /**
     * Ejecuta el callback con un ID token simulado.
     *
     * @param  array<string, mixed>  $claims
     */
    private function loginWith(array $claims = [], ?array $flow = null): \Illuminate\Testing\TestResponse
    {
        $flow ??= $this->startFlow();

        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'id_token' => $this->idToken($flow['nonce'], $claims),
            ]),
        ]);

        return $this->get('/auth/microsoft/callback?'.http_build_query([
            'code' => 'codigo-de-prueba',
            'state' => $flow['state'],
        ]));
    }

    private function activeUser(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'email' => 'ana.torres@lab.onmicrosoft.com',
            'status' => 'active',
        ], $attributes));

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | Redirección
    |--------------------------------------------------------------------------
    */

    public function test_redirect_fails_gracefully_when_not_configured(): void
    {
        config(['services.entra.client_secret' => null]);

        $this->get('/auth/microsoft/redirect')
            ->assertRedirect(self::FRONT.'/sign-in?error=entra_not_configured');
    }

    public function test_redirect_sends_user_to_microsoft_with_pkce(): void
    {
        $flow = $this->startFlow();

        $response = $this->get('/auth/microsoft/redirect');
        $location = $response->headers->get('Location');

        $this->assertStringStartsWith(
            'https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/authorize?',
            $location
        );

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame(self::CLIENT, $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertArrayNotHasKey('client_secret', $query);

        $this->assertNotSame($flow['state'], $query['state']);
    }

    /*
    |--------------------------------------------------------------------------
    | Callback: errores del flujo
    |--------------------------------------------------------------------------
    */

    public function test_callback_rejects_state_mismatch(): void
    {
        $this->activeUser();
        $this->startFlow();

        Http::fake();

        $this->get('/auth/microsoft/callback?code=x&state=otro-estado')
            ->assertRedirect(self::FRONT.'/sign-in?error=entra_invalid');

        $this->assertGuest('web');
        Http::assertNothingSent();
    }

    public function test_callback_without_started_flow_is_rejected(): void
    {
        Http::fake();

        $this->get('/auth/microsoft/callback?code=x&state=y')
            ->assertRedirect(self::FRONT.'/sign-in?error=entra_invalid');

        Http::assertNothingSent();
    }

    public function test_user_cancelling_at_microsoft_is_reported(): void
    {
        $this->startFlow();

        $this->get('/auth/microsoft/callback?error=access_denied')
            ->assertRedirect(self::FRONT.'/sign-in?error=entra_denied');

        $this->assertGuest('web');
    }

    public function test_flow_context_is_single_use(): void
    {
        $this->activeUser();
        $flow = $this->startFlow();

        $this->loginWith([], $flow)->assertRedirect(self::FRONT.'/');

        auth()->guard('web')->logout();

        $this->get('/auth/microsoft/callback?code=x&state='.$flow['state'])
            ->assertRedirect(self::FRONT.'/sign-in?error=entra_invalid');
    }

    public function test_token_exchange_sends_secret_and_pkce_verifier(): void
    {
        $this->activeUser();
        $flow = $this->startFlow();

        $this->loginWith([], $flow);

        Http::assertSent(function (Request $request) use ($flow) {
            $expectedChallenge = rtrim(strtr(
                base64_encode(hash('sha256', $request['code_verifier'], true)),
                '+/',
                '-_'
            ), '=');

            return $request->url() === 'https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/token'
                && $request['client_secret'] === 'secreto-de-prueba'
                && $request['code'] === 'codigo-de-prueba'
                && $request['code_verifier'] === $flow['verifier']
                && $expectedChallenge === $flow['challenge'];
        });
    }

    public function test_rejected_token_exchange_is_reported(): void
    {
        $this->activeUser();
        $flow = $this->startFlow();

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->get('/auth/microsoft/callback?code=x&state='.$flow['state'])
            ->assertRedirect(self::FRONT.'/sign-in?error=entra_invalid');

        $this->assertGuest('web');
    }

    public function test_unreachable_microsoft_is_reported(): void
    {
        $this->activeUser();
        $flow = $this->startFlow();

        Http::fake(fn () => throw new ConnectionException('sin red'));

        $this->get('/auth/microsoft/callback?code=x&state='.$flow['state'])
            ->assertRedirect(self::FRONT.'/sign-in?error=entra_unavailable');
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidClaims(): array
    {
        return [
            'otra audiencia' => [['aud' => 'otra-app']],
            'otro emisor' => [['iss' => 'https://login.microsoftonline.com/otro/v2.0']],
            'otro inquilino' => [['tid' => '99999999-9999-4999-8999-999999999999']],
            'nonce distinto' => [['nonce' => 'otro-nonce']],
            'token vencido' => [['exp' => 1000]],
            'sin oid' => [['oid' => null]],
            'oid no válido' => [['oid' => 'no-es-un-uuid']],
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    #[DataProvider('invalidClaims')]
    public function test_invalid_id_token_claims_are_rejected(array $claims): void
    {
        $this->activeUser();

        $this->loginWith($claims)
            ->assertRedirect(self::FRONT.'/sign-in?error=entra_invalid');

        $this->assertGuest('web');
        $this->assertNull(User::first()->entra_object_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Callback: reglas de identidad
    |--------------------------------------------------------------------------
    */

    public function test_first_login_links_by_upn_and_starts_session(): void
    {
        $user = $this->activeUser();

        $this->loginWith()->assertRedirect(self::FRONT.'/');

        $this->assertAuthenticatedAs($user->fresh(), 'web');

        $user->refresh();
        $this->assertSame(self::OID, $user->entra_object_id);
        $this->assertNotNull($user->entra_linked_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.entra_linked',
            'actor_user_id' => $user->id,
            'entity_id' => $user->id,
            'outcome' => 'success',
        ]);
    }

    public function test_linking_is_case_insensitive(): void
    {
        $user = $this->activeUser(['email' => 'ana.torres@lab.onmicrosoft.com']);

        $this->loginWith(['preferred_username' => 'ANA.TORRES@LAB.ONMICROSOFT.COM'])
            ->assertRedirect(self::FRONT.'/');

        $this->assertAuthenticatedAs($user->fresh(), 'web');
    }

    public function test_linked_identity_signs_in_by_oid_even_if_upn_changed(): void
    {
        $user = $this->activeUser([
            'entra_object_id' => self::OID,
            'entra_linked_at' => now(),
        ]);

        $this->loginWith(['preferred_username' => 'nuevo.nombre@lab.onmicrosoft.com'])
            ->assertRedirect(self::FRONT.'/');

        $this->assertAuthenticatedAs($user->fresh(), 'web');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.entra_login',
            'actor_user_id' => $user->id,
        ]);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'auth.entra_linked',
        ]);
    }

    public function test_unknown_identity_has_no_access_and_nothing_is_created(): void
    {
        $this->loginWith(['preferred_username' => 'nadie@lab.onmicrosoft.com'])
            ->assertRedirect(self::FRONT.'/sign-in?error=entra_no_access');

        $this->assertGuest('web');
        $this->assertSame(0, User::count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_suspended_identity_has_no_access_and_is_not_linked(): void
    {
        $user = $this->activeUser(['status' => 'suspended']);

        $this->loginWith()
            ->assertRedirect(self::FRONT.'/sign-in?error=entra_no_access');

        $this->assertGuest('web');
        $this->assertNull($user->fresh()->entra_object_id);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_disabled_linked_identity_has_no_access(): void
    {
        $this->activeUser([
            'status' => 'disabled',
            'entra_object_id' => self::OID,
        ]);

        $this->loginWith()
            ->assertRedirect(self::FRONT.'/sign-in?error=entra_no_access');

        $this->assertGuest('web');
    }

    public function test_email_already_linked_to_another_account_is_rejected(): void
    {
        $user = $this->activeUser([
            'entra_object_id' => '44444444-4444-4444-8444-444444444444',
        ]);

        $this->loginWith()
            ->assertRedirect(self::FRONT.'/sign-in?error=entra_no_access');

        $this->assertGuest('web');
        $this->assertSame(
            '44444444-4444-4444-8444-444444444444',
            $user->fresh()->entra_object_id
        );
    }

    public function test_audit_does_not_store_tokens_or_entra_identifiers(): void
    {
        $this->activeUser();

        $this->loginWith();

        $log = AuditLog::where('action', 'auth.entra_linked')->firstOrFail();
        $serialized = json_encode($log->toArray());

        $this->assertStringNotContainsString(self::OID, $serialized);
        $this->assertStringNotContainsString('codigo-de-prueba', $serialized);
        $this->assertStringNotContainsString('secreto-de-prueba', $serialized);
        $this->assertSame(['method' => 'entra'], $log->metadata);
    }

    public function test_entra_object_id_is_never_serialized(): void
    {
        $user = $this->activeUser(['entra_object_id' => self::OID]);

        $this->assertArrayNotHasKey('entra_object_id', $user->toArray());
    }
}
