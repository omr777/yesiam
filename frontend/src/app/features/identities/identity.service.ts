import { inject, Injectable } from '@angular/core';

import {
  HttpClient,
  HttpHeaders,
  HttpParams,
} from '@angular/common/http';

import { Observable, map } from 'rxjs';

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

  /*
   * FASE 21: filtrar por permiso efectivo,
   * por ejemplo access.approve.
   */
  permission?: string;
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
| FASE 21 - DETALLE, ACTIVACIÓN, ROLES, MOVER Y LEAVER
|--------------------------------------------------------------------------
*/

export interface RoleSummary {
  code: string;
  name: string;
}

export interface RoleDefinition extends RoleSummary {
  permissions: string[];
}

export interface IdentityDetail extends Identity {
  department: {
    id: number;
    name: string;
    code: string;
    is_active: boolean;
  } | null;
  roles: RoleSummary[];
  managed_resources: {
    id: number;
    name: string;
    code: string;
    is_active: boolean;
  }[];
  active_access_grants_count: number;
  created_at: string | null;
}

export interface ActivationResponse {
  message: string;
  data: {
    id: number;
    status: IdentityStatus;
    temporary_password: string;
  };
}

export interface RolesUpdateResponse {
  message: string;
  data: {
    id: number;
    roles: string[];
    changed: boolean;
    removed_approver_resource_ids: number[];
  };
}

export interface DepartmentChangeResponse {
  message: string;
  data: {
    id: number;
    name: string;
    previous_department_id: number | null;
    department_id: number;
    revoked_access_grants_count: number;
  };
}

export interface DeactivationResponse {
  message: string;
  data: {
    id: number;
    status: 'disabled';
    revoked_access_grants_count: number;
  };
}

export type GrantStatus = 'active' | 'revoked';

export interface AccessGrantItem {
  id: number;
  status: GrantStatus;
  resource: {
    id: number;
    name: string;
    code: string;
    is_active: boolean;
  } | null;
  access_request: {
    id: number;
    status: string;
    decided_at: string | null;
  } | null;
  granted_at: string | null;
  granted_by: { id: number; name: string } | null;
  revoked_at: string | null;
  revoked_by: { id: number; name: string } | null;
}

export interface AccessGrantInventory {
  message: string;
  user: { id: number; name: string };
  data: AccessGrantItem[];
  pagination: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
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

    if (filters.permission) {
      params = params.set(
        'permission',
        filters.permission
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
  /*
  |--------------------------------------------------------------------------
  | FASE 21 - DETALLE
  |--------------------------------------------------------------------------
  */

  get(id: number): Observable<IdentityDetail> {
    return this.http.get<{ data: IdentityDetail }>(
      `${this.endpoint}/${id}`,
      this.requestOptions
    ).pipe(
      map((response) => response.data)
    );
  }

  /*
  |--------------------------------------------------------------------------
  | FASE 21 - ACTIVACIÓN
  |--------------------------------------------------------------------------
  |
  | Laravel devuelve una contraseña temporal
  | una sola vez. Angular la muestra y no la
  | guarda en ningún almacenamiento.
  |
  */

  activate(id: number): Observable<ActivationResponse> {
    return this.http.patch<ActivationResponse>(
      `${this.endpoint}/${id}/activate`,
      {},
      this.requestOptions
    );
  }

  /*
  |--------------------------------------------------------------------------
  | FASE 21 - MOVER
  |--------------------------------------------------------------------------
  */

  changeDepartment(
    id: number,
    departmentId: number
  ): Observable<DepartmentChangeResponse> {
    return this.http.patch<DepartmentChangeResponse>(
      `${this.endpoint}/${id}/department`,
      { department_id: departmentId },
      this.requestOptions
    );
  }

  /*
  |--------------------------------------------------------------------------
  | FASE 21 - LEAVER
  |--------------------------------------------------------------------------
  */

  deactivate(id: number): Observable<DeactivationResponse> {
    return this.http.patch<DeactivationResponse>(
      `${this.endpoint}/${id}/deactivate`,
      {},
      this.requestOptions
    );
  }

  /*
  |--------------------------------------------------------------------------
  | FASE 21 - ROLES
  |--------------------------------------------------------------------------
  */

  listRoles(): Observable<RoleDefinition[]> {
    return this.http.get<{ data: RoleDefinition[] }>(
      '/api/roles',
      this.requestOptions
    ).pipe(
      map((response) => response.data)
    );
  }

  updateRoles(
    id: number,
    roles: string[]
  ): Observable<RolesUpdateResponse> {
    return this.http.put<RolesUpdateResponse>(
      `${this.endpoint}/${id}/roles`,
      { roles },
      this.requestOptions
    );
  }

  /*
  |--------------------------------------------------------------------------
  | FASE 21 - INVENTARIO DE ACCESOS
  |--------------------------------------------------------------------------
  |
  | Sirve tanto para el inventario propio
  | (Mis accesos) como para el de otra
  | identidad (requiere access.view_all).
  |
  */

  accessGrants(
    userId: number,
    filters: {
      status?: GrantStatus | '';
      page?: number;
      perPage?: number;
    } = {}
  ): Observable<AccessGrantInventory> {
    let params = new HttpParams()
      .set('page', String(filters.page ?? 1))
      .set('per_page', String(filters.perPage ?? 20));

    if (filters.status) {
      params = params.set('status', filters.status);
    }

    return this.http.get<AccessGrantInventory>(
      `${this.endpoint}/${userId}/access-grants`,
      {
        ...this.requestOptions,
        params,
      }
    );
  }
}
