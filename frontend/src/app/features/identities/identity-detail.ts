import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  numberAttribute,
  signal,
  untracked,
} from '@angular/core';

import { RouterLink } from '@angular/router';

import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import { AuthService } from '../../core/auth/auth.service';
import { describeApiError } from '../../core/http/api-error';

import {
  ROLE_DESCRIPTIONS,
  formatDate,
  formatDateTime,
} from '../../shared/labels';

import { ActionDialog } from '../../shared/ui/action-dialog';
import { Pager } from '../../shared/ui/pager';
import { StatusBadge } from '../../shared/ui/status-badge';
import { ToastService } from '../../shared/ui/toast.service';

import { AccessService } from '../access/access.service';

import {
  Department,
  DepartmentService,
} from '../departments/department.service';

import {
  AccessGrantItem,
  GrantStatus,
  IdentityDetail as IdentityDetailModel,
  IdentityService,
  RoleDefinition,
} from './identity.service';

/*
|--------------------------------------------------------------------------
| YESIAM - DETALLE DE IDENTIDAD
|--------------------------------------------------------------------------
|
| FASE 21
|
| Concentra el ciclo de vida de una persona:
|
| - Activación (completa el Joiner).
| - Roles.
| - Mover: traslado de departamento con
|   revocación selectiva de accesos.
| - Leaver: desactivación definitiva.
| - Inventario de accesos y revocación.
|
| Cada acción vuelve a autorizarse en Laravel.
| La interfaz solo evita ofrecer acciones que
| el backend rechazaría con seguridad
| (por ejemplo, modificar la propia identidad).
|
*/

type Stage = 'registered' | 'active' | 'disabled';

