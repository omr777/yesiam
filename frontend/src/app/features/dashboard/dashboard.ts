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

import { AuthService } from '../../core/auth/auth.service';
import { describeApiError } from '../../core/http/api-error';

import {
  auditActionLabel,
  auditEntityLabel,
  formatDateTime,
} from '../../shared/labels';

import {
  DashboardService,
  DashboardSummary,
} from './dashboard.service';

/*
|--------------------------------------------------------------------------
| YESIAM - DASHBOARD
|--------------------------------------------------------------------------
|
| FASE 21
|
| Datos reales de GET /api/dashboard.
|
| Organización de la pantalla:
|
| 1. Lo que espera una acción de esta persona.
| 2. Su propia actividad de accesos.
| 3. El estado de la organización (si su
|    perfil lo permite).
| 4. Actividad reciente de auditoría.
|
| Una sección ausente significa que el perfil
| no tiene permiso, no que el valor sea cero.
|
*/

interface PendingItem {
  count: number;
  label: string;
  hint: string;
  link: string;
  action: string;
}

@Component({
  selector: 'app-dashboard',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink],

  template: `
    <section class="page dashboard">

      <header class="page-header">
        <div>
          <h1>Hola, {{ firstName() }}</h1>
          <p>{{ profileLine() }}</p>
        </div>

        <button
          type="button"
          class="btn"
          [disabled]="isLoading()"
          (click)="load()"
        >
          {{ isLoading() ? 'Actualizando...' : 'Actualizar' }}
        </button>
      </header>

      @if (error()) {
        <div class="notice error" role="alert">
          <strong>No fue posible cargar el resumen.</strong>
          <p>{{ error() }}</p>
          <button type="button" class="btn" (click)="load()">Reintentar</button>
        </div>
      } @else if (summary(); as data) {

        <div class="dashboard-grid">

          <!-- PENDIENTE DE TI -->
          <section class="panel pending" aria-labelledby="pending-title">
            <div class="panel-header">
              <h2 class="section-title" id="pending-title">Pendiente de ti</h2>
            </div>

            @if (pendingItems().length === 0) {
              <div class="empty-state">
                <strong>Nada pendiente</strong>
                No hay solicitudes ni identidades esperando tu acción.
              </div>
            } @else {
              <ul class="pending-list">
                @for (item of pendingItems(); track item.link) {
                  <li>
                    <span class="pending-count">{{ item.count }}</span>
                    <span class="pending-text">
                      <strong>{{ item.label }}</strong>
                      <span>{{ item.hint }}</span>
                    </span>
                    <a class="btn btn-sm" [routerLink]="item.link">{{ item.action }}</a>
                  </li>
                }
              </ul>
            }
          </section>

          <!-- MI ACTIVIDAD -->
          <section class="panel mine" aria-labelledby="mine-title">
            <div class="panel-header">
              <h2 class="section-title" id="mine-title">Mis accesos</h2>
              <a class="small" routerLink="/my-access">Ver inventario</a>
            </div>

            <div class="panel-body mine-body">
              <div class="big-figure">
                <strong>{{ data.my_access.active_grants }}</strong>
                <span>{{ data.my_access.active_grants === 1 ? 'acceso activo' : 'accesos activos' }}</span>
              </div>

              @if (data.my_requests; as mine) {
                <dl class="mini-stats">
                  <div><dt>En revisión</dt><dd>{{ mine.pending }}</dd></div>
                  <div><dt>Aprobadas</dt><dd>{{ mine.approved }}</dd></div>
                  <div><dt>Rechazadas</dt><dd>{{ mine.rejected }}</dd></div>
                </dl>

                <a class="btn btn-primary" routerLink="/requests">Solicitar acceso</a>
              }
            </div>
          </section>

          <!-- ORGANIZACIÓN -->
          @if (data.identities; as identities) {
            <section class="panel org" aria-labelledby="org-title">
              <div class="panel-header">
                <h2 class="section-title" id="org-title">Identidades de la organización</h2>
                <a class="small" routerLink="/identities">Abrir directorio</a>
              </div>

              <div class="panel-body">
                <div
                  class="status-bar"
                  role="img"
                  [attr.aria-label]="identityBarLabel()"
                >
                  @for (segment of identitySegments(); track segment.key) {
                    @if (segment.value > 0) {
                      <span
                        class="status-bar-segment"
                        [class]="'status-bar-segment is-' + segment.key"
                        [style.flex-grow]="segment.value"
                      ></span>
                    }
                  }
                </div>

                <ul class="legend">
                  @for (segment of identitySegments(); track segment.key) {
                    <li>
                      <span class="legend-swatch" [class]="'legend-swatch is-' + segment.key" aria-hidden="true"></span>
                      <strong>{{ segment.value }}</strong> {{ segment.label }}
                    </li>
                  }
                </ul>

                <dl class="org-facts">
                  @if (data.departments; as departments) {
                    <div>
                      <dt>Departamentos activos</dt>
                      <dd>{{ departments.active }}</dd>
                    </div>
                  }
                  @if (data.resources; as resources) {
                    <div>
                      <dt>Recursos activos</dt>
                      <dd>{{ resources.active }} <span class="muted small">({{ resources.restricted }} con restricción)</span></dd>
                    </div>
                  }
                  @if (data.access; as access) {
                    <div>
                      <dt>Accesos concedidos</dt>
                      <dd>{{ access.active_grants }} <span class="muted small">activos, {{ access.revoked_grants }} revocados</span></dd>
                    </div>
                  }
                </dl>
              </div>
            </section>
          } @else if (data.resources; as resources) {
            <section class="panel org" aria-labelledby="catalog-summary-title">
              <div class="panel-header">
                <h2 class="section-title" id="catalog-summary-title">Catálogo</h2>
                <a class="small" routerLink="/resources">Ver recursos</a>
              </div>
              <div class="panel-body">
                <div class="big-figure">
                  <strong>{{ resources.active }}</strong>
                  <span>recursos disponibles para solicitar</span>
                </div>
              </div>
            </section>
          }

          <!-- ACTIVIDAD RECIENTE -->
          @if (data.recent_activity; as activity) {
            <section class="panel activity" aria-labelledby="activity-title">
              <div class="panel-header">
                <h2 class="section-title" id="activity-title">Actividad reciente</h2>
                <a class="small" routerLink="/audit">Ver auditoría</a>
              </div>

              @if (activity.length === 0) {
                <div class="empty-state">Todavía no hay eventos registrados.</div>
              } @else {
                <ul class="activity-list">
                  @for (event of activity; track event.id) {
                    <li>
                      <strong>{{ auditActionLabel(event.action) }}</strong>
                      <span class="small muted">
                        {{ event.actor?.name ?? 'Sistema' }}, {{ auditEntityLabel(event.entity_type) }} #{{ event.entity_id }}
                      </span>
                      <time class="small muted" [attr.datetime]="event.created_at">{{ formatDateTime(event.created_at) }}</time>
                    </li>
                  }
                </ul>
              }
            </section>
          }

        </div>

      } @else {
        <div class="panel empty-state" role="status">Cargando resumen...</div>
      }

    </section>
  `,

  styles: [`
    .dashboard-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 20px;
      align-items: start;
    }

    .org,
    .activity {
      grid-column: span 1;
    }

    /*
    | PENDIENTE DE TI
    */

    .pending-list {
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .pending-list li {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 14px 20px;
      border-bottom: 1px solid var(--line);
    }

    .pending-list li:last-child {
      border-bottom: 0;
    }

    .pending-count {
      display: grid;
      flex: 0 0 44px;
      place-items: center;
      height: 44px;
      border-radius: 10px;
      background: var(--pending-tint);
      color: var(--pending);
      font-size: 20px;
      font-weight: 700;
      font-variant-numeric: tabular-nums;
    }

    .pending-text {
      display: flex;
      flex: 1;
      flex-direction: column;
      min-width: 0;
    }

    .pending-text span {
      font-size: 13px;
      color: var(--text-muted);
    }

    /*
    | MIS ACCESOS
    */

    .mine-body {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 16px;
    }

    .big-figure {
      display: flex;
      align-items: baseline;
      gap: 10px;
    }

    .big-figure strong {
      font-size: 40px;
      font-weight: 600;
      line-height: 1;
      color: var(--verified);
      font-variant-numeric: tabular-nums;
    }

    .big-figure span {
      color: var(--text-muted);
    }

    .mini-stats {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 10px;
      width: 100%;
      margin: 0;
    }

    .mini-stats div {
      padding: 10px 12px;
      border: 1px solid var(--line);
      border-radius: var(--radius-control);
    }

    .mini-stats dt {
      font-size: 12px;
      color: var(--text-muted);
    }

    .mini-stats dd {
      margin: 2px 0 0;
      font-size: 20px;
      font-weight: 600;
      font-variant-numeric: tabular-nums;
    }

    /*
    | BARRA DE ESTADOS DE IDENTIDAD
    */

    .status-bar {
      display: flex;
      gap: 3px;
      height: 14px;
      overflow: hidden;
      border-radius: 999px;
      background: var(--surface-sunken);
    }

    .status-bar-segment {
      flex-basis: 0;
      min-width: 6px;
    }

    .is-active {
      background: var(--verified);
    }

    .is-suspended {
      background: #e3a24c;
    }

    .is-disabled {
      background: #d98b84;
    }

    .legend {
      display: flex;
      flex-wrap: wrap;
      gap: 18px;
      margin: 12px 0 18px;
      padding: 0;
      list-style: none;
      font-size: 14px;
    }

    .legend li {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .legend-swatch {
      width: 10px;
      height: 10px;
      border-radius: 3px;
    }

    .org-facts {
      display: grid;
      gap: 10px;
      margin: 0;
      padding-top: 14px;
      border-top: 1px solid var(--line);
    }

    .org-facts div {
      display: flex;
      justify-content: space-between;
      gap: 12px;
    }

    .org-facts dt {
      color: var(--text-muted);
    }

    .org-facts dd {
      margin: 0;
      font-weight: 600;
      text-align: right;
    }

    /*
    | ACTIVIDAD
    */

    .activity-list {
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .activity-list li {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 2px 12px;
      padding: 11px 20px;
      border-bottom: 1px solid var(--line);
    }

    .activity-list li:last-child {
      border-bottom: 0;
    }

    .activity-list strong {
      grid-column: 1;
      font-size: 14px;
      font-weight: 600;
    }

    .activity-list span {
      grid-column: 1;
    }

    .activity-list time {
      grid-column: 2;
      grid-row: 1 / span 2;
      align-self: center;
      white-space: nowrap;
    }

    @media (max-width: 980px) {
      .dashboard-grid {
        grid-template-columns: minmax(0, 1fr);
      }
    }
  `],
})
export class Dashboard {

