import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  effect,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';

/*
|--------------------------------------------------------------------------
| YESIAM - DIÁLOGO DE ACCIÓN SENSIBLE
|--------------------------------------------------------------------------
|
| FASE 21
|
| Toda acción que cambia accesos pasa por aquí:
| revocar, rechazar, aprobar, desactivar.
|
| Puede exigir:
|
| - Un motivo (mínimo de caracteres, igual que
|   la validación de Laravel). El motivo queda
|   registrado por el backend.
| - Escribir una palabra de confirmación para
|   acciones irreversibles (Leaver).
|
| Usa el elemento nativo <dialog>, que ya
| gestiona el foco y la tecla Escape.
|
*/

@Component({
  selector: 'app-action-dialog',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,

  template: `
    <dialog
      #dialog
      class="action-dialog"
      [attr.aria-labelledby]="titleId"
      (cancel)="onCancel($event)"
    >
      <form method="dialog" (submit)="onSubmit($event)">

        <header class="dialog-header" [class.is-danger]="tone() === 'danger'">
          <h2 [id]="titleId">{{ title() }}</h2>
        </header>

        <div class="dialog-body">
          <p class="dialog-description">{{ description() }}</p>

          <ng-content />

          @if (reasonLabel()) {
            <div class="field">
              <label [for]="reasonId">{{ reasonLabel() }}</label>

              <textarea
                [id]="reasonId"
                name="reason"
                maxlength="1000"
                [value]="reason()"
                [attr.aria-invalid]="showReasonError()"
                (input)="reason.set($any($event.target).value)"
              ></textarea>

              <span class="field-help">
                Mínimo {{ reasonMinLength() }} caracteres.
                Quedará registrado en la auditoría.
              </span>

              @if (showReasonError()) {
                <span class="field-error">
                  Escribe al menos {{ reasonMinLength() }} caracteres.
                </span>
              }
            </div>
          }

          @if (confirmWord()) {
            <div class="field">
              <label [for]="confirmId">
                Escribe <strong>{{ confirmWord() }}</strong> para confirmar
              </label>

              <input
                [id]="confirmId"
                name="confirm"
                autocomplete="off"
                [value]="typedWord()"
                (input)="typedWord.set($any($event.target).value)"
              />
            </div>
          }

          @if (errorMessage()) {
            <div class="notice error" role="alert">
              <strong>No se completó la acción.</strong>
              <p>{{ errorMessage() }}</p>
            </div>
          }
        </div>

        <footer class="dialog-footer">
          <button
            type="button"
            class="btn"
            [disabled]="busy()"
            (click)="close()"
          >
            Cancelar
          </button>

          <button
            type="submit"
            class="btn"
            [class.btn-primary]="tone() !== 'danger'"
            [class.btn-danger]="tone() === 'danger'"
            [disabled]="busy() || !canConfirm()"
          >
            {{ busy() ? busyLabel() : confirmLabel() }}
          </button>
        </footer>

      </form>
    </dialog>
  `,

  styles: [`
    .action-dialog {
      width: min(520px, calc(100vw - 32px));
      padding: 0;
      border: 0;
      border-radius: var(--radius-panel);
      background: var(--surface);
      color: var(--text);
      box-shadow: var(--shadow-dialog);
    }

    .action-dialog::backdrop {
      background: rgba(19, 35, 43, 0.45);
    }

    .dialog-header {
      padding: 18px 22px 0;
    }

    .dialog-header h2 {
      font-size: 18px;
      font-weight: 600;
    }

    .dialog-header.is-danger h2 {
      color: var(--danger);
    }

    .dialog-body {
      display: flex;
      flex-direction: column;
      gap: 16px;
      padding: 12px 22px 20px;
    }

    .dialog-description {
      color: var(--text-muted);
    }

    .dialog-footer {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      padding: 14px 22px;
      border-top: 1px solid var(--line);
      background: var(--surface-sunken);
      border-radius: 0 0 var(--radius-panel) var(--radius-panel);
    }
  `],
})
export class ActionDialog {

  private static nextId = 1;

  private readonly uid = ActionDialog.nextId++;

  readonly titleId = `action-dialog-title-${this.uid}`;
  readonly reasonId = `action-dialog-reason-${this.uid}`;
  readonly confirmId = `action-dialog-confirm-${this.uid}`;

  /*
   * Entradas.
   */
  readonly open = input(false);
  readonly title = input.required<string>();
  readonly description = input('');
  readonly confirmLabel = input('Confirmar');
  readonly busyLabel = input('Procesando...');
  readonly tone = input<'primary' | 'danger'>('primary');
  readonly reasonLabel = input<string | null>(null);
  readonly reasonMinLength = input(10);
  readonly confirmWord = input<string | null>(null);
  readonly busy = input(false);
  readonly errorMessage = input<string | null>(null);

  /*
   * Salidas.
   */
  readonly confirmed = output<string>();
  readonly closed = output<void>();

  /*
   * Estado interno.
   */
  readonly reason = signal('');
  readonly typedWord = signal('');
  readonly attempted = signal(false);

  private readonly dialog =
    viewChild.required<ElementRef<HTMLDialogElement>>('dialog');

  readonly reasonValid = computed(() =>
    !this.reasonLabel()
    || this.reason().trim().length >= this.reasonMinLength()
  );

  readonly showReasonError = computed(() =>
    this.attempted() && !this.reasonValid()
  );

  readonly canConfirm = computed(() => {
    const word = this.confirmWord();

    return word === null || this.typedWord().trim() === word;
  });

  constructor() {
    effect(() => {
      const element = this.dialog().nativeElement;

      if (this.open()) {
        this.reason.set('');
        this.typedWord.set('');
        this.attempted.set(false);

        if (!element.open) {
          /*
           * showModal no existe en algunos
           * entornos de prueba.
           */
          if (typeof element.showModal === 'function') {
            element.showModal();
          } else {
            element.setAttribute('open', '');
          }
        }
      } else if (element.open || element.hasAttribute('open')) {
        if (typeof element.close === 'function') {
          element.close();
        }

        element.removeAttribute('open');
      }
    });
  }

  onSubmit(event: Event): void {
    event.preventDefault();

    this.attempted.set(true);

    if (this.busy() || !this.reasonValid() || !this.canConfirm()) {
      return;
    }

    this.confirmed.emit(this.reason().trim());
  }

  onCancel(event: Event): void {
    event.preventDefault();
    this.close();
  }

  close(): void {
    if (this.busy()) {
      return;
    }

    this.closed.emit();
  }
}
