import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  inject,
  signal,
} from '@angular/core';

import { FormsModule } from '@angular/forms';

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
  Identity,
  IdentityService,
  IdentityStatus,
} from './identity.service';

import {
  Department,
  DepartmentService,
} from '../departments/department.service';

import { StatusBadge } from '../../shared/ui/status-badge';

/*
|--------------------------------------------------------------------------
| YESIAM - IDENTITY MANAGEMENT
|--------------------------------------------------------------------------
|
| FASE 21
|
| Componente de consulta de identidades.
|
| 19.5.7: acceso al alta de identidades
| (Joiner) desde el encabezado.
|
| 21: nombre del departamento en lugar de
| su ID, acceso al detalle de cada identidad
| y estilos del sistema de diseño.
|
| Incluye:
|
| - Datos reales obtenidos de Laravel.
| - Búsqueda por nombre o correo.
| - Filtro por estado.
| - Paginación.
| - Estados de carga.
| - Manejo de errores HTTP.
| - Protección frente a respuestas obsoletas.
| - Acceso al formulario Joiner.
|
| SEGURIDAD:
|
| La autorización pertenece al backend.
| Este componente no concede permisos.
|
*/

@Component({
  selector: 'app-identity-list',
  standalone: true,

  imports: [
    FormsModule,
    RouterLink,
    StatusBadge,
  ],

  changeDetection: ChangeDetectionStrategy.OnPush,

  template: `
    <section
      class="page identities"
      aria-labelledby="identities-title"
    >

      <header class="page-header heading">
        <div>
          <h1 id="identities-title">
            Identidades
          </h1>

          <p>
            Directorio de las personas registradas en YESIAM.
            Abre una identidad para activarla, cambiar sus roles,
            trasladarla o darla de baja.
          </p>
        </div>

        <!--
        Ocultamos el acceso al Joiner cuando
        Laravel ya respondió 403: la consulta
        y el alta requieren users.manage.

        Es solo experiencia de usuario;
        Laravel vuelve a autorizar el alta.
        -->

        @if (errorStatus() !== 403) {

          <a
            class="btn btn-primary new-identity"
            routerLink="/identities/new"
          >
            + Nueva identidad
          </a>

        }
      </header>

      <div class="panel">

        <!--
        ------------------------------------------------------
        FILTROS
        ------------------------------------------------------
        -->

        <form
          class="filters"
          (ngSubmit)="applySearch()"
        >

          <div class="field search-field">
            <label for="identity-search">
              Buscar identidad
            </label>

            <input
              id="identity-search"
              name="identitySearch"
              type="search"
              placeholder="Nombre o correo electrónico"
              maxlength="100"
              autocomplete="off"
              [(ngModel)]="searchText"
            />
          </div>

          <div class="field status-field">
            <label for="identity-status">
              Estado
            </label>

            <select
              id="identity-status"
              name="identityStatus"
              [(ngModel)]="selectedStatus"
              (ngModelChange)="onStatusChange()"
            >
              <option value="">
                Todos
              </option>

              <option value="active">
                Activas
              </option>

              <option value="suspended">
                Suspendidas
              </option>

              <option value="disabled">
                Desactivadas
              </option>
            </select>
          </div>

          <div class="actions">
            <button
              type="submit"
              class="btn btn-primary"
              [disabled]="isLoading()"
            >
              Buscar
            </button>

            <button
              type="button"
              class="btn"
              [disabled]="isLoading()"
              (click)="resetFilters()"
            >
              Limpiar
            </button>
          </div>

        </form>

        <!--
        ------------------------------------------------------
        RESULTADOS
        ------------------------------------------------------
        -->

        <div class="results" aria-live="polite">

          @if (isLoading()) {

            <div class="empty-state" role="status">
              Cargando identidades...
            </div>

          } @else if (errorMessage()) {

            <div class="results-message">
              <div class="notice error" role="alert">

                <strong>
                  No fue posible consultar las identidades.
                </strong>

                <p>
                  {{ errorMessage() }}
                </p>

                @if (errorStatus() === 401) {

                  <a routerLink="/sign-in">
                    Ir al inicio de sesión
                  </a>

                } @else if (errorStatus() !== 403) {

                  <button
                    type="button"
                    class="btn"
                    (click)="load()"
                  >
                    Reintentar
                  </button>

                }

              </div>
            </div>

          } @else if (identities().length === 0) {

            <div class="empty-state">
              No se encontraron identidades
              con los filtros seleccionados.
            </div>

          } @else {

            <div class="table-wrap">

              <table class="data-table">

                <thead>
                  <tr>
                    <th scope="col">
                      Identidad
                    </th>

                    <th scope="col">
                      Estado
                    </th>

                    <th scope="col">
                      Departamento
                    </th>
                  </tr>
                </thead>

                <tbody>

                  @for (
                    identity of identities();
                    track identity.id
                  ) {

                    <tr>

                      <td>
                        <a
                          class="row-link"
                          [routerLink]="['/identities', identity.id]"
                        >
                          <div class="cell-primary identity-name">
                            {{ identity.name }}
                          </div>

                          <div class="cell-secondary identity-email">
                            {{ identity.email }}
                          </div>
                        </a>
                      </td>

                      <td>
                        <app-status-badge
                          kind="identity"
                          [value]="identity.status"
                        />
                      </td>

                      <td>
                        {{ departmentLabel(identity.department_id) }}
                      </td>

                    </tr>

                  }

                </tbody>

              </table>

            </div>

          }

        </div>

        <!--
        ------------------------------------------------------
        PAGINACIÓN
        ------------------------------------------------------
        -->

        @if (
          !isLoading() &&
          !errorMessage() &&
          total() > 0
        ) {

          <footer class="pager pagination">

            <p>
              Mostrando
              {{ from() ?? 0 }}
              a
              {{ to() ?? 0 }}
              de
              {{ total() }}
              identidades.
            </p>

            <div class="actions page-actions">

              <button
                type="button"
                class="btn btn-sm"
                [disabled]="currentPage() <= 1"
                (click)="goToPage(currentPage() - 1)"
              >
                Anterior
              </button>

              <span>
                Página
                {{ currentPage() }}
                de
                {{ lastPage() }}
              </span>

              <button
                type="button"
                class="btn btn-sm"
                [disabled]="currentPage() >= lastPage()"
                (click)="goToPage(currentPage() + 1)"
              >
                Siguiente
              </button>

            </div>

          </footer>

        }

      </div>

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

    .search-field {
      flex: 1 1 280px;
    }

    .status-field {
      flex: 0 1 200px;
    }

    .results-message {
      padding: 20px;
    }

    .pagination p {
      margin: 0;
    }

    @media (max-width: 640px) {
      .status-field,
      .filters .actions,
      .new-identity {
        width: 100%;
        flex-basis: 100%;
      }
    }
  `],
})

