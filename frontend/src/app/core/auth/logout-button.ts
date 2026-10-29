
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  inject,
  signal,
} from '@angular/core';

import { Router } from '@angular/router';

import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import { AuthService } from './auth.service';

/*
|--------------------------------------------------------------------------
| YESIAM - LOGOUT BUTTON
|--------------------------------------------------------------------------
|
| FASE 19.3.14
|
| Responsabilidades:
|
| 1. Solicitar el cierre de sesión a Laravel.
| 2. Evitar solicitudes duplicadas.
| 3. Mostrar errores de forma segura.
| 4. Redirigir al login cuando Laravel
|    confirme que la sesión fue cerrada.
|
| SEGURIDAD:
|
| No basta con navegar al login.
| Laravel debe invalidar primero
| la sesión autenticada.
|
*/

@Component({
  selector: 'app-logout-button',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,

  template: `
    <div class="logout-container">

      <button
        type="button"
        class="logout-button"
        [disabled]="isLoggingOut()"
        (click)="onLogout()"
      >
        @if (isLoggingOut()) {
          Cerrando sesión...
        } @else {
          Cerrar sesión
        }
      </button>

      @if (errorMessage()) {
        <p class="error-message" role="alert">
          {{ errorMessage() }}
        </p>
      }

    </div>
  `,

  styles: [`
    :host {
      display: block;
      width: 100%;
    }

    .logout-container {
      width: 100%;
    }

    .logout-button {
      display: block;
      width: 100%;
      min-height: 42px;
      padding: 10px 14px;

      border: 1px solid #45536b;
      border-radius: 9px;

      background: transparent;
      color: #e1e8f4;

      font: inherit;
      font-size: 13px;
      font-weight: 600;

      cursor: pointer;
      transition:
        background 150ms,
        border-color 150ms;
    }

    .logout-button:hover:not(:disabled) {
      background: #26344d;
      border-color: #6981a9;
    }

    .logout-button:focus-visible {
      outline: 3px solid #7fa5ff;
      outline-offset: 3px;
    }

    .logout-button:disabled {
      opacity: 0.65;
      cursor: wait;
    }

    .error-message {
      margin: 10px 0 0;
      color: #ff9ba6;
      font-size: 12px;
      line-height: 1.5;
    }
  `],
})
export class LogoutButton {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  readonly isLoggingOut = signal(false);
  readonly errorMessage = signal<string | null>(null);

  onLogout(): void {
    if (this.isLoggingOut()) {
      return;
    }

    this.isLoggingOut.set(true);
    this.errorMessage.set(null);

    this.auth.logout()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.isLoggingOut.set(false);

          /*
           * Laravel ya confirmó el cierre.
           *
           * replaceUrl evita conservar
           * esta entrada del Dashboard
           * en el historial inmediato.
           */
          void this.router.navigateByUrl(
            '/sign-in',
            { replaceUrl: true }
          );
        },

        error: () => {
          this.isLoggingOut.set(false);

          /*
           * No afirmamos que la sesión
           * terminó si Laravel no confirmó
           * el cierre.
           */
          this.errorMessage.set(
            'No fue posible confirmar el cierre de sesión. Intenta nuevamente.'
          );
        },
      });
  }
}
