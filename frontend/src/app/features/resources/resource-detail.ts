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

import { formatDate } from '../../shared/labels';
import { ActionDialog } from '../../shared/ui/action-dialog';
import { StatusBadge } from '../../shared/ui/status-badge';
import { ToastService } from '../../shared/ui/toast.service';

import {
  Department,
  DepartmentService,
} from '../departments/department.service';

import {
  Identity,
  IdentityService,
} from '../identities/identity.service';

import {
  Resource,
  ResourceApprover,
  ResourceEligibility,
  ResourceService,
} from './resource.service';

/*
|--------------------------------------------------------------------------
| YESIAM - DETALLE DE RECURSO
|--------------------------------------------------------------------------
|
| FASE 21
|
| - Datos del recurso y edición.
| - Elegibilidad departamental:
|     cualquier departamento | solo los elegidos.
|     Restringido sin departamentos = nadie.
| - Responsables de aprobación.
| - Desactivación (revoca todos sus accesos).
|
| Laravel rechaza con 409 un cambio de
| elegibilidad que contradiga concesiones
| activas; la interfaz muestra ese mensaje.
|
*/

const CODE_PATTERN = /^[A-Z0-9_-]+$/;

@Component({
  selector: 'app-resource-detail',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    RouterLink,
    StatusBadge,
    ActionDialog,
  ],

  template: `
    <section class="page">

      <a class="back-link" routerLink="/resources">← Volver al catálogo</a>

      @if (isLoading()) {
        <div class="panel empty-state" role="status">Cargando recurso...</div>
      } @else if (loadError()) {
        <div class="notice error" role="alert">
          <strong>No fue posible cargar el recurso.</strong>
          <p>{{ loadError() }}</p>
          <button type="button" class="btn" (click)="load()">Reintentar</button>
        </div>
      } @else if (resource(); as item) {

        <header class="page-header">
          <div>
            <h1>{{ item.name }}</h1>
            <p>
              <span class="code">{{ item.code }}</span>
              @if (item.description) {
                <span class="description">{{ item.description }}</span>
              }
            </p>
          </div>

          <div class="actions">
            <app-status-badge kind="catalog" [value]="item.is_active" />

            @if (canRequest() && item.is_active) {
              <a
                class="btn btn-primary"
                routerLink="/requests"
                [queryParams]="{ resource: item.id }"
              >
                Solicitar acceso
              </a>
            }
          </div>
        </header>

        @if (!item.is_active) {
          <div class="notice warning inactive-note">
            <strong>Recurso inactivo</strong>
            <p>
              No acepta solicitudes ni concesiones. Sus accesos fueron revocados
              al desactivarlo y el historial se conserva en la auditoría.
            </p>
          </div>
        }

        <div class="split">

          <div class="stack">

            <!-- ELEGIBILIDAD -->
            <section class="panel" aria-labelledby="eligibility-title">
              <div class="panel-header">
                <div>
                  <h2 class="section-title" id="eligibility-title">Elegibilidad</h2>
                  <p class="section-hint">Qué departamentos pueden solicitar este recurso.</p>
                </div>
              </div>

              <div class="panel-body stack-sm">
                @if (eligibilityError()) {
                  <div class="notice error" role="alert">
                    <strong>No fue posible consultar la elegibilidad.</strong>
                    <p>{{ eligibilityError() }}</p>
                  </div>
                } @else if (eligibility(); as rules) {

                  @if (editableEligibility()) {
                    <fieldset class="choice-group">
                      <legend class="field-label">¿Quién puede solicitarlo?</legend>

                      <label class="check">
                        <input
                          type="radio"
                          name="restriction"
                          [checked]="!draftRestricted()"
                          (change)="draftRestricted.set(false)"
                        />
                        <span>Cualquier departamento</span>
                      </label>

                      <label class="check">
                        <input
                          type="radio"
                          name="restriction"
                          [checked]="draftRestricted()"
                          (change)="draftRestricted.set(true)"
                        />
                        <span>Solo los departamentos seleccionados</span>
                      </label>
                    </fieldset>

                    @if (draftRestricted()) {
                      <div class="department-grid" role="group" aria-label="Departamentos elegibles">
                        @for (department of activeDepartments(); track department.id) {
                          <label class="check department-option">
                            <input
                              type="checkbox"
                              [checked]="draftDepartments().has(department.id)"
                              (change)="toggleDepartment(department.id)"
                            />
                            <span>{{ department.name }} <span class="code">{{ department.code }}</span></span>
                          </label>
                        } @empty {
                          <p class="muted small">No hay departamentos activos.</p>
                        }
                      </div>

                      @if (draftDepartments().size === 0) {
                        <div class="notice warning">
                          <strong>Nadie podrá solicitar este recurso</strong>
                          <p>Un recurso restringido sin departamentos no es elegible para ninguna identidad.</p>
                        </div>
                      }
                    }

                    @if (saveEligibilityError()) {
                      <div class="notice error" role="alert">
                        <strong>No se guardó la elegibilidad.</strong>
                        <p>{{ saveEligibilityError() }}</p>
                      </div>
                    }

                    <div class="actions">
                      <button
                        type="button"
                        class="btn btn-primary"
                        [disabled]="isSavingEligibility() || !eligibilityChanged()"
                        (click)="saveEligibility()"
                      >
                        {{ isSavingEligibility() ? 'Guardando...' : 'Guardar elegibilidad' }}
                      </button>
                    </div>

                  } @else {
                    @if (!rules.department_restricted) {
                      <p>Cualquier departamento puede solicitarlo.</p>
                    } @else if (rules.eligible_departments.length === 0) {
                      <p>Restringido sin departamentos: nadie puede solicitarlo.</p>
                    } @else {
                      <ul class="plain-list">
                        @for (department of rules.eligible_departments; track department.id) {
                          <li>{{ department.name }} <span class="code">{{ department.code }}</span></li>
                        }
                      </ul>
                    }
                  }
                } @else {
                  <span class="muted small" role="status">Cargando elegibilidad...</span>
                }
              </div>
            </section>

            <!-- RESPONSABLES -->
            @if (canManage()) {
              <section class="panel" aria-labelledby="approvers-title">
                <div class="panel-header">
                  <div>
                    <h2 class="section-title" id="approvers-title">Responsables de aprobación</h2>
                    <p class="section-hint">Deciden las solicitudes de este recurso. Nadie aprueba su propia solicitud.</p>
                  </div>
                </div>

                @if (approversError()) {
                  <div class="panel-body">
                    <div class="notice error" role="alert">
                      <strong>No fue posible consultar los responsables.</strong>
                      <p>{{ approversError() }}</p>
                    </div>
                  </div>
                } @else {
                  @if (approvers().length === 0) {
                    <div class="empty-state">
                      <strong>Sin responsables</strong>
                      Las solicitudes de este recurso no podrán decidirse hasta asignar al menos uno.
                    </div>
                  } @else {
                    <ul class="approver-list">
                      @for (approver of approvers(); track approver.id) {
                        <li>
                          <div>
                            <div class="cell-primary">{{ approver.name }}</div>
                            <div class="cell-secondary">{{ approver.email }}</div>
                          </div>

                          @if (item.is_active) {
                            <button
                              type="button"
                              class="btn btn-sm btn-ghost"
                              (click)="askRemoveApprover(approver)"
                            >
                              Retirar
                            </button>
                          }
                        </li>
                      }
                    </ul>
                  }

                  @if (item.is_active) {
                    <div class="panel-body add-approver">
                      <div class="field">
                        <label for="approver-select">Asignar responsable</label>
                        <select
                          id="approver-select"
                          (change)="candidateId.set($any($event.target).value ? +$any($event.target).value : null)"
                        >
                          <option value="" [selected]="candidateId() === null">Selecciona una identidad con permiso de aprobación</option>
                          @for (candidate of candidates(); track candidate.id) {
                            <option
                              [value]="candidate.id"
                              [selected]="candidate.id === candidateId()"
                            >
                              {{ candidate.name }} ({{ candidate.email }})
                            </option>
                          }
                        </select>
                        @if (candidates().length === 0) {
                          <span class="field-help">
                            Ninguna identidad activa disponible tiene el rol de responsable de recurso.
                            Asígnalo desde el detalle de la identidad.
                          </span>
                        }
                      </div>

                      @if (addApproverError()) {
                        <div class="notice error" role="alert">
                          <strong>No se asignó el responsable.</strong>
                          <p>{{ addApproverError() }}</p>
                        </div>
                      }

                      <div class="actions">
                        <button
                          type="button"
                          class="btn"
                          [disabled]="candidateId() === null || isAddingApprover()"
                          (click)="addApprover()"
                        >
                          {{ isAddingApprover() ? 'Asignando...' : 'Asignar' }}
                        </button>
                      </div>
                    </div>
                  }
                }
              </section>
            }

          </div>

          <!-- COLUMNA LATERAL -->
          <aside class="stack">

            <section class="panel" aria-labelledby="facts-title">
              <div class="panel-header">
                <h2 class="section-title" id="facts-title">Ficha</h2>
              </div>
              <dl class="panel-body facts">
                <div><dt>Código</dt><dd><span class="code">{{ item.code }}</span></dd></div>
                <div><dt>Registrado</dt><dd>{{ formatDate(item.created_at) }}</dd></div>
                <div><dt>Actualizado</dt><dd>{{ formatDate(item.updated_at) }}</dd></div>
              </dl>
            </section>

            @if (canManage() && item.is_active) {
              <section class="panel" aria-labelledby="edit-title">
                <div class="panel-header">
                  <h2 class="section-title" id="edit-title">Editar</h2>
                </div>

                <div class="panel-body stack-sm">
                  <div class="field">
                    <label for="edit-name">Nombre</label>
                    <input
                      id="edit-name"
                      maxlength="150"
                      [value]="editName()"
                      (input)="editName.set($any($event.target).value)"
                    />
                  </div>

                  <div class="field">
                    <label for="edit-code">Código</label>
                    <input
                      id="edit-code"
                      maxlength="50"
                      [value]="editCode()"
                      (input)="editCode.set($any($event.target).value.toUpperCase())"
                    />
                  </div>

                  <div class="field">
                    <label for="edit-description">Descripción</label>
                    <textarea
                      id="edit-description"
                      maxlength="2000"
                      [value]="editDescription()"
                      (input)="editDescription.set($any($event.target).value)"
                    ></textarea>
                  </div>

                  @if (editError()) {
                    <div class="notice error" role="alert">
                      <strong>No se guardaron los cambios.</strong>
                      <p>{{ editError() }}</p>
                    </div>
                  }

                  <button
                    type="button"
                    class="btn btn-primary"
                    [disabled]="isSavingEdit()"
                    (click)="saveEdit()"
                  >
                    {{ isSavingEdit() ? 'Guardando...' : 'Guardar cambios' }}
                  </button>
                </div>
              </section>

              <section class="panel panel--danger" aria-labelledby="deactivate-title">
                <div class="panel-header">
                  <h2 class="section-title" id="deactivate-title">Desactivar recurso</h2>
                </div>
                <div class="panel-body stack-sm">
                  <p class="small muted">
                    Revoca todos los accesos activos a este recurso y deja de
                    aceptar solicitudes. No se puede deshacer.
                  </p>
                  <button
                    type="button"
                    class="btn btn-danger-outline"
                    (click)="deactivateOpen.set(true)"
                  >
                    Desactivar recurso
                  </button>
                </div>
              </section>
            }

          </aside>
        </div>

        <app-action-dialog
          [open]="deactivateOpen()"
          title="Desactivar recurso"
          [description]="'Se revocarán todos los accesos activos a ' + item.name + ' y el recurso dejará de aceptar solicitudes.'"
          confirmLabel="Desactivar recurso"
          busyLabel="Desactivando..."
          tone="danger"
          [confirmWord]="item.code"
          [busy]="isDeactivating()"
          [errorMessage]="deactivateError()"
          (confirmed)="confirmDeactivate()"
          (closed)="closeDeactivate()"
        />

        <app-action-dialog
          [open]="removing() !== null"
          title="Retirar responsable"
          [description]="removeDescription()"
          confirmLabel="Retirar responsable"
          busyLabel="Retirando..."
          [busy]="isRemoving()"
          [errorMessage]="removeError()"
          (confirmed)="confirmRemoveApprover()"
          (closed)="removing.set(null)"
        />
      }

    </section>
  `,

  styles: [`
    .page-header p {
      display: flex;
      flex-wrap: wrap;
      align-items: baseline;
      gap: 10px;
    }

    .description {
      color: var(--text-muted);
    }

    .inactive-note {
      margin-bottom: 20px;
    }

    .stack-sm {
      display: flex;
      flex-direction: column;
      gap: 14px;
    }

    .choice-group {
      display: flex;
      flex-direction: column;
      gap: 10px;
      margin: 0;
      padding: 0;
      border: 0;
    }

    .choice-group legend {
      margin-bottom: 8px;
    }

    .department-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 8px;
    }

    .department-option {
      padding: 9px 11px;
      border: 1px solid var(--line);
      border-radius: var(--radius-control);
    }

    .plain-list {
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .approver-list {
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .approver-list li {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      padding: 12px 20px;
      border-bottom: 1px solid var(--line);
    }

    .add-approver {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
  `],
})
export class ResourceDetail {

