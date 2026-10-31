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

import { Router, RouterLink } from '@angular/router';

import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import { AuthService } from '../../core/auth/auth.service';
import { describeApiError } from '../../core/http/api-error';

import { StatusBadge } from '../../shared/ui/status-badge';
import { ToastService } from '../../shared/ui/toast.service';

import {
  Resource,
  ResourceService,
} from './resource.service';

/*
|--------------------------------------------------------------------------
| YESIAM - CATÁLOGO DE RECURSOS
|--------------------------------------------------------------------------
|
| FASE 21
|
| - Cualquier perfil con resources.view
|   consulta el catálogo activo.
| - Quien tiene resources.manage registra
|   recursos y puede ver también los inactivos.
|
*/

const CODE_PATTERN = /^[A-Z0-9_-]+$/;

@Component({
  selector: 'app-resource-list',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ReactiveFormsModule,
    RouterLink,
    StatusBadge,
  ],

  template: `
    <section class="page">

      <header class="page-header">
        <div>
          <h1>Recursos</h1>
          <p>
            Sistemas y servicios a los que se puede solicitar acceso.
            Cada recurso define quién es elegible y quién aprueba.
          </p>
        </div>

        @if (canManage()) {
          <button
            type="button"
            class="btn btn-primary"
            [attr.aria-expanded]="showCreate()"
            aria-controls="new-resource-panel"
            (click)="showCreate.set(!showCreate())"
          >
            {{ showCreate() ? 'Cerrar formulario' : '+ Nuevo recurso' }}
          </button>
        }
      </header>

      <div class="stack">

        @if (canManage() && showCreate()) {
          <section
            class="panel"
            id="new-resource-panel"
            aria-labelledby="new-resource-title"
          >
            <div class="panel-header">
              <div>
                <h2 class="section-title" id="new-resource-title">Nuevo recurso</h2>
                <p class="section-hint">
                  Se crea activo y sin restricción departamental. Después
                  podrás definir elegibilidad y responsables.
                </p>
              </div>
            </div>

            <form
              class="panel-body stack"
              novalidate
              [formGroup]="form"
              (ngSubmit)="create()"
            >
              <div class="form-grid">
                <div class="field">
                  <label for="resource-name">Nombre</label>
                  <input
                    id="resource-name"
                    formControlName="name"
                    maxlength="150"
                    autocomplete="off"
                    [attr.aria-invalid]="fieldError('name') ? true : null"
                  />
                  @if (fieldError('name')) {
                    <span class="field-error">{{ fieldError('name') }}</span>
                  }
                </div>

                <div class="field">
                  <label for="resource-code">Código</label>
                  <input
                    id="resource-code"
                    formControlName="code"
                    maxlength="50"
                    autocomplete="off"
                    (input)="upperCaseCode()"
                    [attr.aria-invalid]="fieldError('code') ? true : null"
                  />
                  <span class="field-help">Mayúsculas, números, guion o guion bajo.</span>
                  @if (fieldError('code')) {
                    <span class="field-error">{{ fieldError('code') }}</span>
                  }
                </div>
              </div>

              <div class="field">
                <label for="resource-description">Descripción (opcional)</label>
                <textarea
                  id="resource-description"
                  formControlName="description"
                  maxlength="2000"
                ></textarea>
              </div>

              @if (createError()) {
                <div class="notice error" role="alert">
                  <strong>No se creó el recurso.</strong>
                  <p>{{ createError() }}</p>
                </div>
              }

              <div class="actions">
                <button
                  type="submit"
                  class="btn btn-primary"
                  [disabled]="isCreating()"
                >
                  {{ isCreating() ? 'Creando...' : 'Crear recurso' }}
                </button>
              </div>
            </form>
          </section>
        }

        <section class="panel" aria-labelledby="catalog-title">
          <div class="panel-header">
            <h2 class="section-title" id="catalog-title">Catálogo</h2>

            @if (canManage()) {
              <label class="check small">
                <input
                  type="checkbox"
                  [checked]="includeInactive()"
                  (change)="toggleInactive()"
                />
                Mostrar inactivos
              </label>
            }
          </div>

          @if (isLoading()) {
            <div class="empty-state" role="status">Cargando recursos...</div>
          } @else if (loadError()) {
            <div class="panel-body">
              <div class="notice error" role="alert">
                <strong>No fue posible consultar los recursos.</strong>
                <p>{{ loadError() }}</p>
                <button type="button" class="btn" (click)="load()">Reintentar</button>
              </div>
            </div>
          } @else if (resources().length === 0) {
            <div class="empty-state">
              <strong>El catálogo está vacío</strong>
              @if (canManage()) {
                Registra el primer recurso con el botón de arriba.
              } @else {
                Todavía no hay recursos disponibles para solicitar.
              }
            </div>
          } @else {
            <div class="table-wrap">
              <table class="data-table">
                <thead>
                  <tr>
                    <th scope="col">Recurso</th>
                    <th scope="col">Código</th>
                    <th scope="col">Elegibilidad</th>
                    <th scope="col">Estado</th>
                    <th scope="col"><span class="visually-hidden">Acciones</span></th>
                  </tr>
                </thead>
                <tbody>
                  @for (resource of resources(); track resource.id) {
                    <tr>
                      <td>
                        <a class="row-link" [routerLink]="['/resources', resource.id]">
                          <div class="cell-primary">{{ resource.name }}</div>
                          @if (resource.description) {
                            <div class="cell-secondary description">{{ resource.description }}</div>
                          }
                        </a>
                      </td>
                      <td><span class="code">{{ resource.code }}</span></td>
                      <td class="small">
                        {{ resource.department_restricted ? 'Departamentos específicos' : 'Cualquier departamento' }}
                      </td>
                      <td>
                        <app-status-badge kind="catalog" [value]="resource.is_active" />
                      </td>
                      <td class="cell-actions">
                        @if (canRequest() && resource.is_active) {
                          <a
                            class="btn btn-sm btn-ghost"
                            routerLink="/requests"
                            [queryParams]="{ resource: resource.id }"
                          >
                            Solicitar
                          </a>
                        }
                      </td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
          }
        </section>

      </div>
    </section>
  `,

  styles: [`
    .description {
      display: -webkit-box;
      max-width: 52ch;
      overflow: hidden;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
    }
  `],
})
export class ResourceList {