@Component({
  selector: 'app-identity-detail',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    RouterLink,
    StatusBadge,
    ActionDialog,
    Pager,
  ],

  template: `
    <section class="page">

      <a class="back-link" routerLink="/identities">← Volver al directorio</a>

      @if (isLoading()) {
        <div class="panel empty-state" role="status">Cargando identidad...</div>
      } @else if (loadError()) {
        <div class="notice error" role="alert">
          <strong>No fue posible cargar la identidad.</strong>
          <p>{{ loadError() }}</p>
          <button type="button" class="btn" (click)="load()">Reintentar</button>
        </div>
      } @else if (identity(); as person) {

        <header class="page-header identity-header">
          <div>
            <h1>{{ person.name }}</h1>
            <p>{{ person.email }}</p>
          </div>

          <app-status-badge kind="identity" [value]="person.status" />
        </header>

        <!-- RECORRIDO DEL CICLO DE VIDA -->
        <ol class="lifecycle" aria-label="Ciclo de vida de la identidad">
          @for (step of stages; track step.key) {
            <li
              class="lifecycle-step"
              [class.is-done]="isStageDone(step.key)"
              [class.is-current]="stage() === step.key"
              [attr.aria-current]="stage() === step.key ? 'step' : null"
            >
              <span class="lifecycle-dot" aria-hidden="true"></span>
              <span class="lifecycle-text">
                <strong>{{ step.label }}</strong>
                <span>{{ step.hint }}</span>
              </span>
            </li>
          }
        </ol>

        @if (temporaryPassword()) {
          <div class="notice warning credential" role="alert">
            <strong>Contraseña temporal de {{ person.name }}</strong>
            <p>
              Se muestra una sola vez. Entrégala por un canal seguro;
              YESIAM no la guarda y no podrás volver a verla.
            </p>

            <div class="credential-row">
              <code class="credential-value">{{ temporaryPassword() }}</code>

              <button type="button" class="btn btn-sm" (click)="copyPassword()">
                {{ copied() ? 'Copiada' : 'Copiar' }}
              </button>

              <button
                type="button"
                class="btn btn-sm btn-ghost"
                (click)="temporaryPassword.set(null)"
              >
                Ya la entregué
              </button>
            </div>
          </div>
        }

        <div class="split">

          <!-- COLUMNA PRINCIPAL -->
          <div class="stack">

            <section class="panel" aria-labelledby="profile-title">
              <div class="panel-header">
                <h2 class="section-title" id="profile-title">Perfil</h2>
              </div>

              <dl class="panel-body facts">
                <div>
                  <dt>Departamento</dt>
                  <dd>
                    @if (person.department) {
                      {{ person.department.name }}
                      <span class="code">{{ person.department.code }}</span>
                    } @else {
                      <span class="muted">Sin departamento</span>
                    }
                  </dd>
                </div>
                <div>
                  <dt>Roles</dt>
                  <dd>
                    @for (role of person.roles; track role.code) {
                      <span class="role-chip">{{ role.name }}</span>
                    } @empty {
                      <span class="muted">Sin roles</span>
                    }
                  </dd>
                </div>
                <div>
                  <dt>Accesos activos</dt>
                  <dd>{{ person.active_access_grants_count }}</dd>
                </div>
                <div>
                  <dt>Responsable de</dt>
                  <dd>
                    @for (resource of person.managed_resources; track resource.id) {
                      <a class="managed-link" [routerLink]="['/resources', resource.id]">
                        {{ resource.name }}
                      </a>
                    } @empty {
                      <span class="muted">Ningún recurso</span>
                    }
                  </dd>
                </div>
                <div>
                  <dt>Alta</dt>
                  <dd>{{ formatDate(person.created_at) }}</dd>
                </div>
              </dl>
            </section>

            <!-- INVENTARIO DE ACCESOS -->
            @if (canViewAccess()) {
              <section class="panel" aria-labelledby="grants-title">
                <div class="panel-header">
                  <div>
                    <h2 class="section-title" id="grants-title">Accesos</h2>
                    <p class="section-hint">Concesiones registradas para esta identidad.</p>
                  </div>

                  <div class="field inline-field">
                    <label for="grant-filter" class="visually-hidden">Filtrar por estado</label>
                    <select
                      id="grant-filter"
                      [value]="grantFilter()"
                      (change)="changeGrantFilter($any($event.target).value)"
                    >
                      <option value="">Todos</option>
                      <option value="active">Activos</option>
                      <option value="revoked">Revocados</option>
                    </select>
                  </div>
                </div>

                @if (grantsLoading()) {
                  <div class="empty-state" role="status">Cargando accesos...</div>
                } @else if (grantsError()) {
                  <div class="panel-body">
                    <div class="notice error" role="alert">
                      <strong>No fue posible consultar los accesos.</strong>
                      <p>{{ grantsError() }}</p>
                    </div>
                  </div>
                } @else if (grants().length === 0) {
                  <div class="empty-state">
                    <strong>Sin accesos en esta vista</strong>
                    Los accesos aparecen aquí cuando se concede una solicitud aprobada.
                  </div>
                } @else {
                  <div class="table-wrap">
                    <table class="data-table">
                      <thead>
                        <tr>
                          <th scope="col">Recurso</th>
                          <th scope="col">Estado</th>
                          <th scope="col">Concedido</th>
                          <th scope="col">Revocado</th>
                          <th scope="col"><span class="visually-hidden">Acciones</span></th>
                        </tr>
                      </thead>
                      <tbody>
                        @for (grant of grants(); track grant.id) {
                          <tr>
                            <td>
                              <div class="cell-primary">{{ grant.resource?.name ?? 'Recurso no disponible' }}</div>
                              @if (grant.resource) {
                                <div class="cell-secondary"><span class="code">{{ grant.resource.code }}</span></div>
                              }
                            </td>
                            <td><app-status-badge kind="grant" [value]="grant.status" /></td>
                            <td>
                              <div>{{ formatDateTime(grant.granted_at) }}</div>
                              <div class="cell-secondary">{{ grant.granted_by?.name ?? '—' }}</div>
                            </td>
                            <td>
                              @if (grant.revoked_at) {
                                <div>{{ formatDateTime(grant.revoked_at) }}</div>
                                <div class="cell-secondary">{{ grant.revoked_by?.name ?? '—' }}</div>
                              } @else {
                                <span class="muted">—</span>
                              }
                            </td>
                            <td class="cell-actions">
                              @if (grant.status === 'active' && canRevoke()) {
                                <button
                                  type="button"
                                  class="btn btn-sm btn-danger-outline"
                                  (click)="askRevoke(grant)"
                                >
                                  Revocar
                                </button>
                              }
                            </td>
                          </tr>
                        }
                      </tbody>
                    </table>
                  </div>

                  <app-pager
                    [page]="grantPage()"
                    [lastPage]="grantLastPage()"
                    [total]="grantTotal()"
                    [from]="grantFrom()"
                    [to]="grantTo()"
                    (pageChange)="loadGrants($event)"
                  />
                }
              </section>
            }

          </div>

          <!-- COLUMNA LATERAL -->
          <aside class="stack">

            @if (isSelf()) {
              <div class="notice">
                <strong>Esta es tu propia identidad</strong>
                <p>
                  Por separación de funciones, otra persona administradora
                  debe cambiar tus roles, departamento o estado.
                </p>
              </div>
            }

            <!-- ACTIVACIÓN -->
            @if (person.status === 'suspended' && !isSelf()) {
              <section class="panel" aria-labelledby="activation-title">
                <div class="panel-header">
                  <h2 class="section-title" id="activation-title">Activación</h2>
                </div>
                <div class="panel-body stack-sm">
                  <p class="small muted">
                    La identidad está suspendida desde el alta. Al activarla
                    se genera una contraseña temporal para su primer inicio de sesión.
                  </p>

                  @if (activationError()) {
                    <div class="notice error" role="alert">
                      <strong>No se activó la identidad.</strong>
                      <p>{{ activationError() }}</p>
                    </div>
                  }

                  <button
                    type="button"
                    class="btn btn-primary"
                    [disabled]="isActivating()"
                    (click)="activate()"
                  >
                    {{ isActivating() ? 'Activando...' : 'Activar identidad' }}
                  </button>
                </div>
              </section>
            }

            <!-- ROLES -->
            @if (person.status !== 'disabled' && !isSelf()) {
              <section class="panel" aria-labelledby="roles-title">
                <div class="panel-header">
                  <h2 class="section-title" id="roles-title">Roles</h2>
                </div>

                <div class="panel-body stack-sm">
                  @if (rolesError()) {
                    <div class="notice error" role="alert">
                      <strong>No se cargaron los roles.</strong>
                      <p>{{ rolesError() }}</p>
                    </div>
                  }

                  @for (role of availableRoles(); track role.code) {
                    <label class="check role-option">
                      <input
                        type="checkbox"
                        [checked]="selectedRoles().has(role.code)"
                        [disabled]="isSavingRoles()"
                        (change)="toggleRole(role.code)"
                      />
                      <span>
                        <strong>{{ role.name }}</strong>
                        <span class="role-description">{{ roleDescription(role.code) }}</span>
                      </span>
                    </label>
                  }

                  @if (losesApproval()) {
                    <div class="notice warning">
                      <strong>Dejará de ser responsable de aprobación</strong>
                      <p>
                        Se retirará de {{ person.managed_resources.length }}
                        recurso(s). Las solicitudes pendientes quedarán para los
                        demás responsables.
                      </p>
                    </div>
                  }

                  @if (saveRolesError()) {
                    <div class="notice error" role="alert">
                      <strong>No se guardaron los roles.</strong>
                      <p>{{ saveRolesError() }}</p>
                    </div>
                  }

                  <button
                    type="button"
                    class="btn btn-primary"
                    [disabled]="isSavingRoles() || !rolesChanged() || selectedRoles().size === 0"
                    (click)="saveRoles()"
                  >
                    {{ isSavingRoles() ? 'Guardando...' : 'Guardar roles' }}
                  </button>
                </div>
              </section>
            }

            <!-- MOVER -->
            @if (person.status !== 'disabled' && !isSelf()) {
              <section class="panel" aria-labelledby="mover-title">
                <div class="panel-header">
                  <h2 class="section-title" id="mover-title">Trasladar (Mover)</h2>
                </div>

                <div class="panel-body stack-sm">
                  <p class="small muted">
                    Solo se revocan los accesos que dejen de ser elegibles
                    en el nuevo departamento.
                  </p>

                  <div class="field">
                    <label for="mover-department">Nuevo departamento</label>
                    <select
                      id="mover-department"
                      (change)="moverTarget.set($any($event.target).value ? +$any($event.target).value : null)"
                    >
                      <option value="" [selected]="moverTarget() === null">Selecciona un departamento</option>
                      @for (department of moverOptions(); track department.id) {
                        <option
                          [value]="department.id"
                          [selected]="department.id === moverTarget()"
                        >
                          {{ department.name }} ({{ department.code }})
                        </option>
                      }
                    </select>
                  </div>

                  <button
                    type="button"
                    class="btn"
                    [disabled]="moverTarget() === null"
                    (click)="moverDialogOpen.set(true)"
                  >
                    Trasladar
                  </button>
                </div>
              </section>
            }

            <!-- LEAVER -->
            @if (person.status !== 'disabled' && !isSelf()) {
              <section class="panel panel--danger" aria-labelledby="leaver-title">
                <div class="panel-header">
                  <h2 class="section-title" id="leaver-title">Baja (Leaver)</h2>
                </div>

                <div class="panel-body stack-sm">
                  <p class="small muted">
                    Revoca todos sus accesos, cierra sus sesiones y bloquea la
                    cuenta. Es definitivo.
                  </p>

                  <button
                    type="button"
                    class="btn btn-danger-outline"
                    (click)="leaverDialogOpen.set(true)"
                  >
                    Desactivar identidad
                  </button>
                </div>
              </section>
            }

          </aside>
        </div>

        <app-action-dialog
          [open]="moverDialogOpen()"
          title="Confirmar traslado"
          [description]="moverDescription()"
          confirmLabel="Trasladar"
          busyLabel="Trasladando..."
          [busy]="isMoving()"
          [errorMessage]="moverError()"
          (confirmed)="confirmMove()"
          (closed)="closeMover()"
        />

        <app-action-dialog
          [open]="leaverDialogOpen()"
          title="Desactivar identidad"
          [description]="'Se revocarán todos los accesos de ' + person.name + ', se cerrarán sus sesiones y la cuenta quedará desactivada de forma permanente.'"
          confirmLabel="Desactivar identidad"
          busyLabel="Desactivando..."
          tone="danger"
          confirmWord="DESACTIVAR"
          [busy]="isDeactivating()"
          [errorMessage]="leaverError()"
          (confirmed)="confirmDeactivate()"
          (closed)="closeLeaver()"
        />

        <app-action-dialog
          [open]="revoking() !== null"
          title="Revocar acceso"
          [description]="revokeDescription()"
          confirmLabel="Revocar acceso"
          busyLabel="Revocando..."
          tone="danger"
          reasonLabel="Motivo de la revocación"
          [busy]="isRevoking()"
          [errorMessage]="revokeError()"
          (confirmed)="confirmRevoke($event)"
          (closed)="revoking.set(null)"
        />
      }

    </section>
  `,

  styles: [`
    .identity-header {
      align-items: center;
      margin-bottom: 18px;
    }

    .stack-sm {
      display: flex;
      flex-direction: column;
      gap: 14px;
    }

    /*
    | Recorrido del ciclo de vida: elemento
    | distintivo de la pantalla.
    */

    .lifecycle {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      margin: 0 0 24px;
      padding: 0;
      list-style: none;
      counter-reset: none;
    }

    .lifecycle-step {
      position: relative;
      display: flex;
      align-items: flex-start;
      gap: 10px;
      padding: 0 12px 0 0;
    }

    .lifecycle-step::after {
      content: '';
      position: absolute;
      top: 7px;
      left: 22px;
      right: 8px;
      height: 2px;
      background: var(--line);
    }

    .lifecycle-step:last-child::after {
      display: none;
    }

    .lifecycle-step.is-done::after {
      background: var(--verified);
    }

    .lifecycle-dot {
      position: relative;
      z-index: 1;
      flex: 0 0 16px;
      width: 16px;
      height: 16px;
      border: 2px solid var(--line-strong);
      border-radius: 50%;
      background: var(--paper);
    }

    .lifecycle-step.is-done .lifecycle-dot {
      border-color: var(--verified);
      background: var(--verified);
    }

    .lifecycle-step.is-current .lifecycle-dot {
      box-shadow: 0 0 0 4px var(--verified-tint);
    }

    .lifecycle-text {
      position: relative;
      z-index: 1;
      display: flex;
      flex-direction: column;
      margin-top: 22px;
      margin-left: -26px;
      padding-right: 8px;
    }

    .lifecycle-text strong {
      font-size: 14px;
      font-weight: 600;
    }

    .lifecycle-text span {
      font-size: 12px;
      color: var(--text-muted);
    }

    .lifecycle-step:not(.is-done) .lifecycle-text strong {
      color: var(--text-muted);
    }

    .credential {
      margin-bottom: 20px;
    }

    .credential-row {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px;
      margin-top: 12px;
    }

    .credential-value {
      padding: 6px 10px;
      border: 1px dashed var(--pending);
      border-radius: var(--radius-control);
      background: var(--surface);
      font-family: var(--font-mono);
      font-size: 15px;
      letter-spacing: 0.04em;
      user-select: all;
    }

    .role-chip {
      display: inline-block;
      margin: 0 6px 6px 0;
      padding: 2px 10px;
      border: 1px solid var(--line);
      border-radius: 999px;
      font-size: 13px;
    }

    .managed-link {
      display: inline-block;
      margin-right: 10px;
    }

    .role-option {
      padding: 10px 12px;
      border: 1px solid var(--line);
      border-radius: var(--radius-control);
    }

    .role-option > span {
      display: flex;
      flex-direction: column;
    }

    .role-description {
      font-size: 12px;
      color: var(--text-muted);
    }

    .inline-field select {
      min-width: 140px;
    }

    @media (max-width: 640px) {
      .lifecycle {
        grid-template-columns: minmax(0, 1fr);
        gap: 14px;
      }

      .lifecycle-step::after {
        display: none;
      }

      .lifecycle-text {
        margin: 0;
      }
    }
  `],
})
export class IdentityDetail {