export class IdentityList {

  private readonly identityService = inject(
    IdentityService
  );

  private readonly destroyRef = inject(
    DestroyRef
  );

  private readonly departmentService = inject(
    DepartmentService
  );

  /*
   * FASE 21: nombres de departamento.
   *
   * Si el catálogo no está disponible, la
   * tabla muestra el identificador como antes.
   */
  private readonly departmentNames = signal<
    Map<number, string>
  >(new Map());

  /*
  |--------------------------------------------------------------------------
  | ESTADO DEL COMPONENTE
  |--------------------------------------------------------------------------
  */

  readonly identities = signal<Identity[]>([]);

  readonly isLoading = signal(false);

  readonly errorMessage = signal<string | null>(
    null
  );

  readonly errorStatus = signal<number | null>(
    null
  );

  readonly currentPage = signal(1);

  readonly lastPage = signal(1);

  readonly total = signal(0);

  readonly from = signal<number | null>(null);

  readonly to = signal<number | null>(null);

  /*
   * Los valores escritos en el formulario
   * se mantienen separados de los filtros
   * aplicados a la consulta.
   */
  searchText = '';

  selectedStatus: IdentityStatus | '' = '';

  private readonly appliedSearch = signal('');

  /*
   * Permite cancelar una consulta anterior
   * cuando se inicia una nueva.
   */
  private currentRequest?: Subscription;

  /*
  |--------------------------------------------------------------------------
  | CARGA INICIAL
  |--------------------------------------------------------------------------
  */

  constructor() {
    this.load();
    this.loadDepartments();
  }

