
import {
  ChangeDetectionStrategy,
  Component,
  inject,
  signal,
} from '@angular/core';

import {
  FormBuilder,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';

import {
  HttpErrorResponse,
} from '@angular/common/http';

import { Router } from '@angular/router';

import {
  AuthService,
} from '../../core/auth/auth.service';

/*
|--------------------------------------------------------------------------
| YESIAM - LOGIN COMPONENT
|--------------------------------------------------------------------------
|
| FASE 19.3.9
|
| Responsabilidades:
|
| - Mostrar el formulario de autenticación.
| - Validar correo y contraseña.
| - Utilizar AuthService para iniciar sesión.
| - Mostrar errores sin exponer datos sensibles.
| - Redirigir al Dashboard cuando Laravel
|   confirme la sesión.
|
| La autenticación real corresponde al backend.
|
*/

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [ReactiveFormsModule],
  changeDetection: ChangeDetectionStrategy.OnPush,

  template: `
    <main class="login-page">
      <section class="login-card" aria-labelledby="login-title">

        <header class="login-header">
          <div class="brand">
            <div class="brand-symbol" aria-hidden="true">
              Y
            </div>

            <div class="brand-text">
              <strong>YESIAM</strong>
              <span>Identity & Access Management</span>
            </div>
          </div>

          <div class="welcome">
            <span class="eyebrow">ACCESO SEGURO</span>

            <h1 id="login-title">
              Iniciar sesión
            </h1>

            <p>
              Ingresa tus credenciales para acceder
              a la plataforma de administración
              de identidades.
            </p>
          </div>
        </header>

        <form
          class="login-form"
          [formGroup]="form"
          (ngSubmit)="onSubmit()"
          novalidate
        >
          <div class="form-field">
            <label for="email">
              Correo electrónico
            </label>

            <input
              id="email"
              type="email"
              formControlName="email"
              placeholder="usuario@empresa.com"
              autocomplete="username"
              spellcheck="false"
              [attr.aria-invalid]="
                form.controls.email.invalid &&
                form.controls.email.touched
              "
            />

            @if (
              form.controls.email.touched &&
              form.controls.email.hasError('required')
            ) {
              <small class="field-error">
                Ingresa tu correo electrónico.
              </small>
            }

            @if (
              form.controls.email.touched &&
              form.controls.email.hasError('email')
            ) {
              <small class="field-error">
                Ingresa un correo válido.
              </small>
            }
          </div>

          <div class="form-field">
            <label for="password">
              Contraseña
            </label>

            <input
              id="password"
              type="password"
              formControlName="password"
              placeholder="Ingresa tu contraseña"
              autocomplete="current-password"
              [attr.aria-invalid]="
                form.controls.password.invalid &&
                form.controls.password.touched
              "
            />

            @if (
              form.controls.password.touched &&
              form.controls.password.hasError('required')
            ) {
              <small class="field-error">
                Ingresa tu contraseña.
              </small>
            }
          </div>

          @if (errorMessage()) {
            <div class="alert-error" role="alert">
              {{ errorMessage() }}
            </div>
          }

          <button
            class="submit-button"
            type="submit"
            [disabled]="isSubmitting()"
          >
            @if (isSubmitting()) {
              Iniciando sesión...
            } @else {
              Iniciar sesión
            }
          </button>
        </form>

        <footer class="login-footer">
          <span class="status-dot" aria-hidden="true"></span>
          Acceso protegido mediante Laravel Sanctum
        </footer>
      </section>

      <p class="page-footer">
        YESIAM · Your Expert System,
        Identity, Access and Monitoring
      </p>
    </main>
  `,

  styles: [`
    :host {
      display: block;
      min-height: 100dvh;
      font-family: inherit;
    }

    * {
      box-sizing: border-box;
    }

    .login-page {
      min-height: 100dvh;
      padding: 32px 20px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      gap: 24px;
      color: #e8edf7;
      background:
        radial-gradient(
          ellipse at 50% 0%,
          #1d3154 0%,
          transparent 55%
        ),
        #0b1220;
    }

    .login-card {
      width: 100%;
      max-width: 440px;
      padding: 36px;
      background: #111c2e;
      border: 1px solid #293750;
      border-radius: 18px;
      box-shadow: 0 24px 70px #00000040;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 42px;
    }

    .brand-symbol {
      display: grid;
      place-items: center;
      width: 43px;
      height: 43px;
      border-radius: 12px;
      color: #ffffff;
      font-weight: 800;
      font-size: 22px;
      background: #326be6;
    }

    .brand-text {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .brand-text strong {
      font-size: 19px;
      letter-spacing: 1px;
    }

    .brand-text span {
      font-size: 11px;
      color: #9caac1;
    }

    .eyebrow {
      color: #8eafff;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 1.6px;
    }

    h1 {
      margin: 12px 0;
      font-size: 29px;
      font-weight: 700;
      letter-spacing: -0.7px;
    }

    .welcome p {
      margin: 0;
      color: #a5b2c8;
      line-height: 1.6;
      font-size: 14px;
    }

    .login-form {
      display: flex;
      flex-direction: column;
      gap: 22px;
      margin-top: 34px;
    }

    .form-field {
      display: flex;
      flex-direction: column;
      gap: 9px;
    }

    label {
      color: #dce5f4;
      font-size: 13px;
      font-weight: 600;
    }

    input {
      width: 100%;
      height: 46px;
      padding: 0 14px;
      border-radius: 9px;
      border: 1px solid #35445d;
      background: #0c1524;
      color: #ffffff;
      font: inherit;
      font-size: 14px;
      outline: none;
      transition: border-color 150ms;
    }

    input::placeholder {
      color: #71809a;
    }

    input:focus {
      border-color: #6494ff;
      box-shadow: 0 0 0 3px #326be626;
    }

    input[aria-invalid="true"] {
      border-color: #e57a88;
    }

    .field-error {
      color: #ff9ba6;
      font-size: 12px;
    }

    .alert-error {
      padding: 12px 14px;
      border: 1px solid #823d50;
      border-radius: 9px;
      background: #471f2c;
      color: #ffd1d7;
      font-size: 13px;
      line-height: 1.5;
    }

    .submit-button {
      width: 100%;
      height: 46px;
      border: 0;
      border-radius: 9px;
      color: #ffffff;
      background: #326be6;
      font: inherit;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      transition: background 150ms;
    }

    .submit-button:hover:not(:disabled) {
      background: #4b80f2;
    }

    .submit-button:disabled {
      opacity: 0.7;
      cursor: wait;
    }

    .submit-button:focus-visible {
      outline: 3px solid #a6bfff;
      outline-offset: 3px;
    }

    .login-footer {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 8px;
      margin-top: 30px;
      padding-top: 22px;
      border-top: 1px solid #293750;
      color: #95a6bf;
      font-size: 11px;
      text-align: center;
    }

    .status-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #42c999;
    }

    .page-footer {
      max-width: 440px;
      margin: 0;
      color: #7887a1;
      text-align: center;
      font-size: 11px;
      line-height: 1.6;
    }

    @media (max-width: 480px) {
      .login-card {
        padding: 28px 22px;
      }

      h1 {
        font-size: 26px;
      }
    }
  `],
})
export class Login {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  readonly isSubmitting = signal(false);
  readonly errorMessage = signal<string | null>(null);

