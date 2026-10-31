import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  inject,
  signal,
} from '@angular/core';

import {
  AbstractControl,
  FormControl,
  NonNullableFormBuilder,
  ReactiveFormsModule,
  ValidationErrors,
  Validators,
} from '@angular/forms';

import {
  HttpErrorResponse,
} from '@angular/common/http';

import {
  RouterLink,
} from '@angular/router';

import {
  takeUntilDestroyed,
} from '@angular/core/rxjs-interop';

import {
  Subscription,
} from 'rxjs';

import {
  CreatedIdentity,
  IdentityService,
} from './identity.service';

import {
  Department,
  DepartmentService,
} from '../departments/department.service';

/*
|--------------------------------------------------------------------------
| YESIAM - JOINER: ALTA DE IDENTIDADES
|--------------------------------------------------------------------------
|
| FASE 19.5.4.1
|
| Formulario para registrar nuevas identidades.
|
| 19.5.4.1: el correo se valida sin
| los espacios al inicio y al final.
|
| Flujo:
|
| 1. Cargar departamentos activos.
| 2. Capturar nombre, correo y departamento.
| 3. Validar el formulario en Angular.
| 4. Enviar POST /api/users.
| 5. Mostrar la identidad creada por Laravel.
|
| SEGURIDAD:
|
| - Angular solo envía name, email y department_id.
| - No envía password, status ni roles.
| - Laravel crea la identidad suspendida,
|   con rol employee y contraseña aleatoria.
| - Laravel vuelve a validar que el
|   departamento esté activo.
| - Las validaciones de Angular mejoran la
|   experiencia, pero no sustituyen al backend.
|
*/

/*
 * Campos que pueden recibir errores
 * de validación desde Laravel (422).
 */
type JoinerField =
  | 'name'
  | 'email'
  | 'department_id';

/*
 * Rechaza valores formados únicamente
 * por espacios en blanco.
 */
function notBlank(
  control: AbstractControl<string>
): ValidationErrors | null {
  const value = control.value ?? '';

  return value.trim().length > 0
    ? null
    : { blank: true };
}

/*
 * Valida el formato del correo ignorando
 * los espacios al inicio y al final.
 *
 * Así, un correo pegado con un espacio
 * extra no se rechaza, y se envía
 * recortado a Laravel.
 *
 * Los valores vacíos los manejan
 * required y notBlank.
 */
function trimmedEmail(
  control: AbstractControl<string>
): ValidationErrors | null {
  const value = (control.value ?? '').trim();

  if (value === '') {
    return null;
  }

  return Validators.email(
    new FormControl(value)
  );
}

