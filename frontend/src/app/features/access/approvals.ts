import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';

import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import { describeApiError } from '../../core/http/api-error';

import { formatDateTime } from '../../shared/labels';
import { ActionDialog } from '../../shared/ui/action-dialog';
import { Pager } from '../../shared/ui/pager';
import { ToastService } from '../../shared/ui/toast.service';

import {
  AccessRequest,
  AccessService,
} from './access.service';

/*
|--------------------------------------------------------------------------
| YESIAM - BANDEJA DE APROBACIONES
|--------------------------------------------------------------------------
|
| FASE 21
|
| Paso 2 del flujo de acceso.
|
| GET /api/access-requests/assigned solo
| devuelve solicitudes pendientes de recursos
| activos asignados a este responsable, de
| solicitantes activos y nunca propias.
|
| Aprobar NO concede el acceso: lo deja listo
| para que un administrador IAM lo provisione.
|
*/

type Decision = 'approve' | 'reject';

@Component({
  selector: 'app-approvals',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ActionDialog,
    Pager,
  ],

  template: `
    <section class="page">

      <header class="page-header">
        <div>
          <h1>Aprobaciones</h1>
          <p>
            Solicitudes pendientes de los recursos de los que eres
            responsable. Tu decisión y su motivo quedan auditados.
          </p>
        </div>
      </header>

      @if (isLoading()) {
        <div class="panel empty-state" role="status">Cargando solicitudes...</div>
      } @else if (error()) {
        <div class="notice error" role="alert">
          <strong>No fue posible consultar la bandeja.</strong>
          <p>{{ error() }}</p>
          <button type="button" class="btn" (click)="load(page())">Reintentar</button>
        </div>
      } @else if (requests().length === 0) {
        <div class="panel empty-state">
          <strong>No tienes solicitudes pendientes</strong>
          Cuando alguien solicite uno de tus recursos, aparecerá aquí.
        </div>
      } @else {
        <ul class="request-list" aria-label="Solicitudes pendientes">
          @for (request of requests(); track request.id) {
            <li class="panel request-card">
              <div class="request-main">
                <div class="request-who">
                  <strong>{{ request.requester?.name ?? 'Identidad no disponible' }}</strong>
                  <span class="muted small">{{ request.requester?.email }}</span>
                </div>

                <div class="request-what">
                  solicita
                  <strong>{{ request.resource?.name ?? 'Recurso' }}</strong>
                  @if (request.resource) {
                    <span class="code">{{ request.resource.code }}</span>
                  }
                </div>

                <blockquote class="request-justification">
                  {{ request.justification }}
                </blockquote>

                <span class="small muted">Enviada {{ formatDateTime(request.created_at) }}</span>
              </div>

              <div class="request-actions">
                <button
                  type="button"
                  class="btn btn-primary"
                  (click)="open(request, 'approve')"
                >
                  Aprobar
                </button>

                <button
                  type="button"
                  class="btn btn-danger-outline"
                  (click)="open(request, 'reject')"
                >
                  Rechazar
                </button>
              </div>
            </li>
          }
        </ul>

        <div class="panel pager-panel">
          <app-pager
            [page]="page()"
            [lastPage]="lastPage()"
            [total]="total()"
            [from]="from()"
            [to]="to()"
            (pageChange)="load($event)"
          />
        </div>
      }

      <app-action-dialog
        [open]="active() !== null"
        [title]="dialogTitle()"
        [description]="dialogDescription()"
        [confirmLabel]="decision() === 'approve' ? 'Aprobar solicitud' : 'Rechazar solicitud'"
        [busyLabel]="decision() === 'approve' ? 'Aprobando...' : 'Rechazando...'"
        [tone]="decision() === 'approve' ? 'primary' : 'danger'"
        reasonLabel="Motivo de la decisión"
        [busy]="isDeciding()"
        [errorMessage]="decisionError()"
        (confirmed)="decide($event)"
        (closed)="close()"
      />

    </section>
  `,

  styles: [`
    .request-list {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .request-card {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 20px;
      padding: 18px 20px;
    }

    .request-main {
      display: flex;
      flex-direction: column;
      gap: 6px;
      min-width: 0;
    }

    .request-who {
      display: flex;
      flex-wrap: wrap;
      align-items: baseline;
      gap: 8px;
    }

    .request-what {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 6px;
      color: var(--text-muted);
    }

    .request-what strong {
      color: var(--text);
    }

    .request-justification {
      max-width: 70ch;
      margin: 4px 0;
      padding: 8px 12px;
      border-left: 3px solid var(--line-strong);
      background: var(--surface-sunken);
      border-radius: 0 var(--radius-control) var(--radius-control) 0;
      font-size: 14px;
    }

    .request-actions {
      display: flex;
      flex-direction: column;
      gap: 8px;
      flex: 0 0 auto;
    }

    .pager-panel {
      margin-top: 12px;
    }

    .pager-panel:empty {
      display: none;
    }

    @media (max-width: 640px) {
      .request-card {
        flex-direction: column;
      }

      .request-actions {
        flex-direction: row;
        width: 100%;
      }

      .request-actions .btn {
        flex: 1;
      }
    }
  `],
})
export class Approvals {