  private readonly resourceService = inject(ResourceService);
  private readonly auth = inject(AuthService);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);
  private readonly formBuilder = inject(NonNullableFormBuilder);
  private readonly destroyRef = inject(DestroyRef);

  readonly canManage = computed(() => this.auth.can('resources.manage'));
  readonly canRequest = computed(() => this.auth.can('access.request'));

  readonly resources = signal<Resource[]>([]);
  readonly isLoading = signal(false);
  readonly loadError = signal<string | null>(null);
  readonly includeInactive = signal(false);

  readonly showCreate = signal(false);
  readonly isCreating = signal(false);
  readonly createError = signal<string | null>(null);
  readonly serverFieldErrors = signal<Record<string, string>>({});
  readonly submitted = signal(false);

  readonly form = this.formBuilder.group({
    name: this.formBuilder.control('', [
      Validators.required,
      Validators.maxLength(150),
    ]),
    code: this.formBuilder.control('', [
      Validators.required,
      Validators.maxLength(50),
      Validators.pattern(CODE_PATTERN),
    ]),
    description: this.formBuilder.control('', [
      Validators.maxLength(2000),
    ]),
  });

  constructor() {
    this.load();
  }

  load(): void {
    this.isLoading.set(true);
    this.loadError.set(null);

    this.resourceService.list(this.canManage() && this.includeInactive())
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (resources) => {
          this.resources.set(resources);
          this.isLoading.set(false);
        },
        error: (error: unknown) => {
          this.loadError.set(
            describeApiError(error, { action: 'consultar recursos' }).message
          );
          this.isLoading.set(false);
        },
      });
  }

  toggleInactive(): void {
    this.includeInactive.update((value) => !value);
    this.load();
  }

  upperCaseCode(): void {
    const control = this.form.controls.code;
    const upper = control.value.toUpperCase();

    if (upper !== control.value) {
      control.setValue(upper);
    }
  }

  fieldError(field: 'name' | 'code'): string | null {
    const server = this.serverFieldErrors()[field];

    if (server) {
      return server;
    }

    if (!this.submitted()) {
      return null;
    }

    const control = this.form.controls[field];
    const value = control.value.trim();

    if (field === 'name') {
      return value ? null : 'Escribe el nombre del recurso.';
    }

    if (!value) {
      return 'Escribe un código.';
    }

    return CODE_PATTERN.test(value)
      ? null
      : 'Usa solo mayúsculas, números, guion o guion bajo.';
  }

  create(): void {
    if (this.isCreating()) {
      return;
    }

    this.submitted.set(true);
    this.serverFieldErrors.set({});
    this.createError.set(null);

    if (this.fieldError('name') || this.fieldError('code')) {
      return;
    }

    const value = this.form.getRawValue();
    const description = value.description.trim();

    this.isCreating.set(true);

    this.resourceService.create({
      name: value.name.trim(),
      code: value.code.trim().toUpperCase(),
      description: description === '' ? null : description,
    })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (response) => {
          this.isCreating.set(false);
          this.toast.success(`Recurso ${response.data.name} creado.`);
          this.router.navigate(['/resources', response.data.id]);
        },
        error: (error: unknown) => {
          this.isCreating.set(false);

          const apiError = describeApiError(error, {
            action: 'crear recursos',
          });

          if (apiError.status === 422) {
            const fields: Record<string, string> = {};

            if (apiError.fieldErrors['name']) {
              fields['name'] = 'El nombre no es válido.';
            }

            if (apiError.fieldErrors['code']) {
              fields['code'] = 'El código no es válido o ya existe.';
            }

            this.serverFieldErrors.set(fields);
          }

          this.createError.set(apiError.message);
        },
      });
  }
}