  private readonly resourceService = inject(ResourceService);
  private readonly departmentService = inject(DepartmentService);
  private readonly identityService = inject(IdentityService);
  private readonly auth = inject(AuthService);
  private readonly toast = inject(ToastService);
  private readonly destroyRef = inject(DestroyRef);

  readonly id = input.required({ transform: numberAttribute });

  readonly formatDate = formatDate;

  readonly canManage = computed(() => this.auth.can('resources.manage'));
  readonly canRequest = computed(() => this.auth.can('access.request'));

  readonly resource = signal<Resource | null>(null);
  readonly isLoading = signal(false);
  readonly loadError = signal<string | null>(null);

  /*
   * ELEGIBILIDAD
   */
  readonly eligibility = signal<ResourceEligibility | null>(null);
  readonly eligibilityError = signal<string | null>(null);
  readonly activeDepartments = signal<Department[]>([]);
  readonly draftRestricted = signal(false);
  readonly draftDepartments = signal<Set<number>>(new Set());
  readonly isSavingEligibility = signal(false);
  readonly saveEligibilityError = signal<string | null>(null);

  readonly editableEligibility = computed(() =>
    this.canManage() && this.resource()?.is_active === true
  );

  readonly eligibilityChanged = computed(() => {
    const rules = this.eligibility();

    if (!rules) {
      return false;
    }

    if (rules.department_restricted !== this.draftRestricted()) {
      return true;
    }

    if (!this.draftRestricted()) {
      return false;
    }

    const current = new Set(rules.eligible_departments.map((department) => department.id));
    const draft = this.draftDepartments();

    return current.size !== draft.size
      || [...draft].some((id) => !current.has(id));
  });

