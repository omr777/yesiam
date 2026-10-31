import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';

import { RouterLink } from '@angular/router';

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
| YESIAM - COLA DE CONCESIÓN
|--------------------------------------------------------------------------
|
| FASE 21
|
| Paso 3 del flujo de acceso: provisionar.
|
| Solicitudes aprobadas sin concesión. Al
| conceder, Laravel vuelve a comprobar dentro
| de una transacción con bloqueos:
|
| - Solicitud aprobada y sin concesión previa.
| - Beneficiario y recurso activos.
| - Elegibilidad actual.
| - Ninguna concesión activa duplicada.
| - Que no sea una autoconcesión.
|
*/

@Component({
  selector: 'app-provisioning',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    RouterLink,
    ActionDialog,
    Pager,
  ],

  template: `
    <section class="page">

      <header class="page-header">
        <div>
          <h1>Concesiones</h1>
          <p>
            Solicitudes ya aprobadas por su responsable que esperan
            provisionamiento. Conceder crea el acceso efectivo.
          </p>
        </div>
      </header>

      <section class="panel" aria-labelledby="queue-title">
        <div class="panel-header">
          <h2 class="section-title" id="queue-title">Listas para conceder</h2>
          <span class="small muted">{{ total() }} en cola</span>
        </div>

        @if (isLoading()) {
          <div class="empty-state" role="status">Cargando cola de concesión...</div>
        } @else if (error()) {
          <div class="panel-body">
            <div class="notice error" role="alert">
              <strong>No fue posible consultar la cola.</strong>
              <p>{{ error() }}</p>
              <button type="button" class="btn" (click)="load(page())">Reintentar</button>
            </div>
          </div>
        } @else if (requests().length === 0) {
          <div class="empty-state">
            <strong>No hay solicitudes por conceder</strong>
            Las solicitudes aparecen aquí cuando su responsable las aprueba.
          </div>
        } @else {
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th scope="col">Beneficiario</th>
                  <th scope="col">Recurso</th>
                  <th scope="col">Aprobación</th>
                  <th scope="col"><span class="visually-hidden">Acciones</span></th>
                </tr>
              </thead>
              <tbody>
                @for (request of requests(); track request.id) {
                  <tr>
                    <td>
                      @if (request.requester) {
                        <a class="row-link" [routerLink]="['/identities', request.requester.id]">
                          <div class="cell-primary">{{ request.requester.name }}</div>
                          <div class="cell-secondary">{{ request.requester.email }}</div>
                        </a>
                      } @else {
                        <span class="muted">Identidad no disponible</span>
                      }
                    </td>
                    <td>
                      <div class="cell-primary">{{ request.resource?.name ?? 'Recurso' }}</div>
                      @if (request.resource) {
                        <div class="cell-secondary"><span class="code">{{ request.resource.code }}</span></div>
                      }
                    </td>
                    <td class="small approval-cell">
                      <div>{{ request.decided_by?.name ?? '—' }}, {{ formatDateTime(request.decided_at) }}</div>
                      <div class="cell-secondary">{{ request.decision_reason }}</div>
                    </td>
                    <td class="cell-actions">
                      <button
                        type="button"
                        class="btn btn-sm btn-primary"
                        (click)="ask(request)"
                      >
                        Conceder
                      </button>
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>

          <app-pager
            [page]="page()"
            [lastPage]="lastPage()"
            [total]="total()"
            [from]="from()"
            [to]="to()"
            (pageChange)="load($event)"
          />
        }
      </section>

      <app-action-dialog
        [open]="granting() !== null"
        title="Conceder acceso"
        [description]="grantDescription()"
        confirmLabel="Conceder acceso"
        busyLabel="Concediendo..."
        [busy]="isGranting()"
        [errorMessage]="grantError()"
        (confirmed)="confirmGrant()"
        (closed)="close()"
      />

    </section>
  `,

  styles: [`
    .approval-cell {
      max-width: 34ch;
    }
  `],
})
export class Provisioning {

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

  readonly granting = signal<AccessRequest | null>(null);
  readonly isGranting = signal(false);
  readonly grantError = signal<string | null>(null);

  readonly grantDescription = computed(() => {
    const request = this.granting();

    return request
      ? `${request.requester?.name ?? 'La identidad'} obtendrá acceso efectivo a ${request.resource?.name ?? 'el recurso'}. YESIAM verificará de nuevo su elegibilidad antes de concederlo.`
      : '';
  });

  constructor() {
    this.load(1);
  }

  load(page: number): void {
    this.isLoading.set(true);
    this.error.set(null);

    this.accessService.readyToGrant(page)
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
            describeApiError(error, { action: 'consultar la cola de concesión' }).message
          );
          this.isLoading.set(false);
        },
      });
  }

  ask(request: AccessRequest): void {
    this.grantError.set(null);
    this.granting.set(request);
  }

  close(): void {
    if (!this.isGranting()) {
      this.granting.set(null);
    }
  }

  confirmGrant(): void {
    const request = this.granting();

    if (!request || this.isGranting()) {
      return;
    }

    this.isGranting.set(true);
    this.grantError.set(null);

    this.accessService.grant(request.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.isGranting.set(false);
          this.granting.set(null);
          this.toast.success(
            `Acceso a ${request.resource?.name ?? 'el recurso'} concedido a ${request.requester?.name ?? 'la identidad'}.`
          );
          this.load(1);
        },
        error: (error: unknown) => {
          this.isGranting.set(false);

          const apiError = describeApiError(error, {
            action: 'conceder accesos',
            forbidden: 'No puedes conceder esta solicitud. Puede que ya no esté aprobada, que el recurso o la identidad estén inactivos, o que sea tu propia solicitud.',
          });

          this.grantError.set(
            apiError.status === 422
              ? 'El beneficiario ya no cumple las condiciones de elegibilidad para este recurso.'
              : apiError.message
          );
        },
      });
  }
}
