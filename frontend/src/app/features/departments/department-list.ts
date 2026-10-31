import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';

import {
  NonNullableFormBuilder,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';

import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import { AuthService } from '../../core/auth/auth.service';

import {
  ApiError,
  describeApiError,
} from '../../core/http/api-error';

import { ActionDialog } from '../../shared/ui/action-dialog';
import { StatusBadge } from '../../shared/ui/status-badge';
import { ToastService } from '../../shared/ui/toast.service';

import {
  Department,
  DepartmentService,
} from './department.service';

/*
|--------------------------------------------------------------------------
| YESIAM - DEPARTAMENTOS
|--------------------------------------------------------------------------
|
| FASE 21
|
| - Consultar departamentos (departments.view).
| - Crear (departments.create).
| - Renombrar o recodificar (departments.update).
| - Desactivar (departments.deactivate).
|
| Al desactivar, Laravel revoca solo las
| concesiones que dependían de este
| departamento y lo audita.
|
*/

const CODE_PATTERN = /^[A-Z0-9_-]+$/;

@Component({
  selector: 'app-department-list',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ReactiveFormsModule,
    StatusBadge,
    ActionDialog,
  ],

  template: `
    <section class="page">

      <header class="page-header">
        <div>
          <h1>Departamentos</h1>
          <p>
            Los departamentos definen la elegibilidad de los recursos.
            Desactivar uno retira los accesos que dependían de él.
          </p>
        </div>
      </header>

      <div class="stack">

        @if (canCreate()) {
          <section class="panel" aria-labelledby="new-department-title">
            <div class="panel-header">
              <div>
                <h2 class="section-title" id="new-department-title">
                  Nuevo departamento
                </h2>
                <p class="section-hint">
                  El código usa mayúsculas, números, guion o guion bajo.
                </p>
              </div>
            </div>

            <form
              class="panel-body create-form"
              novalidate
              [formGroup]="createForm"
              (ngSubmit)="create()"
            >
              <div class="field">
                <label for="department-name">Nombre</label>
                <input
                  id="department-name"
                  formControlName="name"
                  maxlength="100"
                  autocomplete="off"
                  [attr.aria-invalid]="createErrors().name ? true : null"
                />
                @if (createErrors().name) {
                  <span class="field-error">{{ createErrors().name }}</span>
                }
              </div>

              <div class="field">
                <label for="department-code">Código</label>
                <input
                  id="department-code"
                  formControlName="code"
                  maxlength="20"
                  autocomplete="off"
                  (input)="upperCaseCode(createForm.controls.code)"
                  [attr.aria-invalid]="createErrors().code ? true : null"
                />
                @if (createErrors().code) {
                  <span class="field-error">{{ createErrors().code }}</span>
                }
              </div>

              <div class="create-actions">
                <button
                  type="submit"
                  class="btn btn-primary"
                  [disabled]="isCreating()"
                >
                  {{ isCreating() ? 'Creando...' : 'Crear departamento' }}
                </button>
              </div>

              @if (createError()) {
                <div class="notice error full-row" role="alert">
                  <strong>No se creó el departamento.</strong>
                  <p>{{ createError() }}</p>
                </div>
              }
            </form>
          </section>
        }

        <section class="panel" aria-labelledby="departments-title">
          <div class="panel-header">
            <h2 class="section-title" id="departments-title">
              Catálogo
            </h2>

            <span class="small muted">
              {{ activeCount() }} activos, {{ inactiveCount() }} inactivos
            </span>
          </div>

          @if (isLoading()) {
            <div class="empty-state" role="status">Cargando departamentos...</div>
          } @else if (loadError()) {
            <div class="panel-body">
              <div class="notice error" role="alert">
                <strong>No fue posible consultar los departamentos.</strong>
                <p>{{ loadError() }}</p>
                <button type="button" class="btn" (click)="load()">Reintentar</button>
              </div>
            </div>
          } @else if (departments().length === 0) {
            <div class="empty-state">
              <strong>Aún no hay departamentos</strong>
              Crea el primero para poder registrar identidades.
            </div>
          } @else {
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th scope="col">Departamento</th>
                    <th scope="col">Código</th>
                    <th scope="col">Estado</th>
                    <th scope="col"><span class="visually-hidden">Acciones</span></th>
                  </tr>
                </thead>
                <tbody>
                  @for (department of departments(); track department.id) {
                    @if (editingId() === department.id) {
                      <tr class="editing-row">
                        <td>
                          <input
                            class="input"
                            aria-label="Nombre del departamento"
                            maxlength="100"
                            [value]="editName()"
                            (input)="editName.set($any($event.target).value)"
                          />
                        </td>
                        <td>
                          <input
                            class="input code-input"
                            aria-label="Código del departamento"
                            maxlength="20"
                            [value]="editCode()"
                            (input)="editCode.set($any($event.target).value.toUpperCase())"
                          />
                        </td>
                        <td>
                          <app-status-badge kind="catalog" [value]="true" />
                        </td>
                        <td class="cell-actions">
                          <div class="actions row-actions">
                            <button
                              type="button"
                              class="btn btn-sm"
                              [disabled]="isSaving()"
                              (click)="cancelEdit()"
                            >
                              Cancelar
                            </button>
                            <button
                              type="button"
                              class="btn btn-sm btn-primary"
                              [disabled]="isSaving()"
                              (click)="saveEdit(department)"
                            >
                              {{ isSaving() ? 'Guardando...' : 'Guardar' }}
                            </button>
                          </div>
                        </td>
                      </tr>
                      @if (editError()) {
                        <tr>
                          <td colspan="4">
                            <div class="notice error" role="alert">
                              <strong>No se guardaron los cambios.</strong>
                              <p>{{ editError() }}</p>
                            </div>
                          </td>
                        </tr>
                      }
                    } @else {
                      <tr [class.inactive-row]="!isActive(department)">
                        <td class="cell-primary">{{ department.name }}</td>
                        <td><span class="code">{{ department.code }}</span></td>
                        <td>
                          <app-status-badge
                            kind="catalog"
                            [value]="isActive(department)"
                          />
                        </td>
                        <td class="cell-actions">
                          @if (isActive(department)) {
                            <div class="actions row-actions">
                              @if (canUpdate()) {
                                <button
                                  type="button"
                                  class="btn btn-sm btn-ghost"
                                  (click)="startEdit(department)"
                                >
                                  Editar
                                </button>
                              }
                              @if (canDeactivate()) {
                                <button
                                  type="button"
                                  class="btn btn-sm btn-danger-outline"
                                  (click)="askDeactivate(department)"
                                >
                                  Desactivar
                                </button>
                              }
                            </div>
                          }
                        </td>
                      </tr>
                    }
                  }
                </tbody>
              </table>
            </div>
          }
        </section>

      </div>

      <app-action-dialog
        [open]="deactivating() !== null"
        title="Desactivar departamento"
        [description]="deactivateDescription()"
        confirmLabel="Desactivar departamento"
        busyLabel="Desactivando..."
        tone="danger"
        [confirmWord]="deactivating()?.code ?? null"
        [busy]="isDeactivating()"
        [errorMessage]="deactivateError()"
        (confirmed)="confirmDeactivate()"
        (closed)="deactivating.set(null)"
      />

    </section>
  `,

  styles: [`
    .create-form {
      display: grid;
      grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) auto;
      gap: 16px;
      align-items: start;
    }

    .create-actions {
      padding-top: 25px;
    }

    .full-row {
      grid-column: 1 / -1;
    }

    .code-input {
      max-width: 160px;
      font-family: var(--font-mono);
    }

    .row-actions {
      justify-content: flex-end;
    }

    .inactive-row td {
      color: var(--text-muted);
    }

    @media (max-width: 760px) {
      .create-form {
        grid-template-columns: minmax(0, 1fr);
      }

      .create-actions {
        padding-top: 0;
      }
    }
  `],
})
export class DepartmentList {

