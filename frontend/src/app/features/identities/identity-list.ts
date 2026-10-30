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

/*
|--------------------------------------------------------------------------
| YESIAM - IDENTITY MANAGEMENT
|--------------------------------------------------------------------------
|
| FASE 19.4.8
|
| Componente de consulta de identidades.
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
  ],

  changeDetection: ChangeDetectionStrategy.OnPush,

  template: `
    <section
      class="identities"
      aria-labelledby="identities-title"
    >

      <header class="heading">
        <div>
          <h1 id="identities-title">
            Identity Management
          </h1>

          <p>
            Consulta las identidades registradas
            en YESIAM.
          </p>
        </div>
      </header>

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

        <div class="field">
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
              Activos
            </option>

            <option value="suspended">
              Suspendidos
            </option>

            <option value="disabled">
              Desactivados
            </option>
          </select>
        </div>

        <div class="actions">
          <button
            type="submit"
            class="primary-button"
            [disabled]="isLoading()"
          >
            Buscar
          </button>

          <button
            type="button"
            class="secondary-button"
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

          <div class="notice" role="status">
            Cargando identidades...
          </div>

        } @else if (errorMessage()) {

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
                class="secondary-button"
                (click)="load()"
              >
                Reintentar
              </button>

            }

          </div>

        } @else if (identities().length === 0) {

          <div class="notice">
            No se encontraron identidades
            con los filtros seleccionados.
          </div>

        } @else {

          <div class="table-wrapper">

            <table>

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
                      <div class="identity-name">
                        {{ identity.name }}
                      </div>

                      <div class="identity-email">
                        {{ identity.email }}
                      </div>
                    </td>

                    <td>
                      @switch (identity.status) {

                        @case ('active') {
                          <span class="status active">
                            Activo
                          </span>
                        }

                        @case ('suspended') {
                          <span class="status suspended">
                            Suspendido
                          </span>
                        }

                        @case ('disabled') {
                          <span class="status disabled">
                            Desactivado
                          </span>
                        }

                        @default {
                          <span class="status">
                            Desconocido
                          </span>
                        }

                      }
                    </td>

                    <td>
                      @if (identity.department_id !== null) {
                        Departamento #{{ identity.department_id }}
                      } @else {
                        Sin departamento
                      }
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

        <footer class="pagination">

          <p>
            Mostrando
            {{ from() ?? 0 }}
            a
            {{ to() ?? 0 }}
            de
            {{ total() }}
            identidades.
          </p>

          <div class="page-actions">

            <button
              type="button"
              class="secondary-button"
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
              class="secondary-button"
              [disabled]="currentPage() >= lastPage()"
              (click)="goToPage(currentPage() + 1)"
            >
              Siguiente
            </button>

          </div>

        </footer>

      }

    </section>
  `,

  styles: [`
    :host {
      display: block;
    }

    .identities {
      max-width: 1200px;
      margin: 0 auto;
      padding: 24px;
      color: inherit;
    }

    .heading {
      margin-bottom: 24px;
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

    .filters {
      display: flex;
      flex-wrap: wrap;
      align-items: end;
      gap: 16px;
      padding: 20px;
      margin-bottom: 24px;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
    }

    .field {
      display: flex;
      flex-direction: column;
      gap: 8px;
      min-width: 180px;
    }

    .search-field {
      flex: 1;
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

    select option {
      color: #0f172a;
      background: white;
    }

    .actions,
    .page-actions {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    button {
      min-height: 40px;
      padding: 8px 14px;
      border-radius: 8px;
      font: inherit;
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
      border: 1px solid #e2e8f0;
      border-radius: 12px;
    }

    .notice.error {
      border-color: #fca5a5;
    }

    .notice p {
      margin: 12px 0;
    }

    .table-wrapper {
      overflow-x: auto;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
    }

    th,
    td {
      padding: 16px;
      border-bottom: 1px solid #e2e8f0;
    }

    th {
      font-size: 13px;
      color: #64748b;
    }

    tbody tr:last-child td {
      border-bottom: 0;
    }

    .identity-name {
      font-weight: 600;
    }

    .identity-email {
      margin-top: 4px;
      font-size: 13px;
      color: #64748b;
      overflow-wrap: anywhere;
    }

    .status {
      display: inline-block;
      padding: 5px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 600;
    }

    .status.active {
      background: #dcfce7;
      color: #166534;
    }

    .status.suspended {
      background: #fef3c7;
      color: #92400e;
    }

    .status.disabled {
      background: #fee2e2;
      color: #991b1b;
    }

    .pagination {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 16px;
      margin-top: 20px;
      font-size: 14px;
    }

    .pagination p {
      margin: 0;
      color: #64748b;
    }

    @media (max-width: 640px) {
      .identities {
        padding: 16px;
      }

      .field,
      .actions {
        width: 100%;
      }

      .pagination {
        align-items: flex-start;
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