  private readonly identityService = inject(IdentityService);
  private readonly departmentService = inject(DepartmentService);
  private readonly accessService = inject(AccessService);
  private readonly auth = inject(AuthService);
  private readonly toast = inject(ToastService);
  private readonly destroyRef = inject(DestroyRef);

  /*
   * Parámetro de ruta :id
   * (withComponentInputBinding).
   */
  readonly id = input.required({ transform: numberAttribute });

  readonly formatDate = formatDate;
  readonly formatDateTime = formatDateTime;

  readonly stages: { key: Stage; label: string; hint: string }[] = [
    { key: 'registered', label: 'Alta registrada', hint: 'Joiner: suspendida' },
    { key: 'active', label: 'Activa', hint: 'Puede iniciar sesión' },
    { key: 'disabled', label: 'Desactivada', hint: 'Leaver: definitiva' },
  ];

  /*
   * IDENTIDAD
   */
  readonly identity = signal<IdentityDetailModel | null>(null);
  readonly isLoading = signal(false);
  readonly loadError = signal<string | null>(null);

  readonly stage = computed<Stage>(() => {
    const status = this.identity()?.status;

    if (status === 'disabled') {
      return 'disabled';
    }

    return status === 'active' ? 'active' : 'registered';
  });

  readonly isSelf = computed(() =>
    this.identity()?.id === this.auth.currentUser()?.id
  );