@Component({
  selector: 'app-identity-create',
  standalone: true,

  imports: [
    ReactiveFormsModule,
    RouterLink,
  ],

  changeDetection: ChangeDetectionStrategy.OnPush,

  template: `
    <section
      class="joiner"
      aria-labelledby="joiner-title"
    >

      <header class="heading">
        <a
          class="back-link"
          routerLink="/identities"
        >
          ← Volver al directorio
        </a>

        <h1 id="joiner-title">
          Nueva identidad
        </h1>

        <p>
          Proceso Joiner: registra el alta de
          una persona en la organización.
        </p>
      </header>

      <!--
      ------------------------------------------------------
      RESULTADO EXITOSO
      ------------------------------------------------------
      -->

      @if (createdIdentity(); as identity) {

        <div
          class="notice success"
          role="status"
        >

          <strong>
            Identidad creada correctamente.
          </strong>

          <dl class="summary">
            <div>
              <dt>Nombre</dt>
              <dd>{{ identity.name }}</dd>
            </div>

            <div>
              <dt>Correo</dt>
              <dd>{{ identity.email }}</dd>
            </div>

            <div>
              <dt>Departamento</dt>
              <dd>{{ departmentName(identity.department_id) }}</dd>
            </div>

            <div>
              <dt>Estado</dt>
              <dd>
                <span class="status suspended">
                  Suspendido
                </span>
              </dd>
            </div>

            <div>
              <dt>Rol inicial</dt>
              <dd>{{ identity.roles.join(', ') }}</dd>
            </div>
          </dl>

          <p>
            La identidad aún no puede iniciar sesión.
            Permanecerá suspendida hasta que sea
            activada mediante el proceso correspondiente.
          </p>

          <div class="actions">
            <a
              class="primary-button"
              routerLink="/identities"
            >
              Ver directorio
            </a>

            <button
              type="button"
              class="secondary-button"
              (click)="startNew()"
            >
              Registrar otra identidad
            </button>
          </div>

        </div>

      } @else {

        <!--
        ------------------------------------------------------
        CARGA DE DEPARTAMENTOS
        ------------------------------------------------------
        -->

        @if (isLoadingDepartments()) {

          <div class="notice" role="status">
            Cargando departamentos...
          </div>

        } @else if (departmentsErrorMessage()) {

          <div class="notice error" role="alert">

            <strong>
              No fue posible cargar los departamentos.
            </strong>

            <p>
              {{ departmentsErrorMessage() }}
            </p>

            @if (departmentsErrorStatus() === 401) {

              <a routerLink="/sign-in">
                Ir al inicio de sesión
              </a>

            } @else if (departmentsErrorStatus() !== 403) {

              <button
                type="button"
                class="secondary-button"
                (click)="loadDepartments()"
              >
                Reintentar
              </button>

            }

          </div>

        } @else if (departments().length === 0) {

          <div class="notice" role="status">
            No existen departamentos activos.
            Activa o crea un departamento antes
            de registrar identidades.
          </div>

        } @else {

          <!--
          ------------------------------------------------------
          FORMULARIO JOINER
          ------------------------------------------------------
          -->

          <div class="info">
            La nueva identidad se creará en estado
            <strong>suspendido</strong>, con el rol
            <strong>employee</strong> y una contraseña
            aleatoria que nadie conoce. Estos valores
            los define el servidor.
          </div>

          @if (submitErrorMessage()) {

            <div class="notice error" role="alert">

              <strong>
                No fue posible crear la identidad.
              </strong>

              <p>
                {{ submitErrorMessage() }}
              </p>

              @if (
                submitErrorStatus() === 401 ||
                submitErrorStatus() === 419
              ) {

                <a routerLink="/sign-in">
                  Ir al inicio de sesión
                </a>

              }

            </div>

          }

          <form
            class="form"
            novalidate
            [formGroup]="form"
            (ngSubmit)="submit()"
          >

            <fieldset [disabled]="isSubmitting()">

              <legend class="visually-hidden">
                Datos de la nueva identidad
              </legend>

              <!-- NOMBRE -->

              <div class="field">
                <label for="joiner-name">
                  Nombre completo
                </label>

                <input
                  id="joiner-name"
                  type="text"
                  formControlName="name"
                  maxlength="255"
                  autocomplete="off"
                  [attr.aria-invalid]="hasError('name')"
                  [attr.aria-describedby]="hasError('name') ? 'joiner-name-error' : null"
                  (input)="clearServerError('name')"
                />

                @if (hasError('name')) {
                  <span
                    id="joiner-name-error"
                    class="field-error"
                  >
                    {{ errorFor('name') }}
                  </span>
                }
              </div>

              <!-- CORREO -->

              <div class="field">
                <label for="joiner-email">
                  Correo electrónico
                </label>

                <input
                  id="joiner-email"
                  type="email"
                  formControlName="email"
                  maxlength="255"
                  autocomplete="off"
                  [attr.aria-invalid]="hasError('email')"
                  [attr.aria-describedby]="hasError('email') ? 'joiner-email-error' : null"
                  (input)="clearServerError('email')"
                />

                @if (hasError('email')) {
                  <span
                    id="joiner-email-error"
                    class="field-error"
                  >
                    {{ errorFor('email') }}
                  </span>
                }
              </div>

              <!-- DEPARTAMENTO -->

              <div class="field">
                <label for="joiner-department">
                  Departamento
                </label>

                <select
                  id="joiner-department"
                  formControlName="department_id"
                  [attr.aria-invalid]="hasError('department_id')"
                  [attr.aria-describedby]="hasError('department_id') ? 'joiner-department-error' : null"
                  (change)="clearServerError('department_id')"
                >
                  <option [ngValue]="null" disabled>
                    Selecciona un departamento
                  </option>

                  @for (
                    department of departments();
                    track department.id
                  ) {
                    <option [ngValue]="department.id">
                      {{ department.name }} ({{ department.code }})
                    </option>
                  }
                </select>

                @if (hasError('department_id')) {
                  <span
                    id="joiner-department-error"
                    class="field-error"
                  >
                    {{ errorFor('department_id') }}
                  </span>
                }
              </div>

              <div class="actions">
                <button
                  type="submit"
                  class="primary-button"
                  [disabled]="isSubmitting()"
                >
                  @if (isSubmitting()) {
                    Creando identidad...
                  } @else {
                    Crear identidad
                  }
                </button>

                <a
                  class="secondary-button"
                  routerLink="/identities"
                >
                  Cancelar
                </a>
              </div>

            </fieldset>

          </form>

        }

      }

    </section>
  `,

  styles: [`
    :host {
      display: block;
    }

    .joiner {
      max-width: 720px;
      margin: 0 auto;
      padding: 24px;
      color: inherit;
    }

    .heading {
      margin-bottom: 24px;
    }

    .back-link {
      display: inline-block;
      margin-bottom: 12px;
      font-size: 14px;
      color: #2563eb;
      text-decoration: none;
    }

    h1 {
      margin: 0 0 8px;
      font-size: 26px;
      font-weight: 700;
    }

    .heading p {
      margin: 0;
      color: #64748b;
    }

    .info {
      padding: 16px 20px;
      margin-bottom: 20px;
      border: 1px solid #bfdbfe;
      border-radius: 12px;
      background: rgba(37, 99, 235, 0.06);
      font-size: 14px;
      line-height: 1.5;
    }

    .form {
      padding: 24px;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
    }

    fieldset {
      display: flex;
      flex-direction: column;
      gap: 20px;
      margin: 0;
      padding: 0;
      border: 0;
      min-width: 0;
    }

    .field {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    label {
      font-size: 13px;
      font-weight: 600;
    }

    input,
    select {
      width: 100%;
      box-sizing: border-box;
      min-height: 40px;
      padding: 8px 12px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      background: transparent;
      color: inherit;
      font: inherit;
    }

    input[aria-invalid='true'],
    select[aria-invalid='true'] {
      border-color: #dc2626;
    }

    select option {
      color: #0f172a;
      background: white;
    }

    .field-error {
      font-size: 13px;
      color: #dc2626;
    }

    .actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px;
    }

    button,
    .primary-button,
    .secondary-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      box-sizing: border-box;
      min-height: 40px;
      padding: 8px 14px;
      border-radius: 8px;
      font: inherit;
      text-decoration: none;
      cursor: pointer;
    }

    button:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .primary-button {
      border: 1px solid #2563eb;
      color: #fff;
      background: #2563eb;
    }

    .secondary-button {
      border: 1px solid #cbd5e1;
      background: transparent;
      color: inherit;
    }

    .notice {
      padding: 24px;
      margin-bottom: 20px;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
    }

    .notice.error {
      border-color: #fca5a5;
    }

    .notice.success {
      border-color: #86efac;
    }

    .notice p {
      margin: 12px 0;
    }

    .summary {
      display: grid;
      gap: 12px;
      margin: 16px 0;
    }

    .summary div {
      display: grid;
      grid-template-columns: 140px 1fr;
      gap: 12px;
    }

    .summary dt {
      font-size: 13px;
      color: #64748b;
    }

    .summary dd {
      margin: 0;
      overflow-wrap: anywhere;
    }

    .status {
      display: inline-block;
      padding: 5px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 600;
    }

    .status.suspended {
      background: #fef3c7;
      color: #92400e;
    }

    .visually-hidden {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip: rect(0 0 0 0);
      white-space: nowrap;
    }

    @media (max-width: 640px) {
      .joiner {
        padding: 16px;
      }

      .form {
        padding: 16px;
      }

      .summary div {
        grid-template-columns: 1fr;
        gap: 4px;
      }

      .actions > * {
        width: 100%;
      }
    }
  `],
})

