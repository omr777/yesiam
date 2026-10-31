import {
  ChangeDetectionStrategy,
  Component,
  inject,
} from '@angular/core';

import { ToastService } from './toast.service';

/*
|--------------------------------------------------------------------------
| YESIAM - SALIDA DE NOTIFICACIONES
|--------------------------------------------------------------------------
*/

@Component({
  selector: 'app-toast-outlet',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,

  template: `
    <div class="toasts" role="status" aria-live="polite">
      @for (toast of toastService.toasts(); track toast.id) {
        <div class="toast">
          <span class="toast-mark" aria-hidden="true"></span>

          <span class="toast-message">{{ toast.message }}</span>

          <button
            type="button"
            class="toast-close"
            aria-label="Cerrar aviso"
            (click)="toastService.dismiss(toast.id)"
          >
            ×
          </button>
        </div>
      }
    </div>
  `,

  styles: [`
    .toasts {
      position: fixed;
      right: 20px;
      bottom: 20px;
      z-index: 60;
      display: flex;
      flex-direction: column;
      gap: 8px;
      max-width: min(380px, calc(100vw - 40px));
    }

    .toast {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 11px 12px 11px 14px;
      border-radius: 8px;
      background: var(--ink);
      color: var(--text-on-ink);
      box-shadow: var(--shadow-dialog);
      font-size: 14px;
      animation: toast-in 180ms ease-out;
    }

    .toast-mark {
      flex: 0 0 8px;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #5eead4;
    }

    .toast-message {
      flex: 1;
    }

    .toast-close {
      border: 0;
      background: transparent;
      color: var(--text-on-ink-muted);
      font-size: 18px;
      line-height: 1;
      cursor: pointer;
    }

    @keyframes toast-in {
      from {
        opacity: 0;
        transform: translateY(6px);
      }
    }
  `],
})
export class ToastOutlet {
  readonly toastService = inject(ToastService);
}
