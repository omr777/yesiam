<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| YESIAM - INICIO DE SESIÓN CON MICROSOFT ENTRA ID
|--------------------------------------------------------------------------
|
| FASE 22
|
| Flujo "authorization code" con PKCE y client secret, ejecutado por
| Laravel (cliente confidencial). El navegador nunca recibe tokens.
|
| El ID token llega directamente desde el endpoint de token de
| Microsoft por TLS. Según OpenID Connect Core 3.1.3.7, en ese caso
| la validación del certificado TLS sustituye a la firma del token.
| Aun así se validan emisor, audiencia, inquilino, expiración y nonce.
|
| Entra solo dice QUIÉN es la persona. Los permisos siguen
| decidiéndose en Laravel.
|
*/

class EntraAuthService
{
    public function isConfigured(): bool
    {
        foreach (['tenant_id', 'client_id', 'client_secret', 'redirect_uri'] as $key) {
            if (blank(config("services.entra.$key"))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Prepara la redirección hacia Microsoft.
     *
     * @return array{url: string, session: array<string, string>}
     */
    public function authorizationRequest(): array
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);

        $challenge = rtrim(
            strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'),
            '='
        );

        $query = http_build_query([
            'client_id' => config('services.entra.client_id'),
            'response_type' => 'code',
            'redirect_uri' => config('services.entra.redirect_uri'),
            'response_mode' => 'query',
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'url' => $this->authority().'/oauth2/v2.0/authorize?'.$query,
            'session' => [
                'state' => $state,
                'nonce' => $nonce,
                'verifier' => $verifier,
            ],
        ];
    }

    /**
     * Cambia el código por el ID token y devuelve sus claims validados.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException  Token inválido o rechazado.
     * @throws ConnectionException  Microsoft no respondió.
     */
    public function claimsFromCode(
        string $code,
        string $verifier,
        string $expectedNonce
    ): array {
        $response = Http::asForm()
            ->timeout(10)
            ->post($this->authority().'/oauth2/v2.0/token', [
                'client_id' => config('services.entra.client_id'),
                'client_secret' => config('services.entra.client_secret'),
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('services.entra.redirect_uri'),
                'code_verifier' => $verifier,
                'scope' => 'openid profile email',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Microsoft rechazó el código de autorización.');
        }

        $idToken = $response->json('id_token');

        if (! is_string($idToken)) {
            throw new RuntimeException('La respuesta no incluye ID token.');
        }

        $claims = $this->decodeClaims($idToken);

        $this->validateClaims($claims, $expectedNonce);

        return $claims;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new RuntimeException('ID token con formato inválido.');
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $payload === false ? null : json_decode($payload, true);

        if (! is_array($claims)) {
            throw new RuntimeException('ID token ilegible.');
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function validateClaims(array $claims, string $expectedNonce): void
    {
        $tenant = (string) config('services.entra.tenant_id');
        $client = (string) config('services.entra.client_id');

        $issuer = rtrim((string) config('services.entra.authority'), '/')
            .'/'.$tenant.'/v2.0';

        $checks = [
            'emisor' => ($claims['iss'] ?? null) === $issuer,
            'audiencia' => ($claims['aud'] ?? null) === $client,
            'inquilino' => strcasecmp((string) ($claims['tid'] ?? ''), $tenant) === 0,
            'nonce' => is_string($claims['nonce'] ?? null)
                && hash_equals($expectedNonce, $claims['nonce']),
            'expiración' => is_numeric($claims['exp'] ?? null)
                && (int) $claims['exp'] > time(),
            'objeto' => is_string($claims['oid'] ?? null)
                && Str::isUuid($claims['oid']),
        ];

        foreach ($checks as $name => $valid) {
            if (! $valid) {
                throw new RuntimeException("ID token inválido ($name).");
            }
        }
    }

    private function authority(): string
    {
        return rtrim((string) config('services.entra.authority'), '/')
            .'/'.config('services.entra.tenant_id');
    }
}