export class IdentityCreate {

  private readonly identityService = inject(
    IdentityService
  );

  private readonly departmentService = inject(
    DepartmentService
  );

  private readonly formBuilder = inject(
    NonNullableFormBuilder
  );

  private readonly destroyRef = inject(
    DestroyRef
  );

  /*
  |--------------------------------------------------------------------------
  | FORMULARIO
  |--------------------------------------------------------------------------
  |
  | Los límites coinciden con
  | UserController::store():
  |
  | name           required | string | max:255
  | email          required | email  | max:255 | unique
  |
  | Angular valida el correo recortado,
  | porque también lo envía recortado.
  | department_id  required | exists (activo)
  |
  | La unicidad del correo y el estado
  | del departamento solo los puede
  | comprobar Laravel.
  |
  */

  readonly form = this.formBuilder.group({
    name: this.formBuilder.control('', [
      Validators.required,
      notBlank,
      Validators.maxLength(255),
    ]),

    email: this.formBuilder.control('', [
      Validators.required,
      notBlank,
      trimmedEmail,
      Validators.maxLength(255),
    ]),

    department_id: this.formBuilder.control<number | null>(
      null,
      [Validators.required]
    ),
  });

  /*
  |--------------------------------------------------------------------------
  | ESTADO: DEPARTAMENTOS
  |--------------------------------------------------------------------------
  */

