import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';

import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import {
  describeApiError,
  firstFieldError,
} from '../../core/http/api-error';

import { formatDateTime } from '../../shared/labels';
import { Pager } from '../../shared/ui/pager';
import { StatusBadge } from '../../shared/ui/status-badge';
import { ToastService } from '../../shared/ui/toast.service';

import {
  Resource,
  ResourceService,
} from '../resources/resource.service';

import {
  AccessRequest,
  AccessService,
} from './access.service';

/*
|--------------------------------------------------------------------------
| YESIAM - SOLICITAR ACCESO
|--------------------------------------------------------------------------
|
| FASE 21
|
| Paso 1 del flujo de acceso. La persona elige
| un recurso y justifica la necesidad.
|
| Laravel verifica elegibilidad departamental,
| que el recurso siga activo y que no exista
| otra solicitud pendiente igual. Sus mensajes
| para esos casos están en español y se
| muestran tal cual.
|
*/

const MIN_JUSTIFICATION = 10;

@Component({
  selector: 'app-request-access',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    StatusBadge,
    Pager,
  ],

  template: `
    <section class="page">

      <header class="page-header">
        <div>
          <h1>Solicitar acceso</h1>
          <p>
            Tu solicitud pasa por el responsable del recurso y después
            por un administrador IAM, que concede el acceso.
          </p>
        </div>
      </header>

      <div class="stack">

        <section class="panel" aria-labelledby="new-request-title">
          <div class="panel-header">
            <h2 class="section-title" id="new-request-title">Nueva solicitud</h2>
          </div>

          <form class="panel-body stack" novalidate (submit)="submit($event)">

            <div class="field">
              <label for="request-resource">Recurso</label>
              <!--
              La selección se marca en cada <option> porque
              las opciones llegan después de la consulta: así
              funciona también la preselección desde el catálogo.
              -->
              <select
                id="request-resource"
                [attr.aria-invalid]="resourceError() ? true : null"
                (change)="selectResource($any($event.target).value)"
              >
                <option value="" [selected]="selectedResource() === null">
                  {{ resourcesLoading() ? 'Cargando recursos...' : 'Selecciona un recurso' }}
                </option>
                @for (resource of resources(); track resource.id) {
                  <option
                    [value]="resource.id"
                    [selected]="resource.id === selectedResource()"
                  >
                    {{ resource.name }} ({{ resource.code }})
                  </option>
                }
              </select>
              @if (selectedDetails(); as details) {
                @if (details.description) {
                  <span class="field-help">{{ details.description }}</span>
                }
              }
              @if (resourceError()) {
                <span class="field-error">{{ resourceError() }}</span>
              }
            </div>

            <div class="field">
              <label for="request-justification">Justificación</label>
              <textarea
                id="request-justification"
                maxlength="1000"
                [value]="justification()"
                [attr.aria-invalid]="justificationError() ? true : null"
                (input)="justification.set($any($event.target).value)"
              ></textarea>
              <span class="field-help">
                Explica para qué lo necesitas. Mínimo {{ minJustification }} caracteres
                ({{ justification().trim().length }} escritos).
              </span>
              @if (justificationError()) {
                <span class="field-error">{{ justificationError() }}</span>
              }
            </div>

            @if (submitError()) {
              <div class="notice error" role="alert">
                <strong>No se envió la solicitud.</strong>
                <p>{{ submitError() }}</p>
              </div>
            }

            <div class="actions">
              <button
                type="submit"
                class="btn btn-primary"
                [disabled]="isSubmitting()"
              >
                {{ isSubmitting() ? 'Enviando...' : 'Enviar solicitud' }}
              </button>
            </div>
          </form>
        </section>

        <section class="panel" aria-labelledby="my-requests-title">
          <div class="panel-header">
            <h2 class="section-title" id="my-requests-title">Mis solicitudes</h2>
          </div>

          @if (requestsLoading()) {
            <div class="empty-state" role="status">Cargando solicitudes...</div>
          } @else if (requestsError()) {
            <div class="panel-body">
              <div class="notice error" role="alert">
                <strong>No fue posible consultar tus solicitudes.</strong>
                <p>{{ requestsError() }}</p>
                <button type="button" class="btn" (click)="loadRequests(page())">Reintentar</button>
              </div>
            </div>
          } @else if (requests().length === 0) {
            <div class="empty-state">
              <strong>Aún no has solicitado accesos</strong>
              Las solicitudes que envíes aparecerán aquí con su decisión.
            </div>
          } @else {
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th scope="col">Recurso</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Enviada</th>
                    <th scope="col">Decisión</th>
                  </tr>
                </thead>
                <tbody>
                  @for (request of requests(); track request.id) {
                    <tr>
                      <td>
                        <div class="cell-primary">{{ request.resource?.name ?? 'Recurso no disponible' }}</div>
                        <div class="cell-secondary justification">{{ request.justification }}</div>
                      </td>
                      <td><app-status-badge kind="request" [value]="request.status" /></td>
                      <td class="small">{{ formatDateTime(request.created_at) }}</td>
                      <td class="small">
                        @if (request.status === 'pending') {
                          <span class="muted">En revisión del responsable</span>
                        } @else {
                          <div>{{ request.decision_reason }}</div>
                          <div class="cell-secondary">{{ formatDateTime(request.decided_at) }}</div>
                        }
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
              (pageChange)="loadRequests($event)"
            />
          }
        </section>

      </div>
    </section>
  `,

  styles: [`
    .justification {
      max-width: 52ch;
    }
  `],
})
export class RequestAccess {