  /*
   * RESPONSABLES
   */
  readonly approvers = signal<ResourceApprover[]>([]);
  readonly approversError = signal<string | null>(null);
  readonly approverPool = signal<Identity[]>([]);
  readonly candidateId = signal<number | null>(null);
  readonly isAddingApprover = signal(false);
  readonly addApproverError = signal<string | null>(null);
  readonly removing = signal<ResourceApprover | null>(null);
  readonly isRemoving = signal(false);
  readonly removeError = signal<string | null>(null);

  readonly candidates = computed(() => {
    const assigned = new Set(this.approvers().map((approver) => approver.id));

    return this.approverPool().filter(
      (identity) => identity.status === 'active' && !assigned.has(identity.id)
    );
  });

  readonly removeDescription = computed(() => {
    const approver = this.removing();

    return approver
      ? `${approver.name} dejará de decidir solicitudes de este recurso. Las solicitudes pendientes quedarán para los demás responsables.`
      : '';
  });

  /*
   * EDICIÓN
   */
  readonly editName = signal('');
  readonly editCode = signal('');
  readonly editDescription = signal('');
  readonly isSavingEdit = signal(false);
  readonly editError = signal<string | null>(null);

  /*
   * DESACTIVACIÓN
   */
  readonly deactivateOpen = signal(false);
  readonly isDeactivating = signal(false);
  readonly deactivateError = signal<string | null>(null);

