import {
  Injectable,
  computed,
  inject,
  signal,
} from '@angular/core';

import {
  HttpClient,
  HttpErrorResponse,
  HttpHeaders,
} from '@angular/common/http';

import {
  Observable,
  catchError,
  map,
  of,
  switchMap,
  tap,
  throwError,
} from 'rxjs';

/*
|--------------------------------------------------------------------------
| YESIAM - MODELOS DE AUTENTICACIÓN
|--------------------------------------------------------------------------
|
| Representan los datos que actualmente
| intercambiamos con Laravel.
|
| No incluyen contraseñas ni tokens.
|
*/

export interface AuthenticatedUser {
  id: number;
  name: string;
  email: string;
  status?: string;
  department_id?: number | null;

  /*
   * FASE 21: GET /api/user incluye el
   * departamento, los roles y los permisos
   * efectivos. Son opcionales para seguir
   * aceptando respuestas antiguas.
   */
  department?: {
    id: number;
    name: string;
    code: string;
    is_active: boolean;
  } | null;
  roles?: {
    code: string;
    name: string;
  }[];
  permissions?: string[];
}

/*
 * Permisos definidos en RbacSeeder.
 */
export type Permission =
  | 'profile.view'
  | 'access.request'
  | 'access.approve'
  | 'access.grant'
  | 'access.revoke'
  | 'access.view_all'
  | 'departments.view'
  | 'departments.create'
  | 'departments.update'
  | 'departments.deactivate'
  | 'users.view'
  | 'users.manage'
  | 'audit.view'
  | 'resources.view'
  | 'resources.manage';

export interface LoginCredentials {
  email: string;
  password: string;
}

export interface LoginResponse {
  message: string;
  user: AuthenticatedUser;
}

export interface LogoutResponse {
  message: string;
}

/*
|--------------------------------------------------------------------------
| YESIAM - AUTH SERVICE
|--------------------------------------------------------------------------
|
| FASE 19.3.7
|
| Responsabilidades:
|
| 1. Obtener la cookie CSRF de Sanctum.
| 2. Iniciar sesión mediante Laravel.
| 3. Consultar al usuario autenticado.
| 4. Mantener el estado de usuario en memoria.
| 5. Cerrar la sesión mediante Laravel.
|
| SEGURIDAD:
|
| - No almacenamos contraseñas.
| - No almacenamos tokens en localStorage.
| - No generamos tokens personales de Sanctum.
| - Laravel sigue validando las sesiones.
| - Laravel sigue aplicando RBAC y Policies.
|
*/

@Injectable({
  providedIn: 'root',
})
export class AuthService {

  /*
   * Cliente HTTP configurado en app.config.ts.
   */
  private readonly http = inject(HttpClient);

  /*
   * Todos los endpoints utilizan URLs relativas.
   *
   * Esto permite aprovechar el proxy de Angular
   * durante desarrollo.
   */
  private readonly endpoints = {
    csrf: '/sanctum/csrf-cookie',
    login: '/login',
    logout: '/logout',
    user: '/api/user',
  } as const;

  /*
   * Indicamos a Laravel que esperamos JSON.
   *
   * El token XSRF lo administra HttpClient
   * mediante la configuración existente.
   */
  private readonly requestOptions = {
    headers: new HttpHeaders({
      Accept: 'application/json',
    }),
    withCredentials: true,
  };

  /*
  |--------------------------------------------------------------------------
  | ESTADO DE AUTENTICACIÓN
  |--------------------------------------------------------------------------
  |
  | El usuario se conserva únicamente
  | en memoria mientras Angular está abierto.
  |
  | Después de recargar la página,
  | necesitaremos consultar /api/user
  | para recuperar la sesión real.
  |
  */

  private readonly currentUserState =
    signal<AuthenticatedUser | null>(null);

  /*
   * Señal de solo lectura para componentes.
   */
  readonly currentUser =
    this.currentUserState.asReadonly();

