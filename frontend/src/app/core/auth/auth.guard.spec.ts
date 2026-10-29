
import { provideHttpClient } from '@angular/common/http';

import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';

import { TestBed } from '@angular/core/testing';

import {
  ActivatedRouteSnapshot,
  Router,
  RouterStateSnapshot,
  UrlTree,
  provideRouter,
} from '@angular/router';

import {
  Observable,
  firstValueFrom,
} from 'rxjs';

import {
  beforeEach,
  afterEach,
  describe,
  expect,
  it,
} from 'vitest';

import {
  AuthService,
  AuthenticatedUser,
} from './auth.service';

import { authGuard } from './auth.guard';

/*
|--------------------------------------------------------------------------
| YESIAM - AUTH GUARD TESTS
|--------------------------------------------------------------------------
|
| Fase 19.3.17
|
| Objetivo:
|
| Comprobar que Angular no permita navegar
| a rutas protegidas sin una sesión válida
| confirmada por Laravel.
|
| SEGURIDAD:
|
| - No usamos credenciales reales.
| - No realizamos peticiones HTTP reales.
| - No modificamos la base de datos.
| - No dependemos de localStorage.
|
*/

describe('YESIAM - AuthGuard', () => {
  let httpMock: HttpTestingController;
  let router: Router;
  let auth: AuthService;

  const testUser: AuthenticatedUser = {
    id: 1,
    name: 'Administrador de prueba',
    email: 'admin@example.test',
    status: 'active',
  };

  /*
  |--------------------------------------------------------------------------
  | CONFIGURACIÓN DEL ENTORNO DE PRUEBAS
  |--------------------------------------------------------------------------
  */

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),

        provideHttpClient(),

        provideHttpClientTesting(),
      ],
    });

    httpMock = TestBed.inject(
      HttpTestingController
    );

    router = TestBed.inject(Router);

    auth = TestBed.inject(AuthService);
  });

  afterEach(() => {
    /*
     * No deben quedar solicitudes HTTP
     * pendientes al finalizar cada prueba.
     */
    httpMock.verify();
  });

  /*
  |--------------------------------------------------------------------------
  | EJECUTAR EL GUARD
  |--------------------------------------------------------------------------
  |
  | Los guards funcionales utilizan inject().
  |
  | Por eso ejecutamos authGuard dentro del
  | contexto de inyección de Angular.
  |
  | El resultado es una decisión:
  |
  | true:
  |   Permitir navegación.
  |
  | UrlTree:
  |   Redirigir a otra ruta.
  |
  */

  function executeGuard(): Promise<boolean | UrlTree> {
    const result = TestBed.runInInjectionContext(
      () =>
        authGuard(
          {} as ActivatedRouteSnapshot,
          {} as RouterStateSnapshot
        )
    );

    return firstValueFrom(
      result as Observable<boolean | UrlTree>
    );
  }

  /*
  |--------------------------------------------------------------------------
  | PREPARAR UNA IDENTIDAD EN MEMORIA
  |--------------------------------------------------------------------------
  |
  | Simula que Angular ya había recuperado
  | una sesión en una operación anterior.
  |
  */

  function establishSession(): void {
    auth.checkSession().subscribe();

    const request = httpMock.expectOne(
      '/api/user'
    );

    expect(request.request.method).toBe(
      'GET'
    );

    request.flush(testUser);

    expect(auth.isAuthenticated()).toBe(
      true
    );
  }

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 1 - SESIÓN VÁLIDA
  |--------------------------------------------------------------------------
  */

  it('should allow access when Laravel confirms the session', async () => {
    const decision = executeGuard();

    const request = httpMock.expectOne(
      '/api/user'
    );

    expect(request.request.method).toBe(
      'GET'
    );

    expect(
      request.request.withCredentials
    ).toBe(true);

    request.flush(testUser);

    expect(await decision).toBe(true);

    expect(auth.currentUser()).toEqual(
      testUser
    );

    expect(auth.isAuthenticated()).toBe(
      true
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 2 - USUARIO NO AUTENTICADO
  |--------------------------------------------------------------------------
  |
  | HTTP 401:
  |
  | El usuario debe regresar al login.
  |
  */

  it('should redirect to sign-in on HTTP 401', async () => {
    const decision = executeGuard();

    const request = httpMock.expectOne(
      '/api/user'
    );

    request.flush(
      {
        message: 'Unauthenticated.',
      },
      {
        status: 401,
        statusText: 'Unauthorized',
      }
    );

    const result = await decision;

    expect(result).toBeInstanceOf(UrlTree);

    expect(
      router.serializeUrl(result as UrlTree)
    ).toBe('/sign-in');

    expect(auth.currentUser()).toBeNull();

    expect(auth.isAuthenticated()).toBe(
      false
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 3 - ACCESO RECHAZADO
  |--------------------------------------------------------------------------
  |
  | HTTP 403:
  |
  | El backend rechazó la solicitud.
  | Puede ser una cuenta desactivada
  | u otra restricción de acceso.
  |
  */

  it('should redirect to sign-in on HTTP 403', async () => {
    const decision = executeGuard();

    const request = httpMock.expectOne(
      '/api/user'
    );

    request.flush(
      {
        message: 'Forbidden.',
      },
      {
        status: 403,
        statusText: 'Forbidden',
      }
    );

    const result = await decision;

    expect(result).toBeInstanceOf(UrlTree);

    expect(
      router.serializeUrl(result as UrlTree)
    ).toBe('/sign-in');

    expect(auth.isAuthenticated()).toBe(
      false
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 4 - ERROR DEL SERVIDOR
  |--------------------------------------------------------------------------
  |
  | HTTP 503:
  |
  | No debemos permitir acceso cuando
  | el backend no está disponible.
  |
  */

  it('should deny access when Laravel is unavailable', async () => {
    const decision = executeGuard();

    const request = httpMock.expectOne(
      '/api/user'
    );

    request.flush(
      {
        message: 'Service Unavailable',
      },
      {
        status: 503,
        statusText: 'Service Unavailable',
      }
    );

    const result = await decision;

    expect(result).toBeInstanceOf(UrlTree);

    expect(
      router.serializeUrl(result as UrlTree)
    ).toBe(
      '/sign-in?reason=service-unavailable'
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 5 - ERROR DE CONEXIÓN
  |--------------------------------------------------------------------------
  |
  | Cuando no podemos conectar al backend,
  | Angular debe bloquear la navegación.
  |
  */

  it('should deny access when the network request fails', async () => {
    const decision = executeGuard();

    const request = httpMock.expectOne(
      '/api/user'
    );

    request.error(
      new ProgressEvent('error')
    );

    const result = await decision;

    expect(result).toBeInstanceOf(UrlTree);

    expect(
      router.serializeUrl(result as UrlTree)
    ).toBe(
      '/sign-in?reason=service-unavailable'
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 6 - ESTADO LOCAL DESACTUALIZADO
  |--------------------------------------------------------------------------
  |
  | ESCENARIO IAM:
  |
  | Angular conserva los datos del usuario.
  |
  | Sin embargo, su sesión fue invalidada
  | por el backend.
  |
  | El guard debe confiar en Laravel,
  | no en el estado anterior del navegador.
  |
  */

  it('should reject a cached user when Laravel returns 401', async () => {
    /*
     * Primero simulamos una sesión válida.
     */
    establishSession();

    expect(auth.currentUser()).toEqual(
      testUser
    );

    /*
     * Después intentamos activar
     * nuevamente una ruta protegida.
     */
    const decision = executeGuard();

    const request = httpMock.expectOne(
      '/api/user'
    );

    /*
     * Laravel ya no reconoce la sesión.
     */
    request.flush(
      {
        message: 'Unauthenticated.',
      },
      {
        status: 401,
        statusText: 'Unauthorized',
      }
    );

    const result = await decision;

    expect(result).toBeInstanceOf(UrlTree);

    expect(
      router.serializeUrl(result as UrlTree)
    ).toBe('/sign-in');

    /*
     * El AuthService debe eliminar
     * la identidad anterior.
     */
    expect(auth.currentUser()).toBeNull();

    expect(auth.isAuthenticated()).toBe(
      false
    );
  });
});