  private readonly accessService = inject(AccessService);
  private readonly resourceService = inject(ResourceService);
  private readonly toast = inject(ToastService);
  private readonly destroyRef = inject(DestroyRef);

  /*
   * ?resource=ID desde el catálogo.
   */
  readonly resource = input<string | undefined>(undefined);

  readonly minJustification = MIN_JUSTIFICATION;
  readonly formatDateTime = formatDateTime;

  readonly resources = signal<Resource[]>([]);
  readonly resourcesLoading = signal(false);
  readonly selectedResource = signal<number | null>(null);
  readonly justification = signal('');

  readonly selectedDetails = computed(() =>
    this.resources().find((item) => item.id === this.selectedResource()) ?? null
  );

  readonly attempted = signal(false);
  readonly isSubmitting = signal(false);
  readonly submitError = signal<string | null>(null);
  readonly serverResourceError = signal<string | null>(null);
  readonly serverJustificationError = signal<string | null>(null);

  readonly resourceError = computed(() => {
    if (this.serverResourceError()) {
      return this.serverResourceError();
    }

    return this.attempted() && this.selectedResource() === null
      ? 'Selecciona el recurso que necesitas.'
      : null;
  });

  readonly justificationError = computed(() => {
    if (this.serverJustificationError()) {
      return this.serverJustificationError();
    }

    return this.attempted() && this.justification().trim().length < MIN_JUSTIFICATION
      ? `Escribe al menos ${MIN_JUSTIFICATION} caracteres.`
      : null;
  });

  readonly requests = signal<AccessRequest[]>([]);
  readonly requestsLoading = signal(false);
  readonly requestsError = signal<string | null>(null);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly total = signal(0);
  readonly from = signal<number | null>(null);
  readonly to = signal<number | null>(null);

  constructor() {
    this.loadResources();
    this.loadRequests(1);

    effect(() => {
      const preselected = Number(this.resource());

      untracked(() => {
        if (Number.isInteger(preselected) && preselected > 0) {
          this.selectedResource.set(preselected);
        }
      });
    });
  }

  selectResource(value: string): void {
    this.selectedResource.set(value ? Number(value) : null);
    this.serverResourceError.set(null);
  }

  private loadResources(): void {
    this.resourcesLoading.set(true);

    this.resourceService.list()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (resources) => {
          this.resources.set(resources.filter((item) => item.is_active));
          this.resourcesLoading.set(false);
        },
        error: (error: unknown) => {
          this.resources.set([]);
          this.resourcesLoading.set(false);
          this.submitError.set(
            describeApiError(error, { action: 'consultar recursos' }).message
          );
        },
      });
  }

  loadRequests(page: number): void {
    this.requestsLoading.set(true);
    this.requestsError.set(null);

    this.accessService.myRequests(page)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.requests.set(response.data);
          this.page.set(response.current_page);
          this.lastPage.set(response.last_page);
          this.total.set(response.total);
          this.from.set(response.from);
          this.to.set(response.to);
          this.requestsLoading.set(false);
        },
        error: (error: unknown) => {
          this.requests.set([]);
          this.requestsError.set(
            describeApiError(error, { action: 'consultar solicitudes' }).message
          );
          this.requestsLoading.set(false);
        },
      });
  }

  submit(event: Event): void {
    event.preventDefault();

    if (this.isSubmitting()) {
      return;
    }

    this.attempted.set(true);
    this.submitError.set(null);
    this.serverResourceError.set(null);
    this.serverJustificationError.set(null);

    const resourceId = this.selectedResource();
    const justification = this.justification().trim();

    if (resourceId === null || justification.length < MIN_JUSTIFICATION) {
      return;
    }

    this.isSubmitting.set(true);

    this.accessService.requestAccess(resourceId, justification)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.isSubmitting.set(false);
          this.attempted.set(false);
          this.selectedResource.set(null);
          this.justification.set('');
          this.toast.success(
            `Solicitud enviada para ${response.data.resource?.name ?? 'el recurso'}.`
          );
          this.loadRequests(1);
        },
        error: (error: unknown) => {
          this.isSubmitting.set(false);

          const apiError = describeApiError(error, {
            action: 'solicitar accesos',
          });

          if (apiError.status === 422) {
            /*
             * Los mensajes de resource_id son reglas
             * de YESIAM redactadas en español
             * (elegibilidad, solicitud duplicada).
             * La validación genérica de Laravel llega
             * en inglés ("The selected...") y se
             * sustituye por un texto propio.
             */
            const resourceMessage = firstFieldError(apiError, 'resource_id');

            this.serverResourceError.set(
              resourceMessage === null
                ? null
                : resourceMessage.startsWith('The ')
                  ? 'El recurso ya no está disponible.'
                  : resourceMessage
            );

            this.serverJustificationError.set(
              firstFieldError(apiError, 'justification')
                ? `La justificación debe tener entre ${MIN_JUSTIFICATION} y 1000 caracteres.`
                : null
            );
          }

          this.submitError.set(apiError.message);
        },
      });
  }
}
