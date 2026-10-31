import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  inject,
  signal,
} from '@angular/core';

import { RouterLink } from '@angular/router';

import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import { describeApiError } from '../../core/http/api-error';

import {
  AUDIT_ACTION_LABELS,
  AUDIT_ENTITY_LABELS,
  auditActionLabel,
  auditEntityLabel,
  formatDateTime,
} from '../../shared/labels';

import { Pager } from '../../shared/ui/pager';

import {
  AuditLogEntry,
  AuditService,
} from './audit.service';

/*
|--------------------------------------------------------------------------
| YESIAM - BITÁCORA DE AUDITORÍA
|--------------------------------------------------------------------------
|
| FASE 21
|
| Solo lectura (audit.view). Cada evento
| responde: qué pasó, quién lo hizo, sobre
| qué entidad y cuándo.
|
| Los metadatos se muestran tal como los
| registró Laravel, que nunca guarda
| contraseñas ni secretos.
|
*/

@Component({
  selector: 'app-audit-log',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    RouterLink,
    Pager,
  ],

  template: `
    <section class="page">

      <header class="page-header">
        <div>
          <h1>Auditoría</h1>
          <p>
            Registro inmutable de las operaciones de identidad y acceso.
            Se escribe en la misma transacción que cada operación.
          </p>
        </div>
      </header>

      <section class="panel">

        <form class="filters" (submit)="applyFilters($event)">
          <div class="field">
            <label for="audit-action">Evento</label>
            <select
              id="audit-action"
              [value]="action()"
              (change)="action.set($any($event.target).value)"
            >
              <option value="">Todos los eventos</option>
              @for (option of actionOptions; track option.value) {
                <option [value]="option.value">{{ option.label }}</option>
              }
            </select>
          </div>

          <div class="field">
            <label for="audit-entity">Entidad</label>
            <select
              id="audit-entity"
              [value]="entityType()"
              (change)="entityType.set($any($event.target).value)"
            >
              <option value="">Todas</option>
              @for (option of entityOptions; track option.value) {
                <option [value]="option.value">{{ option.label }}</option>
              }
            </select>
          </div>

          <div class="field entity-id-field">
            <label for="audit-entity-id">ID de entidad</label>
            <input
              id="audit-entity-id"
              type="number"
              min="1"
              inputmode="numeric"
              [value]="entityId()"
              (input)="entityId.set($any($event.target).value)"
            />
          </div>

          <div class="actions">
            <button type="submit" class="btn btn-primary" [disabled]="isLoading()">Filtrar</button>
            <button type="button" class="btn" [disabled]="isLoading()" (click)="reset()">Limpiar</button>
          </div>
        </form>

        @if (isLoading()) {
          <div class="empty-state" role="status">Cargando eventos...</div>
        } @else if (error()) {
          <div class="panel-body">
            <div class="notice error" role="alert">
              <strong>No fue posible consultar la auditoría.</strong>
              <p>{{ error() }}</p>
              <button type="button" class="btn" (click)="load(page())">Reintentar</button>
            </div>
          </div>
        } @else if (entries().length === 0) {
          <div class="empty-state">
            <strong>Sin eventos</strong>
            No hay eventos que coincidan con los filtros.
          </div>
        } @else {
          <ol class="timeline" aria-label="Eventos de auditoría">
            @for (entry of entries(); track entry.id) {
              <li class="event">
                <time class="event-time" [attr.datetime]="entry.created_at">
                  {{ formatDateTime(entry.created_at) }}
                </time>

                <div class="event-body">
                  <div class="event-title">
                    <strong>{{ auditActionLabel(entry.action) }}</strong>
                    @if (entry.outcome !== 'success') {
                      <span class="badge badge--danger">{{ entry.outcome }}</span>
                    }
                  </div>

                  <div class="event-meta small">
                    <span>{{ entry.actor?.name ?? 'Sistema' }}</span>
                    <span class="muted">sobre</span>
                    @if (entityLink(entry); as link) {
                      <a [routerLink]="link">{{ auditEntityLabel(entry.entity_type) }} #{{ entry.entity_id }}</a>
                    } @else {
                      <span>{{ auditEntityLabel(entry.entity_type) }} #{{ entry.entity_id }}</span>
                    }
                    @if (entry.ip_address) {
                      <span class="muted">desde {{ entry.ip_address }}</span>
                    }
                  </div>

                  @if (hasMetadata(entry)) {
                    <details class="event-details">
                      <summary>Detalles</summary>
                      <dl>
                        @for (item of metadataItems(entry); track item.key) {
                          <div>
                            <dt>{{ item.key }}</dt>
                            <dd><code>{{ item.value }}</code></dd>
                          </div>
                        }
                      </dl>
                    </details>
                  }
                </div>
              </li>
            }
          </ol>

          <app-pager
            [page]="page()"
            [lastPage]="lastPage()"
            [total]="total()"
            [from]="from()"
            [to]="to()"
            [disabled]="isLoading()"
            (pageChange)="load($event)"
          />
        }
      </section>

    </section>
  `,

  styles: [`
    .filters {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-end;
      gap: 14px;
      padding: 16px 20px;
      border-bottom: 1px solid var(--line);
    }

    .filters .field {
      flex: 1 1 200px;
    }

    .filters .entity-id-field {
      flex: 0 1 140px;
    }

    .timeline {
      margin: 0;
      padding: 8px 0;
      list-style: none;
    }

    .event {
      display: grid;
      grid-template-columns: 170px minmax(0, 1fr);
      gap: 16px;
      padding: 12px 20px;
      border-bottom: 1px solid var(--line);
    }

    .event:last-child {
      border-bottom: 0;
    }

    .event-time {
      padding-top: 1px;
      font-size: 13px;
      color: var(--text-muted);
      font-variant-numeric: tabular-nums;
    }

    .event-title {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px;
    }

    .event-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 5px;
      margin-top: 2px;
    }

    .event-details {
      margin-top: 8px;
      font-size: 13px;
    }

    .event-details summary {
      color: var(--verified);
      cursor: pointer;
    }

    .event-details dl {
      display: grid;
      gap: 4px;
      margin: 8px 0 0;
      padding: 10px 12px;
      border-radius: var(--radius-control);
      background: var(--surface-sunken);
    }

    .event-details dl div {
      display: grid;
      grid-template-columns: 200px minmax(0, 1fr);
      gap: 10px;
    }

    .event-details dt {
      color: var(--text-muted);
    }

    .event-details dd {
      margin: 0;
      overflow-wrap: anywhere;
    }

    .event-details code {
      font-family: var(--font-mono);
      font-size: 12px;
    }

    @media (max-width: 720px) {
      .event {
        grid-template-columns: minmax(0, 1fr);
        gap: 4px;
      }

      .event-details dl div {
        grid-template-columns: minmax(0, 1fr);
        gap: 0;
      }
    }
  `],
})
export class AuditLog {