  readonly canViewAccess = computed(() => this.auth.can('access.view_all'));
  readonly canRevoke = computed(() => this.auth.can('access.revoke'));

  /*
   * ACTIVACIÓN
   */
  readonly isActivating = signal(false);
  readonly activationError = signal<string | null>(null);
  readonly temporaryPassword = signal<string | null>(null);
  readonly copied = signal(false);

  /*
   * ROLES
   */
  readonly availableRoles = signal<RoleDefinition[]>([]);
  readonly rolesError = signal<string | null>(null);
  readonly selectedRoles = signal<Set<string>>(new Set());
  readonly isSavingRoles = signal(false);
  readonly saveRolesError = signal<string | null>(null);

  readonly rolesChanged = computed(() => {
    const current = new Set(
      (this.identity()?.roles ?? []).map((role) => role.code)
    );
    const selected = this.selectedRoles();

    return current.size !== selected.size
      || [...selected].some((code) => !current.has(code));
  });

  /*
   * Avisar si la selección elimina access.approve
   * mientras la identidad es responsable de recursos.
   */
  readonly losesApproval = computed(() => {
    const person = this.identity();

    if (!person || person.managed_resources.length === 0) {
      return false;
    }

    const roles = this.availableRoles();

    if (roles.length === 0) {
      return false;
    }

    return ![...this.selectedRoles()].some((code) =>
      roles.find((role) => role.code === code)
        ?.permissions.includes('access.approve')
    );
  });