  private readonly departmentService = inject(DepartmentService);
  private readonly auth = inject(AuthService);
  private readonly toast = inject(ToastService);
  private readonly formBuilder = inject(NonNullableFormBuilder);
  private readonly destroyRef = inject(DestroyRef);

  readonly canCreate = computed(() => this.auth.can('departments.create'));
  readonly canUpdate = computed(() => this.auth.can('departments.update'));
  readonly canDeactivate = computed(() => this.auth.can('departments.deactivate'));

  readonly departments = signal<Department[]>([]);
  readonly isLoading = signal(false);
  readonly loadError = signal<string | null>(null);

  readonly activeCount = computed(() =>
    this.departments().filter((department) => this.isActive(department)).length
  );

  readonly inactiveCount = computed(() =>
    this.departments().length - this.activeCount()
  );

  /*
   * CREAR
   */
  readonly createForm = this.formBuilder.group({
    name: this.formBuilder.control('', [
      Validators.required,
      Validators.maxLength(100),
    ]),
    code: this.formBuilder.control('', [
      Validators.required,
      Validators.maxLength(20),
      Validators.pattern(CODE_PATTERN),
    ]),
  });

  readonly isCreating = signal(false);
  readonly createError = signal<string | null>(null);
  readonly createErrors = signal<{ name?: string; code?: string }>({});

  /*
   * EDITAR
   */
  readonly editingId = signal<number | null>(null);
  readonly editName = signal('');
  readonly editCode = signal('');
  readonly isSaving = signal(false);
  readonly editError = signal<string | null>(null);

  /*
   * DESACTIVAR
   */
  readonly deactivating = signal<Department | null>(null);
  readonly isDeactivating = signal(false);
  readonly deactivateError = signal<string | null>(null);

  readonly deactivateDescription = computed(() => {
    const department = this.deactivating();

    return department
      ? `${department.name} dejará de aceptar nuevas identidades y se revocarán los accesos de sus miembros que dependían de él. Esta acción no se puede deshacer desde YESIAM.`
      : '';
  });

  constructor() {
    this.load();
  }

  isActive(department: Department): boolean {
    return department.is_active === true || department.is_active === 1;
  }