  /*
   * Indica si el usuario está identificado
   * en el estado actual de Angular.
   *
   * No reemplaza la validación del backend.
   */
  readonly isAuthenticated = computed(
    () => this.currentUser() !== null
  );

  /*
  |--------------------------------------------------------------------------
  | FASE 21 - PERMISOS PARA LA INTERFAZ
  |--------------------------------------------------------------------------
  |
  | Solo deciden qué se MUESTRA.
  |
  | Ocultar un botón no protege nada: cada
  | operación vuelve a autorizarse en Laravel.
  |
  */

  readonly permissions = computed(
    () => new Set(this.currentUser()?.permissions ?? [])
  );

  can(permission: Permission): boolean {
    return this.permissions().has(permission);
  }

  canAny(permissions: readonly Permission[]): boolean {
    return permissions.some((permission) =>
      this.permissions().has(permission)
    );
  }

  /*
  |--------------------------------------------------------------------------
  | INICIAR SESIÓN
  |--------------------------------------------------------------------------
  |
  | Flujo:
  |
  | 1. GET /sanctum/csrf-cookie
  | 2. POST /login
  | 3. GET /api/user
  | 4. Actualizar estado local
  |
  | Laravel confirma la autenticación.
  |
  */

  login(
    credentials: LoginCredentials
  ): Observable<AuthenticatedUser> {

    return this.http.get<void>(
      this.endpoints.csrf,
      this.requestOptions
    ).pipe(

      /*
       * Una vez preparada la cookie CSRF,
       * enviamos las credenciales.
       */
      switchMap(() =>
        this.http.post<LoginResponse>(
          this.endpoints.login,
          credentials,
          this.requestOptions
        )
      ),

      /*
       * Después del login no confiamos
       * solamente en la respuesta inicial.
       *
       * Consultamos el endpoint protegido
       * para comprobar que la sesión funciona
       * también con Sanctum.
       */
      switchMap(() =>
        this.http.get<AuthenticatedUser>(
          this.endpoints.user,
          this.requestOptions
        )
      ),

      /*
       * Guardamos únicamente los datos
       * no sensibles del usuario.
       */
      tap((user) => {
        this.currentUserState.set(user);
      })

    );
  }

  /*
  |--------------------------------------------------------------------------
  | COMPROBAR SESIÓN
  |--------------------------------------------------------------------------
  |
  | Esta operación será útil para:
  |
  | - Recuperar la sesión al recargar Angular.
  | - Implementar guards.
  | - Consultar la identidad actual.
  |
  | Si Laravel devuelve 401 o 403,
  | consideramos que no hay una sesión
  | utilizable para YESIAM.
  |
  | Los errores de servidor y red no se
  | convierten silenciosamente en logout.
  |
  */

  checkSession(): Observable<AuthenticatedUser | null> {

    return this.http.get<AuthenticatedUser>(
      this.endpoints.user,
      this.requestOptions
    ).pipe(

      tap((user) => {
        this.currentUserState.set(user);
      }),

      catchError((error: unknown) => {

        if (
          error instanceof HttpErrorResponse
          && (error.status === 401 || error.status === 403)
        ) {

          this.currentUserState.set(null);

          return of(null);
        }

        return throwError(() => error);

      })

    );
  }

  /*
  |--------------------------------------------------------------------------
  | CERRAR SESIÓN
  |--------------------------------------------------------------------------
  |
  | Laravel:
  |
  | - Cierra la sesión del guard web.
  | - Invalida la sesión persistente.
  | - Regenera el token CSRF.
  |
  | Angular elimina su estado local
  | solamente después de una respuesta
  | exitosa del backend.
  |
  */

  logout(): Observable<void> {

    return this.http.post<LogoutResponse>(
      this.endpoints.logout,
      {},
      this.requestOptions
    ).pipe(

      tap(() => {
        this.currentUserState.set(null);
      }),

      map(() => undefined)

    );
  }

}