  readonly departments = signal<Department[]>([]);

  readonly isLoadingDepartments = signal(false);

  readonly departmentsErrorMessage = signal<string | null>(
    null
  );

  readonly departmentsErrorStatus = signal<number | null>(
    null
  );

  /*
  |--------------------------------------------------------------------------
  | ESTADO: ENVÍO
  |--------------------------------------------------------------------------
  */

  readonly isSubmitting = signal(false);

  readonly submitErrorMessage = signal<string | null>(
    null
  );

  readonly submitErrorStatus = signal<number | null>(
    null
  );

  /*
   * Campos rechazados por Laravel (422).
   */
  readonly serverErrors = signal<
    Partial<Record<JoinerField, string>>
  >({});

  /*
   * Identidad devuelta por Laravel
   * después de una creación exitosa.
   */
  readonly createdIdentity = signal<CreatedIdentity | null>(
    null
  );

  private departmentsRequest?: Subscription;

  /*
  |--------------------------------------------------------------------------
  | CARGA INICIAL
  |--------------------------------------------------------------------------
  */

  constructor() {
    this.loadDepartments();
  }

  /*
  |--------------------------------------------------------------------------
  | CARGAR DEPARTAMENTOS ACTIVOS
  |--------------------------------------------------------------------------
  */

  loadDepartments(): void {

    this.departmentsRequest?.unsubscribe();

    this.isLoadingDepartments.set(true);

    this.departmentsErrorMessage.set(null);
    this.departmentsErrorStatus.set(null);

    this.departmentsRequest = this.departmentService
      .listActive()
      .pipe(
        takeUntilDestroyed(this.destroyRef)
      )
      .subscribe({

        next: (departments) => {
          this.departments.set(departments);

          /*
           * Si el departamento elegido ya no
           * está activo, limpiamos la selección.
           */
          const selected =
            this.form.controls.department_id.value;

          if (
            selected !== null &&
            !departments.some(
              (department) => department.id === selected
            )
          ) {
            this.form.controls.department_id.setValue(null);
          }

          this.isLoadingDepartments.set(false);
        },

        error: (error: unknown) => {
          this.departments.set([]);

          this.handleDepartmentsError(error);

          this.isLoadingDepartments.set(false);
        },

      });
  }

  /*
  |--------------------------------------------------------------------------
  | ENVIAR JOINER
  |--------------------------------------------------------------------------
  */

  submit(): void {

    /*
     * Evitar envíos dobles.
     */
    if (this.isSubmitting()) {
      return;
    }

    this.submitErrorMessage.set(null);
    this.submitErrorStatus.set(null);
    this.serverErrors.set({});

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const value = this.form.getRawValue();

    /*
     * department_id es obligatorio,
     * pero TypeScript necesita confirmarlo.
     */
    if (value.department_id === null) {
      this.form.controls.department_id.markAsTouched();
      return;
    }

    this.isSubmitting.set(true);

    /*
     * Solo enviamos los tres campos
     * aceptados por Laravel.
     */
    this.identityService
      .create({
        name: value.name.trim(),
        email: value.email.trim(),
        department_id: value.department_id,
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef)
      )
      .subscribe({

        next: (response) => {
          this.createdIdentity.set(response.data);

          this.isSubmitting.set(false);
        },

        error: (error: unknown) => {
          this.handleSubmitError(error);

          this.isSubmitting.set(false);
        },

      });
  }

  /*
  |--------------------------------------------------------------------------
  | REGISTRAR OTRA IDENTIDAD
  |--------------------------------------------------------------------------
  */

  startNew(): void {
    this.createdIdentity.set(null);

    this.submitErrorMessage.set(null);
    this.submitErrorStatus.set(null);
    this.serverErrors.set({});

    this.form.reset();
  }

  /*
  |--------------------------------------------------------------------------
  | AYUDAS PARA LA PLANTILLA
  |--------------------------------------------------------------------------
  */

  hasError(field: JoinerField): boolean {
    if (this.serverErrors()[field]) {
      return true;
    }

    const control = this.form.controls[field];

    return control.invalid && control.touched;
  }