  load(): void {
    this.isLoading.set(true);
    this.loadError.set(null);

    this.departmentService.list()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          const sorted = [...response.data].sort((a, b) =>
            Number(this.isActive(b)) - Number(this.isActive(a))
            || a.name.localeCompare(b.name, 'es')
          );

          this.departments.set(sorted);
          this.isLoading.set(false);
        },
        error: (error: unknown) => {
          this.loadError.set(
            describeApiError(error, {
              action: 'consultar los departamentos',
            }).message
          );
          this.isLoading.set(false);
        },
      });
  }

  upperCaseCode(control: { value: string; setValue: (value: string) => void }): void {
    const upper = control.value.toUpperCase();

    if (upper !== control.value) {
      control.setValue(upper);
    }
  }

  create(): void {
    if (this.isCreating()) {
      return;
    }

    this.createError.set(null);

    const value = this.createForm.getRawValue();
    const name = value.name.trim();
    const code = value.code.trim().toUpperCase();

    const errors: { name?: string; code?: string } = {};

    if (!name) {
      errors.name = 'Escribe el nombre del departamento.';
    }

    if (!code) {
      errors.code = 'Escribe un código.';
    } else if (!CODE_PATTERN.test(code)) {
      errors.code = 'Usa solo mayúsculas, números, guion o guion bajo.';
    }

    this.createErrors.set(errors);

    if (Object.keys(errors).length > 0) {
      return;
    }

    this.isCreating.set(true);

    this.departmentService.create({ name, code })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.isCreating.set(false);
          this.createForm.reset();
          this.toast.success(`Departamento ${response.data.name} creado.`);
          this.load();
        },
        error: (error: unknown) => {
          this.isCreating.set(false);
          this.applyMutationError(
            describeApiError(error, { action: 'crear departamentos' }),
            this.createErrors,
            this.createError
          );
        },
      });
  }

  startEdit(department: Department): void {
    this.editingId.set(department.id);
    this.editName.set(department.name);
    this.editCode.set(department.code);
    this.editError.set(null);
  }

  cancelEdit(): void {
    this.editingId.set(null);
    this.editError.set(null);
  }

  saveEdit(department: Department): void {
    if (this.isSaving()) {
      return;
    }

    const name = this.editName().trim();
    const code = this.editCode().trim().toUpperCase();

    if (!name || !CODE_PATTERN.test(code)) {
      this.editError.set(
        'El nombre es obligatorio y el código solo admite mayúsculas, números, guion o guion bajo.'
      );
      return;
    }

    if (name === department.name && code === department.code) {
      this.cancelEdit();
      return;
    }

    this.isSaving.set(true);
    this.editError.set(null);

    this.departmentService.update(department.id, { name, code })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: () => {
          this.isSaving.set(false);
          this.editingId.set(null);
          this.toast.success('Departamento actualizado.');
          this.load();
        },
        error: (error: unknown) => {
          this.isSaving.set(false);

          const apiError = describeApiError(error, {
            action: 'modificar departamentos',
          });

          this.editError.set(
            apiError.status === 422
              ? this.validationSummary(apiError)
              : apiError.message
          );
        },
      });
  }

  askDeactivate(department: Department): void {
    this.deactivateError.set(null);
    this.deactivating.set(department);
  }

  confirmDeactivate(): void {
    const department = this.deactivating();

    if (!department || this.isDeactivating()) {
      return;
    }

    this.isDeactivating.set(true);
    this.deactivateError.set(null);

    this.departmentService.deactivate(department.id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.isDeactivating.set(false);
          this.deactivating.set(null);

          const revoked = response.revoked_access_grants_count;

          this.toast.success(
            revoked > 0
              ? `${department.name} desactivado. Se revocaron ${revoked} accesos dependientes.`
              : `${department.name} desactivado. No había accesos dependientes.`
          );

          this.load();
        },
        error: (error: unknown) => {
          this.isDeactivating.set(false);
          this.deactivateError.set(
            describeApiError(error, {
              action: 'desactivar departamentos',
            }).message
          );
        },
      });
  }

  private applyMutationError(
    apiError: ApiError,
    fieldTarget: { set: (value: { name?: string; code?: string }) => void },
    messageTarget: { set: (value: string | null) => void }
  ): void {
    if (apiError.status === 422) {
      const fields: { name?: string; code?: string } = {};

      if (apiError.fieldErrors['name']) {
        fields.name = 'El nombre no es válido o ya existe.';
      }

      if (apiError.fieldErrors['code']) {
        fields.code = 'El código no es válido o ya existe.';
      }

      fieldTarget.set(fields);
    }

    messageTarget.set(apiError.message);
  }

  private validationSummary(apiError: ApiError): string {
    const parts: string[] = [];

    if (apiError.fieldErrors['name']) {
      parts.push('el nombre no es válido o ya existe');
    }

    if (apiError.fieldErrors['code']) {
      parts.push('el código no es válido o ya existe');
    }

    return parts.length > 0
      ? `Revisa los datos: ${parts.join(' y ')}.`
      : apiError.message;
  }
}