  /*
   * MOVER
   */
  readonly departments = signal<Department[]>([]);
  readonly moverTarget = signal<number | null>(null);
  readonly moverDialogOpen = signal(false);
  readonly isMoving = signal(false);
  readonly moverError = signal<string | null>(null);

  readonly moverOptions = computed(() =>
    this.departments().filter(
      (department) => department.id !== this.identity()?.department_id
    )
  );

  readonly moverDescription = computed(() => {
    const target = this.departments().find(
      (department) => department.id === this.moverTarget()
    );

    return target
      ? `La identidad pasará a ${target.name}. YESIAM revisará cada acceso activo y revocará solo los que ya no sean elegibles.`
      : '';
  });

  /*
   * LEAVER
   */
  readonly leaverDialogOpen = signal(false);
  readonly isDeactivating = signal(false);
  readonly leaverError = signal<string | null>(null);

  /*
   * INVENTARIO
   */
  readonly grants = signal<AccessGrantItem[]>([]);
  readonly grantsLoading = signal(false);
  readonly grantsError = signal<string | null>(null);
  readonly grantFilter = signal<GrantStatus | ''>('');
  readonly grantPage = signal(1);
  readonly grantLastPage = signal(1);
  readonly grantTotal = signal(0);
  readonly grantFrom = signal<number | null>(null);
  readonly grantTo = signal<number | null>(null);