  private readonly accessService = inject(AccessService);
  private readonly toast = inject(ToastService);
  private readonly destroyRef = inject(DestroyRef);

  readonly formatDateTime = formatDateTime;

  readonly requests = signal<AccessRequest[]>([]);
  readonly isLoading = signal(false);
  readonly error = signal<string | null>(null);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly total = signal(0);
  readonly from = signal<number | null>(null);
  readonly to = signal<number | null>(null);

  readonly active = signal<AccessRequest | null>(null);
  readonly decision = signal<Decision>('approve');
  readonly isDeciding = signal(false);
  readonly decisionError = signal<string | null>(null);

  readonly dialogTitle = computed(() =>
    this.decision() === 'approve' ? 'Aprobar solicitud' : 'Rechazar solicitud'
  );

  readonly dialogDescription = computed(() => {
    const request = this.active();

    if (!request) {
      return '';
    }

    const who = request.requester?.name ?? 'la identidad';
    const what = request.resource?.name ?? 'el recurso';

    return this.decision() === 'approve'
      ? `${who} quedará en la cola de concesión para ${what}. Un administrador IAM concederá el acceso.`
      : `${who} no recibirá acceso a ${what}. Verá tu motivo en sus solicitudes.`;
  });

  constructor() {
    this.load(1);
  }

  load(page: number): void {
    this.isLoading.set(true);
    this.error.set(null);

    this.accessService.assigned(page)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.requests.set(response.data);
          this.page.set(response.current_page);
          this.lastPage.set(response.last_page);
          this.total.set(response.total);
          this.from.set(response.from);
          this.to.set(response.to);
          this.isLoading.set(false);
        },
        error: (error: unknown) => {
          this.requests.set([]);
          this.error.set(
            describeApiError(error, { action: 'consultar la bandeja de aprobación' }).message
          );
          this.isLoading.set(false);
        },
      });
  }

  open(request: AccessRequest, decision: Decision): void {
    this.decision.set(decision);
    this.decisionError.set(null);
    this.active.set(request);
  }

  close(): void {
    if (!this.isDeciding()) {
      this.active.set(null);
    }
  }

  decide(reason: string): void {
    const request = this.active();

    if (!request || this.isDeciding()) {
      return;
    }

    this.isDeciding.set(true);
    this.decisionError.set(null);

    const call = this.decision() === 'approve'
      ? this.accessService.approve(request.id, reason)
      : this.accessService.reject(request.id, reason);

    const approved = this.decision() === 'approve';

    call
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.isDeciding.set(false);
          this.active.set(null);
          this.toast.success(approved ? 'Solicitud aprobada.' : 'Solicitud rechazada.');
          this.load(1);
        },
        error: (error: unknown) => {
          this.isDeciding.set(false);

          const apiError = describeApiError(error, {
            action: 'decidir esta solicitud',
            forbidden: 'Ya no puedes decidir esta solicitud: pudo haber sido decidida por otro responsable o tu asignación cambió.',
          });

          this.decisionError.set(
            apiError.status === 422
              ? 'El motivo debe tener entre 10 y 1000 caracteres.'
              : apiError.message
          );
        },
      });
  }
}