  private readonly auditService = inject(AuditService);
  private readonly destroyRef = inject(DestroyRef);

  readonly formatDateTime = formatDateTime;
  readonly auditActionLabel = auditActionLabel;
  readonly auditEntityLabel = auditEntityLabel;

  readonly actionOptions = Object.entries(AUDIT_ACTION_LABELS).map(
    ([value, label]) => ({ value, label })
  );

  readonly entityOptions = Object.entries(AUDIT_ENTITY_LABELS).map(
    ([value, label]) => ({ value, label })
  );

  readonly action = signal('');
  readonly entityType = signal('');
  readonly entityId = signal('');

  readonly entries = signal<AuditLogEntry[]>([]);
  readonly isLoading = signal(false);
  readonly error = signal<string | null>(null);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly total = signal(0);
  readonly from = signal<number | null>(null);
  readonly to = signal<number | null>(null);

  constructor() {
    this.load(1);
  }

  applyFilters(event: Event): void {
    event.preventDefault();
    this.load(1);
  }

  reset(): void {
    this.action.set('');
    this.entityType.set('');
    this.entityId.set('');
    this.load(1);
  }

  load(page: number): void {
    this.isLoading.set(true);
    this.error.set(null);

    const id = Number(this.entityId());

    this.auditService.list({
      action: this.action(),
      entityType: this.entityType(),
      entityId: Number.isInteger(id) && id > 0 ? id : null,
      page,
    })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.entries.set(response.data);
          this.page.set(response.current_page);
          this.lastPage.set(response.last_page);
          this.total.set(response.total);
          this.from.set(response.from);
          this.to.set(response.to);
          this.isLoading.set(false);
        },
        error: (error: unknown) => {
          this.entries.set([]);
          this.error.set(
            describeApiError(error, {
              action: 'consultar la auditoría',
            }).message
          );
          this.isLoading.set(false);
        },
      });
  }

  entityLink(entry: AuditLogEntry): unknown[] | null {
    switch (entry.entity_type) {
      case 'user':
        return ['/identities', entry.entity_id];
      case 'resource':
        return ['/resources', entry.entity_id];
      default:
        return null;
    }
  }

  hasMetadata(entry: AuditLogEntry): boolean {
    return !!entry.metadata && Object.keys(entry.metadata).length > 0;
  }

  metadataItems(entry: AuditLogEntry): { key: string; value: string }[] {
    return Object.entries(entry.metadata ?? {}).map(([key, value]) => ({
      key,
      value: typeof value === 'string' ? value : JSON.stringify(value),
    }));
  }
}
