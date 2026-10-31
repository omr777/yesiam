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
| FASE 19.5.1
|
| Modelos para:
|
| - Consultar el directorio de identidades.
| - Crear identidades mediante el proceso Joiner.
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
 * Identidad devuelta por el listado de Laravel.
 */
export interface Identity {
  id: number;
  name: string;
  email: string;
  status: IdentityStatus;
  department_id: number | null;
}

/*
|--------------------------------------------------------------------------
| CONSULTA DE IDENTIDADES
|--------------------------------------------------------------------------
*/

/*
 * Parámetros opcionales del listado.
 *
 * Coinciden con UserController::index().
 */
export interface IdentityFilters {
  search?: string;
  status?: IdentityStatus | '';
  page?: number;
  perPage?: number;
}

/*
 * Respuesta paginada de Laravel.
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
| JOINER - CREACIÓN DE IDENTIDADES
|--------------------------------------------------------------------------
|
| Los campos enviados coinciden exactamente
| con los que valida UserController::store().
|
| No enviamos:
|
| - ID del usuario.
| - Contraseña.
| - Rol.
| - Estado de la cuenta.
|
| Es Laravel quien determina esos valores.
|
*/

/*
 * Solicitud POST /api/users.
 */
export interface CreateIdentityRequest {
  name: string;
  email: string;
  department_id: number;
}

/*
 * Identidad devuelta después de crearla.
 *
 * Laravel incorpora el rol inicial
 * asignado durante el proceso Joiner.
 */
export interface CreatedIdentity extends Identity {
  roles: string[];
}

/*
 * Respuesta HTTP 201 del backend.
 */
export interface CreateIdentityResponse {
  message: string;
  data: CreatedIdentity;
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
| 4. Crear nuevas identidades.
| 5. Mantener tipadas las respuestas HTTP.
|
| SEGURIDAD:
|
| - Reutilizamos la sesión de Laravel Sanctum.
| - No almacenamos tokens en localStorage.
| - No generamos contraseñas desde Angular.
| - No asignamos roles desde Angular.
| - No activamos automáticamente identidades.
| - Laravel realiza la autorización RBAC.
| - Los errores HTTP se propagan al componente.
|
*/

@Injectable({
  providedIn: 'root',
})
export class IdentityService {

  private readonly http = inject(HttpClient);

  /*
   * URL relativa para utilizar el proxy
   * y las cookies de sesión existentes.
   */
  private readonly endpoint = '/api/users';

  /*
   * Configuración HTTP compartida.
   *
   * Las solicitudes usan JSON y
   * credenciales de sesión.
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
  | Parámetros:
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
     * Primera página y 15 registros
     * como valores predeterminados.
     */
    let params = new HttpParams()
      .set('page', String(filters.page ?? 1))
      .set('per_page', String(filters.perPage ?? 15));

    /*
     * Búsqueda opcional por nombre o correo.
     */
    const search = filters.search?.trim();

    if (search) {
      params = params.set('search', search);
    }

    /*
     * Filtro opcional por estado.
     */
    if (filters.status) {
      params = params.set(
        'status',
        filters.status
      );
    }

    /*
     * No transformamos los errores 401/403
     * en listas vacías.
     *
     * El componente debe distinguir
     * entre ausencia de datos y
     * falta de autorización.
     */
    return this.http.get<PaginatedIdentitiesResponse>(
      this.endpoint,
      {
        ...this.requestOptions,
        params,
      }
    );
  }

  /*
  |--------------------------------------------------------------------------
  | JOINER - CREAR IDENTIDAD
  |--------------------------------------------------------------------------
  |
  | Endpoint:
  |
  | POST /api/users
  |
  | Datos enviados:
  |
  | - name
  | - email
  | - department_id
  |
  | SEGURIDAD EN LARAVEL:
  |
  | 1. Sanctum autentica al solicitante.
  | 2. account.active verifica su estado.
  | 3. UserPolicy::create comprueba users.manage.
  | 4. Laravel valida los campos.
  | 5. Se verifica que el departamento esté activo.
  | 6. Se crea la identidad suspendida.
  | 7. Se asigna el rol employee.
  | 8. Se registra el evento de auditoría.
  |
  | El backend devuelve HTTP 201
  | cuando la creación es exitosa.
  |
  */

  create(
    payload: CreateIdentityRequest
  ): Observable<CreateIdentityResponse> {

    /*
     * Enviamos únicamente los campos
     * aceptados por el controlador.
     *
     * No enviamos status, password ni roles.
     */
    const body: CreateIdentityRequest = {
      name: payload.name,
      email: payload.email,
      department_id: payload.department_id,
    };

    /*
     * Angular utiliza la configuración
     * XSRF existente para las solicitudes
     * de escritura del mismo origen.
     *
     * La sesión de Sanctum ya fue
     * establecida durante el login.
     *
     * No agregamos un Bearer Token.
     */
    return this.http.post<CreateIdentityResponse>(
      this.endpoint,
      body,
      this.requestOptions
    );
  }
}
