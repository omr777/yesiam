import { inject, Injectable } from '@angular/core';

import {
  HttpClient,
  HttpHeaders,
  HttpParams,
} from '@angular/common/http';

import { Observable } from 'rxjs';

/*
|--------------------------------------------------------------------------
| YESIAM - MODELOS DE IDENTIDADES
|--------------------------------------------------------------------------
|
| FASE 19.4.6
|
| Estos modelos representan exclusivamente
| los campos expuestos por GET /api/users.
|
| No incluimos contraseñas, tokens ni
| información sensible de autenticación.
|
*/

export type IdentityStatus =
  | 'active'
  | 'suspended'
  | 'disabled';

/*
 * Identidad devuelta por Laravel.
 */
export interface Identity {
  id: number;
  name: string;
  email: string;
  status: IdentityStatus;
  department_id: number | null;
}

/*
 * Parámetros opcionales del listado.
 *
 * Los nombres de los parámetros HTTP
 * coinciden con UserController::index().
 */
export interface IdentityFilters {
  search?: string;
  status?: IdentityStatus | '';
  page?: number;
  perPage?: number;
}

/*
 * Respuesta paginada de Laravel.
 *
 * Conservamos los campos que necesita
 * nuestro frontend para representar
 * el listado y la navegación.
 */
export interface PaginatedIdentitiesResponse {
  data: Identity[];

  current_page: number;
  last_page: number;
  per_page: number;
  total: number;

  from: number | null;
  to: number | null;

  next_page_url: string | null;
  prev_page_url: string | null;
}

/*
|--------------------------------------------------------------------------
| YESIAM - IDENTITY SERVICE
|--------------------------------------------------------------------------
|
| Responsabilidades:
|
| 1. Consultar identidades registradas.
| 2. Enviar filtros de búsqueda y estado.
| 3. Solicitar páginas de resultados.
| 4. Mantener tipadas las respuestas HTTP.
|
| SEGURIDAD:
|
| - Usamos la sesión existente de Sanctum.
| - No almacenamos tokens en localStorage.
| - No enviamos contraseñas.
| - No sustituimos la autorización de Laravel.
| - Los errores HTTP se propagan al componente.
|
*/

@Injectable({
  providedIn: 'root',
})
export class IdentityService {

  private readonly http = inject(HttpClient);

  /*
   * URL relativa para aprovechar
   * el proxy configurado en Angular.
   */
  private readonly endpoint = '/api/users';

  /*
   * Configuración común de solicitudes.
   *
   * withCredentials mantiene compatibilidad
   * con la autenticación mediante cookies.
   */
  private readonly requestOptions = {
    headers: new HttpHeaders({
      Accept: 'application/json',
    }),
    withCredentials: true,
  };

  /*
  |--------------------------------------------------------------------------
  | LISTAR IDENTIDADES
  |--------------------------------------------------------------------------
  |
  | Endpoint:
  |
  | GET /api/users
  |
  | Parámetros opcionales:
  |
  | search
  | status
  | page
  | per_page
  |
  | Laravel realiza:
  |
  | - Autenticación.
  | - Verificación de cuenta activa.
  | - Autorización users.manage.
  | - Validación de filtros.
  | - Consulta paginada.
  |
  */

  list(
    filters: IdentityFilters = {}
  ): Observable<PaginatedIdentitiesResponse> {

    /*
     * Valores predeterminados:
     *
     * Primera página.
     * 15 identidades por página.
     */
    let params = new HttpParams()
      .set('page', String(filters.page ?? 1))
      .set('per_page', String(filters.perPage ?? 15));

    /*
     * Búsqueda opcional.
     *
     * Eliminamos espacios al principio
     * y final del texto.
     *
     * HttpParams codifica el valor
     * como parámetro de URL.
     */
    const search = filters.search?.trim();

    if (search) {
      params = params.set('search', search);
    }

    /*
     * Filtro opcional por estado.
     *
     * Si está vacío, consultamos
     * identidades de todos los estados.
     */
    if (filters.status) {
      params = params.set(
        'status',
        filters.status
      );
    }

    /*
     * La autorización y las validaciones
     * definitivas corresponden a Laravel.
     *
     * No convertimos respuestas 401/403
     * en listas vacías: el componente
     * debe poder mostrar el error real.
     */
    return this.http.get<PaginatedIdentitiesResponse>(
      this.endpoint,
      {
        ...this.requestOptions,
        params,
      }
    );
  }
}