  departmentLabel(departmentId: number | null): string {
    if (departmentId === null) {
      return 'Sin departamento';
    }

    return this.departmentNames().get(departmentId)
      ?? `Departamento #${departmentId}`;
  }

  private loadDepartments(): void {
    this.departmentService
      .list()
      .pipe(
        takeUntilDestroyed(this.destroyRef)
      )
      .subscribe({
        next: (response) => {
          this.departmentNames.set(
            new Map(
              response.data.map(
                (department: Department) =>
                  [department.id, department.name]
              )
            )
          );
        },
        error: () => {
          this.departmentNames.set(new Map());
        },
      });
  }

  /*
  |--------------------------------------------------------------------------
  | CONSULTAR IDENTIDADES
  |--------------------------------------------------------------------------
  */

  load(): void {

    /*
     * Evitar que una respuesta anterior
     * sobrescriba resultados más recientes.
     */
    this.currentRequest?.unsubscribe();

    this.isLoading.set(true);

    this.errorMessage.set(null);
    this.errorStatus.set(null);

    this.currentRequest = this.identityService
      .list({
        search: this.appliedSearch(),
        status: this.selectedStatus,
        page: this.currentPage(),
        perPage: 15,
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef)
      )
      .subscribe({

        next: (response) => {
          this.identities.set(response.data);

          this.currentPage.set(
            response.current_page
          );

          this.lastPage.set(
            response.last_page
          );

          this.total.set(response.total);

          this.from.set(response.from);
          this.to.set(response.to);

          this.isLoading.set(false);
        },

        error: (error: unknown) => {
          this.identities.set([]);

          this.total.set(0);
          this.from.set(null);
          this.to.set(null);

          this.handleError(error);

          this.isLoading.set(false);
        },

      });
  }

  /*
  |--------------------------------------------------------------------------
  | BÚSQUEDA
  |--------------------------------------------------------------------------
  */

  applySearch(): void {
    this.appliedSearch.set(
      this.searchText.trim()
    );

    this.currentPage.set(1);

    this.load();
  }

  /*
  |--------------------------------------------------------------------------
  | FILTRO POR ESTADO
  |--------------------------------------------------------------------------
  */

  onStatusChange(): void {
    this.currentPage.set(1);

    this.load();
  }

  /*
  |--------------------------------------------------------------------------
  | LIMPIAR FILTROS
  |--------------------------------------------------------------------------
  */

  resetFilters(): void {
    this.searchText = '';
    this.selectedStatus = '';

    this.appliedSearch.set('');

    this.currentPage.set(1);

    this.load();
  }

  /*
  |--------------------------------------------------------------------------
  | CAMBIAR PÁGINA
  |--------------------------------------------------------------------------
  */

  goToPage(page: number): void {
    if (
      this.isLoading() ||
      page < 1 ||
      page > this.lastPage()
    ) {
      return;
    }

    this.currentPage.set(page);

    this.load();
  }

  /*
  |--------------------------------------------------------------------------
  | MANEJO DE ERRORES
  |--------------------------------------------------------------------------
  |
  | No mostramos mensajes internos del backend.
  |
  | 401: sesión no válida.
  | 403: autorización insuficiente.
  | 422: parámetros inválidos.
  |   0: error de conexión.
  |
  */

  private handleError(error: unknown): void {

    if (!(error instanceof HttpErrorResponse)) {
      this.errorStatus.set(null);

      this.errorMessage.set(
        'Ocurrió un error inesperado.'
      );

      return;
    }

    this.errorStatus.set(error.status);

    switch (error.status) {

      case 401:
        this.errorMessage.set(
          'Tu sesión no está disponible. Inicia sesión nuevamente.'
        );
        break;

      case 403:
        this.errorMessage.set(
          'No tienes permisos para consultar el directorio de identidades.'
        );
        break;

      case 422:
        this.errorMessage.set(
          'Los filtros enviados no son válidos.'
        );
        break;

      case 0:
        this.errorMessage.set(
          'No fue posible conectar con YESIAM. Verifica que Laravel esté disponible.'
        );
        break;

      default:
        this.errorMessage.set(
          'El servidor no pudo completar la consulta.'
        );
        break;
    }
  }
}