  constructor() {
    effect(() => {
      this.id();

      untracked(() => this.load());
    });
  }

  load(): void {
    this.isLoading.set(true);
    this.loadError.set(null);

    this.resourceService.get(this.id())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (resource) => {
          this.setResource(resource);
          this.isLoading.set(false);
          this.loadEligibility();

          if (this.canManage()) {
            this.loadApprovers();
            this.loadManagementCatalogs();
          }
        },
        error: (error: unknown) => {
          this.loadError.set(
            describeApiError(error, {
              action: 'consultar recursos',
              notFound: 'El recurso no existe.',
            }).message
          );
          this.isLoading.set(false);
        },
      });
  }

  private setResource(resource: Resource): void {
    this.resource.set(resource);
    this.editName.set(resource.name);
    this.editCode.set(resource.code);
    this.editDescription.set(resource.description ?? '');
  }

  private loadEligibility(): void {
    this.eligibilityError.set(null);

    this.resourceService.eligibility(this.id())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (rules) => {
          this.eligibility.set(rules);
          this.draftRestricted.set(rules.department_restricted);
          this.draftDepartments.set(
            new Set(rules.eligible_departments.map((department) => department.id))
          );
        },
        error: (error: unknown) => {
          this.eligibilityError.set(
            describeApiError(error, { action: 'consultar la elegibilidad' }).message
          );
        },
      });
  }

  private loadApprovers(): void {
    this.approversError.set(null);

    this.resourceService.approvers(this.id())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (approvers) => this.approvers.set(approvers),
        error: (error: unknown) => {
          this.approversError.set(
            describeApiError(error, { action: 'consultar responsables' }).message
          );
        },
      });
  }

  private loadManagementCatalogs(): void {
    this.departmentService.listActive()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (departments) => this.activeDepartments.set(departments),
        error: () => this.activeDepartments.set([]),
      });

    if (this.auth.can('users.manage')) {
      this.identityService.list({
        permission: 'access.approve',
        status: 'active',
        perPage: 100,
      })
        .pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe({
          next: (response) => this.approverPool.set(response.data),
          error: () => this.approverPool.set([]),
        });
    }
  }

  /*
  |--------------------------------------------------------------------------
  | ELEGIBILIDAD
  |--------------------------------------------------------------------------
  */

  toggleDepartment(id: number): void {
    this.draftDepartments.update((current) => {
      const next = new Set(current);

      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }

      return next;
    });
  }

  saveEligibility(): void {
    if (this.isSavingEligibility()) {
      return;
    }

    this.isSavingEligibility.set(true);
    this.saveEligibilityError.set(null);

    const ids = [...this.draftDepartments()].sort((a, b) => a - b);

    this.resourceService.updateEligibility(
      this.id(),
      this.draftRestricted(),
      ids
    )
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.isSavingEligibility.set(false);
          this.toast.success('Elegibilidad actualizada.');
          this.loadEligibility();
        },
        error: (error: unknown) => {
          this.isSavingEligibility.set(false);

          const apiError = describeApiError(error, {
            action: 'modificar la elegibilidad',
          });

          this.saveEligibilityError.set(
            apiError.status === 422
              ? 'Uno o más departamentos ya no están activos. Recarga la página y vuelve a elegir.'
              : apiError.message
          );
        },
      });
  }

  /*
  |--------------------------------------------------------------------------
  | RESPONSABLES
  |--------------------------------------------------------------------------
  */

  addApprover(): void {
    const userId = this.candidateId();

    if (userId === null || this.isAddingApprover()) {
      return;
    }

    this.isAddingApprover.set(true);
    this.addApproverError.set(null);

    this.resourceService.addApprover(this.id(), userId)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.isAddingApprover.set(false);
          this.candidateId.set(null);
          this.toast.success('Responsable asignado.');
          this.loadApprovers();
        },
        error: (error: unknown) => {
          this.isAddingApprover.set(false);

          const apiError = describeApiError(error, {
            action: 'asignar responsables',
          });

          this.addApproverError.set(
            apiError.status === 422
              ? 'La identidad debe estar activa y tener permiso para aprobar accesos.'
              : apiError.message
          );
        },
      });
  }

  askRemoveApprover(approver: ResourceApprover): void {
    this.removeError.set(null);
    this.removing.set(approver);
  }

  confirmRemoveApprover(): void {
    const approver = this.removing();

    if (!approver || this.isRemoving()) {
      return;
    }

    this.isRemoving.set(true);
    this.removeError.set(null);

    this.resourceService.removeApprover(this.id(), approver.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.isRemoving.set(false);
          this.removing.set(null);
          this.toast.success(`${approver.name} ya no es responsable de este recurso.`);
          this.loadApprovers();
        },
        error: (error: unknown) => {
          this.isRemoving.set(false);
          this.removeError.set(
            describeApiError(error, { action: 'retirar responsables' }).message
          );
        },
      });
  }

  /*
  |--------------------------------------------------------------------------
  | EDICIÓN
  |--------------------------------------------------------------------------
  */

  saveEdit(): void {
    if (this.isSavingEdit()) {
      return;
    }

    const name = this.editName().trim();
    const code = this.editCode().trim().toUpperCase();
    const description = this.editDescription().trim();

    if (!name || !CODE_PATTERN.test(code)) {
      this.editError.set(
        'El nombre es obligatorio y el código solo admite mayúsculas, números, guion o guion bajo.'
      );
      return;
    }

    this.isSavingEdit.set(true);
    this.editError.set(null);

    this.resourceService.update(this.id(), {
      name,
      code,
      description: description === '' ? null : description,
    })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.isSavingEdit.set(false);
          this.setResource(response.data);
          this.toast.success('Recurso actualizado.');
        },
        error: (error: unknown) => {
          this.isSavingEdit.set(false);

          const apiError = describeApiError(error, {
            action: 'modificar recursos',
          });

          this.editError.set(
            apiError.status === 422
              ? 'Revisa los datos: el código podría estar en uso por otro recurso.'
              : apiError.message
          );
        },
      });
  }

  /*
  |--------------------------------------------------------------------------
  | DESACTIVACIÓN
  |--------------------------------------------------------------------------
  */

  confirmDeactivate(): void {
    if (this.isDeactivating()) {
      return;
    }

    this.isDeactivating.set(true);
    this.deactivateError.set(null);

    this.resourceService.deactivate(this.id())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.isDeactivating.set(false);
          this.deactivateOpen.set(false);
          this.setResource(response.data);
          this.toast.success(
            `Recurso desactivado. Accesos revocados: ${response.revoked_access_grants_count}.`
          );
          this.loadEligibility();
        },
        error: (error: unknown) => {
          this.isDeactivating.set(false);
          this.deactivateError.set(
            describeApiError(error, { action: 'desactivar recursos' }).message
          );
        },
      });
  }

  closeDeactivate(): void {
    if (!this.isDeactivating()) {
      this.deactivateOpen.set(false);
      this.deactivateError.set(null);
    }
  }
}