  readonly form = this.fb.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]],
  });

  onSubmit(): void {
    if (this.isSubmitting()) {
      return;
    }

    this.errorMessage.set(null);

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.isSubmitting.set(true);

    this.auth.login(this.form.getRawValue()).subscribe({
      next: () => {
        this.isSubmitting.set(false);

        // Eliminamos la contraseña del formulario.
        this.form.controls.password.reset();

        // El Dashboard se encuentra en la ruta raíz.
        void this.router.navigateByUrl('/');
      },

      error: (error: unknown) => {
        this.isSubmitting.set(false);

        if (!(error instanceof HttpErrorResponse)) {
          this.errorMessage.set(
            'Ocurrió un error inesperado. Intenta nuevamente.'
          );
          return;
        }

        switch (error.status) {
          case 0:
            this.errorMessage.set(
              'No fue posible conectar con el servidor.'
            );
            break;

          case 401:
          case 403:
          case 422:
            this.errorMessage.set(
              'Credenciales inválidas o cuenta no disponible.'
            );
            break;

          case 419:
            this.errorMessage.set(
              'La sesión de seguridad expiró. Intenta nuevamente.'
            );
            break;

          case 429:
            this.errorMessage.set(
              'Demasiados intentos. Intenta más tarde.'
            );
            break;

          default:
            this.errorMessage.set(
              'No fue posible iniciar sesión. Intenta nuevamente.'
            );
        }
      },
    });
  }
}
