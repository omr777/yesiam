<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use App\Services\EntraAuthService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| YESIAM - CONTROLADOR DE INICIO DE SESIÓN CON ENTRA ID
|--------------------------------------------------------------------------
|
| FASE 22
|
| Reglas:
|
| - Solo entran identidades que YA existen en YESIAM y están activas.
|   No se crea ninguna cuenta automáticamente.
| - La primera vez se enlaza por correo/UPN. Desde entonces la llave
|   es el "oid" de Entra, que no cambia.
| - Si el correo pertenece a una cuenta ya enlazada con OTRO oid,
|   se rechaza (evita suplantación).
| - Al terminar siempre se redirige al frontend; los errores viajan
|   como un código corto, nunca como detalle técnico.
|
*/

class EntraAuthController extends Controller
{
    public function redirect(
        Request $request,
        EntraAuthService $entra
    ): RedirectResponse {
        if (! $entra->isConfigured()) {
            return $this->failure('entra_not_configured');
        }

        $flow = $entra->authorizationRequest();

        $request->session()->put('entra', $flow['session']);

        return redirect()->away($flow['url']);
    }

    public function callback(
        Request $request,
        EntraAuthService $entra,
        AuditService $audit
    ): RedirectResponse {
        /*
         * El contexto del flujo se usa una sola vez.
         */
        $flow = $request->session()->pull('entra');

        if ($request->filled('error')) {
            return $this->failure('entra_denied');
        }

        $state = $request->query('state');
        $code = $request->query('code');

        if (
            ! is_array($flow)
            || ! is_string($state)
            || ! is_string($code)
            || ! hash_equals((string) ($flow['state'] ?? ''), $state)
        ) {
            return $this->failure('entra_invalid');
        }

        try {
            $claims = $entra->claimsFromCode(
                $code,
                (string) $flow['verifier'],
                (string) $flow['nonce']
            );
        } catch (ConnectionException) {
            return $this->failure('entra_unavailable');
        } catch (RuntimeException $exception) {
            Log::warning('Inicio de sesión con Entra rechazado.', [
                'reason' => $exception->getMessage(),
            ]);

            return $this->failure('entra_invalid');
        }

        $user = $this->resolveAndAudit($claims, $audit, $request->ip());

        if (! $user) {
            Log::notice('Entra autenticó a alguien sin acceso en YESIAM.');

            return $this->failure('entra_no_access');
        }

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        return redirect()->away($this->frontendUrl('/'));
    }

    /**
     * Busca la identidad, la enlaza si es la primera vez y deja
     * constancia en auditoría dentro de la misma transacción.
     *
     * @param  array<string, mixed>  $claims
     */
    private function resolveAndAudit(
        array $claims,
        AuditService $audit,
        ?string $ip
    ): ?User {
        $oid = strtolower((string) $claims['oid']);

        return DB::transaction(function () use ($claims, $oid, $audit, $ip) {
            $linked = false;

            $user = User::where('entra_object_id', $oid)
                ->lockForUpdate()
                ->first();

            if (! $user) {
                $candidates = collect([
                    $claims['preferred_username'] ?? null,
                    $claims['email'] ?? null,
                ])
                    ->filter(fn ($value) => is_string($value) && $value !== '')
                    ->map(fn (string $value) => strtolower($value))
                    ->unique()
                    ->values()
                    ->all();

                if ($candidates === []) {
                    return null;
                }

                $user = User::whereIn('email', $candidates)
                    ->lockForUpdate()
                    ->first();

                if (! $user || $user->entra_object_id !== null) {
                    return null;
                }

                $user->forceFill([
                    'entra_object_id' => $oid,
                    'entra_linked_at' => now(),
                ])->save();

                $linked = true;
            }

            if ($user->status !== 'active') {
                /*
                 * Un enlace no debe sobrevivir a una cuenta no activa.
                 */
                if ($linked) {
                    $user->forceFill([
                        'entra_object_id' => null,
                        'entra_linked_at' => null,
                    ])->save();
                }

                return null;
            }

            $audit->entraLogin($user, $linked, $ip);

            return $user;
        });
    }

    private function failure(string $code): RedirectResponse
    {
        return redirect()->away($this->frontendUrl('/sign-in?error='.$code));
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('services.entra.frontend_url'), '/').$path;
    }
}