  private readonly dashboardService = inject(DashboardService);
  private readonly auth = inject(AuthService);
  private readonly destroyRef = inject(DestroyRef);

  readonly formatDateTime = formatDateTime;
  readonly auditActionLabel = auditActionLabel;
  readonly auditEntityLabel = auditEntityLabel;

  readonly summary = signal<DashboardSummary | null>(null);
  readonly isLoading = signal(false);
  readonly error = signal<string | null>(null);

  readonly firstName = computed(() =>
    (this.auth.currentUser()?.name ?? '').split(/\s+/)[0] || 'de nuevo'
  );

  readonly profileLine = computed(() => {
    const user = this.auth.currentUser();

    if (!user) {
      return '';
    }

    const roles = (user.roles ?? []).map((role) => role.name).join(', ');
    const department = user.department?.name;

    return [roles, department].filter(Boolean).join(' en ')
      || 'Resumen de identidades y accesos.';
  });

  readonly pendingItems = computed<PendingItem[]>(() => {
    const data = this.summary();

    if (!data) {
      return [];
    }

    const items: PendingItem[] = [];

    if (data.approvals && data.approvals.pending > 0) {
      items.push({
        count: data.approvals.pending,
        label: 'Solicitudes por decidir',
        hint: 'De los recursos de los que eres responsable.',
        link: '/approvals',
        action: 'Revisar',
      });
    }

    if (data.provisioning && data.provisioning.ready_to_grant > 0) {
      items.push({
        count: data.provisioning.ready_to_grant,
        label: 'Accesos por conceder',
        hint: 'Solicitudes aprobadas que esperan provisionamiento.',
        link: '/provisioning',
        action: 'Conceder',
      });
    }

    if (data.identities && data.identities.suspended > 0) {
      items.push({
        count: data.identities.suspended,
        label: 'Identidades por activar',
        hint: 'Altas recientes que aún no pueden iniciar sesión.',
        link: '/identities',
        action: 'Ver identidades',
      });
    }

    return items;
  });

  readonly identitySegments = computed(() => {
    const identities = this.summary()?.identities;

    return [
      { key: 'active', label: 'activas', value: identities?.active ?? 0 },
      { key: 'suspended', label: 'suspendidas', value: identities?.suspended ?? 0 },
      { key: 'disabled', label: 'desactivadas', value: identities?.disabled ?? 0 },
    ];
  });

  readonly identityBarLabel = computed(() =>
    this.identitySegments()
      .map((segment) => `${segment.value} ${segment.label}`)
      .join(', ')
  );

  constructor() {
    this.load();
  }

  load(): void {
    this.isLoading.set(true);
    this.error.set(null);

    this.dashboardService.summary()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (summary) => {
          this.summary.set(summary);
          this.isLoading.set(false);
        },
        error: (error: unknown) => {
          this.error.set(
            describeApiError(error, {
              action: 'cargar el resumen',
            }).message
          );
          this.isLoading.set(false);
        },
      });
  }
}