  errorFor(field: JoinerField): string | null {

    const serverError = this.serverErrors()[field];

    if (serverError) {
      return serverError;
    }

    const control = this.form.controls[field];

    if (!control.invalid || !control.touched) {
      return null;
    }

    switch (field) {

      case 'name':
        if (control.hasError('maxlength')) {
          return 'El nombre no puede superar 255 caracteres.';
        }

        return 'Escribe el nombre completo.';

      case 'email':
        if (
          control.hasError('required') ||
          control.hasError('blank')
        ) {
          return 'Escribe el correo electrónico.';
        }

        if (control.hasError('maxlength')) {
          return 'El correo no puede superar 255 caracteres.';
        }

        return 'Escribe un correo electrónico válido.';

      case 'department_id':
        return 'Selecciona un departamento.';
    }
  }

  clearServerError(field: JoinerField): void {
    if (!this.serverErrors()[field]) {
      return;
    }

    const remaining = { ...this.serverErrors() };

    delete remaining[field];

    this.serverErrors.set(remaining);
  }

  departmentName(departmentId: number | null): string {
    const department = this.departments().find(
      (item) => item.id === departmentId
    );

    return department
      ? department.name
      : 'Sin departamento';
  }

  /*
  |--------------------------------------------------------------------------
  | ERRORES: DEPARTAMENTOS
  |--------------------------------------------------------------------------
  */

  private handleDepartmentsError(error: unknown): void {

    if (!(error instanceof HttpErrorResponse)) {
      this.departmentsErrorStatus.set(null);

      this.departmentsErrorMessage.set(
        'Ocurrió un error inesperado.'
      );

      return;
    }

    this.departmentsErrorStatus.set(error.status);

    switch (error.status) {

      case 401:
        this.departmentsErrorMessage.set(
          'Tu sesión no está disponible. Inicia sesión nuevamente.'
        );
        break;

      case 403:
        this.departmentsErrorMessage.set(
          'No tienes permisos para consultar los departamentos.'
        );
        break;

      case 0:
        this.departmentsErrorMessage.set(
          'No fue posible conectar con YESIAM. Verifica que Laravel esté disponible.'
        );
        break;

      default:
        this.departmentsErrorMessage.set(
          'El servidor no pudo completar la consulta.'
        );
        break;
    }
  }

  /*
  |--------------------------------------------------------------------------
  | ERRORES: CREACIÓN
  |--------------------------------------------------------------------------
  |
  | No mostramos mensajes internos del backend.
  |
  | 401: sesión no válida.
  | 403: falta el permiso users.manage.
  | 419: token CSRF vencido.
  | 422: datos rechazados por Laravel.
  |   0: error de conexión.
  |
  */

  private handleSubmitError(error: unknown): void {

    if (!(error instanceof HttpErrorResponse)) {
      this.submitErrorStatus.set(null);

      this.submitErrorMessage.set(
        'Ocurrió un error inesperado.'
      );

      return;
    }

    this.submitErrorStatus.set(error.status);

    switch (error.status) {

      case 401:
        this.submitErrorMessage.set(
          'Tu sesión no está disponible. Inicia sesión nuevamente.'
        );
        break;

      case 403:
        this.submitErrorMessage.set(
          'No tienes permisos para registrar identidades.'
        );
        break;

      case 419:
        this.submitErrorMessage.set(
          'La sesión de seguridad expiró. Inicia sesión nuevamente.'
        );
        break;

      case 422:
        this.applyValidationErrors(error);
        break;

      case 0:
        this.submitErrorMessage.set(
          'No fue posible conectar con YESIAM. Verifica que Laravel esté disponible.'
        );
        break;

      default:
        this.submitErrorMessage.set(
          'El servidor no pudo crear la identidad.'
        );
        break;
    }
  }

  /*
  |--------------------------------------------------------------------------
  | ERRORES DE VALIDACIÓN (422)
  |--------------------------------------------------------------------------
  |
  | Usamos los nombres de campo devueltos
  | por Laravel, pero mostramos mensajes
  | propios en español.
  |
  */

  private applyValidationErrors(
    error: HttpErrorResponse
  ): void {

    const fields: Record<string, unknown> =
      error.error?.errors ?? {};

    const errors: Partial<Record<JoinerField, string>> = {};

    if ('name' in fields) {
      errors.name =
        'El nombre no es válido.';
    }

    if ('email' in fields) {
      errors.email =
        'El correo no es válido o ya está registrado.';
    }

    if ('department_id' in fields) {
      errors.department_id =
        'El departamento ya no existe o no está activo.';

      /*
       * Actualizamos el catálogo por si
       * el departamento fue desactivado.
       */
      this.loadDepartments();
    }

    this.serverErrors.set(errors);

    this.submitErrorMessage.set(
      Object.keys(errors).length > 0
        ? 'Revisa los campos marcados.'
        : 'Los datos enviados no son válidos.'
    );
  }
}