  readonly revoking = signal<AccessGrantItem | null>(null);
  readonly isRevoking = signal(false);
  readonly revokeError = signal<string | null>(null);

  readonly revokeDescription = computed(() => {
    const grant = this.revoking();

    return grant
      ? `Se retirará el acceso a ${grant.resource?.name ?? 'este recurso'} de forma inmediata. El historial se conserva.`
      : '';
  });

  constructor() {
    /*
     * Recargar si cambia el :id de la ruta.
     */
    effect(() => {
      this.id();

      untracked(() => {
        this.temporaryPassword.set(null);
        this.load();
      });
    });

    this.loadCatalogs();
  }

  roleDescription(code: string): string {
    return ROLE_DESCRIPTIONS[code] ?? '';
  }

  isStageDone(key: Stage): boolean {
    const order: Stage[] = ['registered', 'active', 'disabled'];
    const current = this.stage();

    if (current === 'disabled' && key === 'active') {
      /*
       * Una identidad pudo desactivarse sin
       * haberse activado nunca; el recorrido
       * muestra igual la etapa como superada.
       */
      return true;
    }

    return order.indexOf(key) <= order.indexOf(current);
  }

  load(): void {
    this.isLoading.set(true);
    this.loadError.set(null);

    this.identityService.get(this.id())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (person) => {
          this.setIdentity(person);
          this.isLoading.set(false);

          if (this.canViewAccess()) {
            this.loadGrants(1);
          }
        },
        error: (error: unknown) => {
          this.loadError.set(
            describeApiError(error, {
              action: 'consultar identidades',
              notFound: 'La identidad no existe.',
            }).message
          );
          this.isLoading.set(false);
        },
      });
  }

  private setIdentity(person: IdentityDetailModel): void {
    this.identity.set(person);
    this.selectedRoles.set(new Set(person.roles.map((role) => role.code)));
  }

  private loadCatalogs(): void {
    this.identityService.listRoles()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (roles) => this.availableRoles.set(roles),
        error: (error: unknown) => this.rolesError.set(
          describeApiError(error, { action: 'consultar los roles' }).message
        ),
      });

    this.departmentService.listActive()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (departments) => this.departments.set(departments),
        error: () => this.departments.set([]),
      });
  }

  /*
  |--------------------------------------------------------------------------
  | ACTIVACIÓN
  |--------------------------------------------------------------------------
  */

  activate(): void {
    const person = this.identity();

    if (!person || this.isActivating()) {
      return;
    }

    this.isActivating.set(true);
    this.activationError.set(null);

    this.identityService.activate(person.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.isActivating.set(false);
          this.temporaryPassword.set(response.data.temporary_password);
          this.copied.set(false);
          this.identity.set({ ...person, status: response.data.status });
          this.toast.success(`${person.name} ya puede iniciar sesión.`);
        },
        error: (error: unknown) => {
          this.isActivating.set(false);
          this.activationError.set(
            describeApiError(error, { action: 'activar identidades' }).message
          );
        },
      });
  }

  copyPassword(): void {
    const password = this.temporaryPassword();

    if (!password || typeof navigator === 'undefined' || !navigator.clipboard) {
      return;
    }

    navigator.clipboard.writeText(password).then(
      () => this.copied.set(true),
      () => this.copied.set(false)
    );
  }

  /*
  |--------------------------------------------------------------------------
  | ROLES
  |--------------------------------------------------------------------------
  */

  toggleRole(code: string): void {
    this.selectedRoles.update((current) => {
      const next = new Set(current);

      if (next.has(code)) {
        next.delete(code);
      } else {
        next.add(code);
      }

      return next;
    });
  }

  saveRoles(): void {
    const person = this.identity();
    const roles = [...this.selectedRoles()].sort();

    if (!person || this.isSavingRoles() || roles.length === 0) {
      return;
    }

    this.isSavingRoles.set(true);
    this.saveRolesError.set(null);

    this.identityService.updateRoles(person.id, roles)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.isSavingRoles.set(false);

          const removed = response.data.removed_approver_resource_ids.length;

          this.toast.success(
            removed > 0
              ? `Roles actualizados. Se retiró como responsable de ${removed} recurso(s).`
              : 'Roles actualizados.'
          );

          this.load();
        },
        error: (error: unknown) => {
          this.isSavingRoles.set(false);
          this.saveRolesError.set(
            describeApiError(error, { action: 'asignar roles' }).message
          );
        },
      });
  }

  /*
  |--------------------------------------------------------------------------
  | MOVER
  |--------------------------------------------------------------------------
  */

  confirmMove(): void {
    const person = this.identity();
    const target = this.moverTarget();

    if (!person || target === null || this.isMoving()) {
      return;
    }

    this.isMoving.set(true);
    this.moverError.set(null);

    this.identityService.changeDepartment(person.id, target)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.isMoving.set(false);
          this.moverDialogOpen.set(false);
          this.moverTarget.set(null);

          const revoked = response.data.revoked_access_grants_count;

          this.toast.success(
            revoked > 0
              ? `Traslado completado. Se revocaron ${revoked} acceso(s) no elegibles.`
              : 'Traslado completado. Todos los accesos siguen siendo elegibles.'
          );

          this.load();
        },
        error: (error: unknown) => {
          this.isMoving.set(false);

          const apiError = describeApiError(error, {
            action: 'trasladar identidades',
          });

          this.moverError.set(
            apiError.status === 422
              ? 'El departamento de destino ya no está activo. Elige otro.'
              : apiError.message
          );
        },
      });
  }

  closeMover(): void {
    if (!this.isMoving()) {
      this.moverDialogOpen.set(false);
      this.moverError.set(null);
    }
  }

  /*
  |--------------------------------------------------------------------------
  | LEAVER
  |--------------------------------------------------------------------------
  */

  confirmDeactivate(): void {
    const person = this.identity();

    if (!person || this.isDeactivating()) {
      return;
    }

    this.isDeactivating.set(true);
    this.leaverError.set(null);

    this.identityService.deactivate(person.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.isDeactivating.set(false);
          this.leaverDialogOpen.set(false);
          this.temporaryPassword.set(null);

          const revoked = response.data.revoked_access_grants_count;

          this.toast.success(
            `${person.name} fue desactivada. Accesos revocados: ${revoked}.`
          );

          this.load();
        },
        error: (error: unknown) => {
          this.isDeactivating.set(false);
          this.leaverError.set(
            describeApiError(error, { action: 'desactivar identidades' }).message
          );
        },
      });
  }

  closeLeaver(): void {
    if (!this.isDeactivating()) {
      this.leaverDialogOpen.set(false);
      this.leaverError.set(null);
    }
  }

  /*
  |--------------------------------------------------------------------------
  | INVENTARIO Y REVOCACIÓN
  |--------------------------------------------------------------------------
  */

  changeGrantFilter(value: string): void {
    this.grantFilter.set(value === 'active' || value === 'revoked' ? value : '');
    this.loadGrants(1);
  }

  loadGrants(page: number): void {
    const person = this.identity();

    if (!person) {
      return;
    }

    this.grantsLoading.set(true);
    this.grantsError.set(null);

    this.identityService.accessGrants(person.id, {
      status: this.grantFilter(),
      page,
    })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (inventory) => {
          this.grants.set(inventory.data);
          this.grantPage.set(inventory.pagination.current_page);
          this.grantLastPage.set(inventory.pagination.last_page);
          this.grantTotal.set(inventory.pagination.total);

          const perPage = inventory.pagination.per_page;
          const start = (inventory.pagination.current_page - 1) * perPage;

          this.grantFrom.set(inventory.data.length > 0 ? start + 1 : null);
          this.grantTo.set(inventory.data.length > 0 ? start + inventory.data.length : null);

          this.grantsLoading.set(false);
        },
        error: (error: unknown) => {
          this.grants.set([]);
          this.grantsError.set(
            describeApiError(error, { action: 'consultar accesos ajenos' }).message
          );
          this.grantsLoading.set(false);
        },
      });
  }

  askRevoke(grant: AccessGrantItem): void {
    this.revokeError.set(null);
    this.revoking.set(grant);
  }

  confirmRevoke(reason: string): void {
    const grant = this.revoking();

    if (!grant || this.isRevoking()) {
      return;
    }

    this.isRevoking.set(true);
    this.revokeError.set(null);

    this.accessService.revoke(grant.id, reason)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.isRevoking.set(false);
          this.revoking.set(null);
          this.toast.success('Acceso revocado.');
          this.load();
        },
        error: (error: unknown) => {
          this.isRevoking.set(false);

          const apiError = describeApiError(error, {
            action: 'revocar accesos',
          });

          this.revokeError.set(
            apiError.status === 403
              ? 'No puedes revocar este acceso. Recuerda que nadie puede revocar sus propios accesos.'
              : apiError.message
          );
        },
      });
  }
}
