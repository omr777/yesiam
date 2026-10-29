import {
  HttpErrorResponse,
  provideHttpClient,
  withXsrfConfiguration,
} from '@angular/common/http';

import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';

import { TestBed } from '@angular/core/testing';

import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
} from 'vitest';

import {
  AuthService,
  AuthenticatedUser,
  LoginCredentials,
} from './auth.service';

/*
|--------------------------------------------------------------------------
| YESIAM - AUTH SERVICE TESTS
|--------------------------------------------------------------------------
|
| FASE 19.3.8
|
| Pruebas unitarias para autenticación
| mediante Laravel Sanctum.
|
| No se realizan solicitudes reales.
| No se utilizan usuarios reales.
| No se modifica ninguna base de datos.
|
| HttpTestingController permite interceptar
| y responder las solicitudes de HttpClient.
|
*/

describe('YESIAM - AuthService', () => {
  let service: AuthService;
  let httpMock: HttpTestingController;

  /*
   * Identidad ficticia utilizada
   * exclusivamente en estas pruebas.
   */
  const testUser: AuthenticatedUser = {
    id: 10,
    name: 'Usuario de prueba',
    email: 'usuario@example.test',
    status: 'active',
    department_id: null,
  };

  const credentials: LoginCredentials = {
    email: 'usuario@example.test',
    password: 'PasswordSoloParaPruebas123!',
  };

  /*
  |--------------------------------------------------------------------------
  | CONFIGURACIÓN
  |--------------------------------------------------------------------------
  |
  | El orden de providers es importante:
  |
  | 1. provideHttpClient()
  | 2. provideHttpClientTesting()
  |
  | El segundo reemplaza el backend HTTP
  | real por uno controlado por las pruebas.
  |
  */

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(
          withXsrfConfiguration({
            cookieName: 'XSRF-TOKEN',
            headerName: 'X-XSRF-TOKEN',
          })
        ),

        provideHttpClientTesting(),
      ],
    });

    service = TestBed.inject(AuthService);

    httpMock = TestBed.inject(
      HttpTestingController
    );
  });

  /*
   * Detecta solicitudes pendientes
   * que una prueba no haya gestionado.
   */
  afterEach(() => {
    httpMock.verify();
  });

  /*
  |--------------------------------------------------------------------------
  | FUNCIÓN AUXILIAR
  |--------------------------------------------------------------------------
  |
  | Simula una sesión válida consultando
  | GET /api/user.
  |
  | No ejecuta un login real.
  |
  */

  function establishSession(): void {
    service.checkSession().subscribe();

    const request = httpMock.expectOne(
      '/api/user'
    );

    expect(request.request.method).toBe(
      'GET'
    );

    request.flush(testUser);

    expect(service.isAuthenticated()).toBe(
      true
    );
  }

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 1 - ESTADO INICIAL
  |--------------------------------------------------------------------------
  */

  it('should start without an authenticated user', () => {
    expect(service.currentUser()).toBeNull();

    expect(service.isAuthenticated()).toBe(
      false
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 2 - FLUJO COMPLETO DE LOGIN
  |--------------------------------------------------------------------------
  |
  | Verificamos:
  |
  | CSRF -> LOGIN -> API USER
  |
  | Cada solicitud debe comenzar después
  | de que finalice la anterior.
  |
  */

  it('should authenticate using the Sanctum flow', () => {
    let receivedUser:
      AuthenticatedUser | undefined;

    service.login(credentials).subscribe({
      next: (user) => {
        receivedUser = user;
      },
    });

    /*
     * PASO 1: CSRF
     */
    const csrfRequest = httpMock.expectOne(
      '/sanctum/csrf-cookie'
    );

    expect(csrfRequest.request.method).toBe(
      'GET'
    );

    expect(
      csrfRequest.request.withCredentials
    ).toBe(true);

    /*
     * Login todavía no debe comenzar.
     */
    httpMock.expectNone('/login');

    csrfRequest.flush(null);

    /*
     * PASO 2: LOGIN
     */
    const loginRequest = httpMock.expectOne(
      '/login'
    );

    expect(loginRequest.request.method).toBe(
      'POST'
    );

    expect(loginRequest.request.body).toEqual(
      credentials
    );

    expect(
      loginRequest.request.headers.get('Accept')
    ).toBe('application/json');

    expect(
      loginRequest.request.withCredentials
    ).toBe(true);

    /*
     * /api/user todavía no debe comenzar.
     */
    httpMock.expectNone('/api/user');

    loginRequest.flush({
      message: 'Sesión iniciada correctamente.',
      user: {
        id: testUser.id,
        name: testUser.name,
        email: testUser.email,
      },
    });

    /*
     * PASO 3: USUARIO AUTENTICADO
     */
    const userRequest = httpMock.expectOne(
      '/api/user'
    );

    expect(userRequest.request.method).toBe(
      'GET'
    );

    expect(
      userRequest.request.withCredentials
    ).toBe(true);

    userRequest.flush(testUser);

    /*
     * RESULTADO FINAL
     */
    expect(receivedUser).toEqual(testUser);

    expect(service.currentUser()).toEqual(
      testUser
    );

    expect(service.isAuthenticated()).toBe(
      true
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 3 - ERROR DE CSRF
  |--------------------------------------------------------------------------
  |
  | Si falla la preparación CSRF,
  | no debemos enviar las credenciales.
  |
  */

  it('should not send credentials when CSRF preparation fails', () => {
    let receivedError:
      HttpErrorResponse | null = null;

    service.login(credentials).subscribe({
      error: (error: HttpErrorResponse) => {
        receivedError = error;
      },
    });

    const csrfRequest = httpMock.expectOne(
      '/sanctum/csrf-cookie'
    );

    csrfRequest.flush(
      {
        message: 'Error CSRF',
      },
      {
        status: 419,
        statusText: 'Page Expired',
      }
    );

    /*
     * Nunca debe enviarse el login.
     */
    httpMock.expectNone('/login');

    httpMock.expectNone('/api/user');

    expect(receivedError).toBeInstanceOf(
      HttpErrorResponse
    );

    expect(service.currentUser()).toBeNull();

    expect(service.isAuthenticated()).toBe(
      false
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 4 - RECUPERAR SESIÓN
  |--------------------------------------------------------------------------
  */

  it('should restore an existing authenticated session', () => {
    let receivedUser:
      AuthenticatedUser | null | undefined;

    service.checkSession().subscribe({
      next: (user) => {
        receivedUser = user;
      },
    });

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

    expect(receivedUser).toEqual(testUser);

    expect(service.currentUser()).toEqual(
      testUser
    );

    expect(service.isAuthenticated()).toBe(
      true
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 5 - RESPUESTA 401
  |--------------------------------------------------------------------------
  |
  | Una sesión no autenticada debe
  | limpiar el estado local del usuario.
  |
  */

  it('should clear the user when Laravel returns 401', () => {
    establishSession();

    let receivedUser:
      AuthenticatedUser | null | undefined;

    service.checkSession().subscribe({
      next: (user) => {
        receivedUser = user;
      },
    });

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

    expect(receivedUser).toBeNull();

    expect(service.currentUser()).toBeNull();

    expect(service.isAuthenticated()).toBe(
      false
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 6 - RESPUESTA 403
  |--------------------------------------------------------------------------
  |
  | Laravel puede rechazar una cuenta
  | desactivada mediante account.active.
  |
  */

  it('should clear the user when Laravel returns 403', () => {
    establishSession();

    let receivedUser:
      AuthenticatedUser | null | undefined;

    service.checkSession().subscribe({
      next: (user) => {
        receivedUser = user;
      },
    });

    const request = httpMock.expectOne(
      '/api/user'
    );

    request.flush(
      {
        message: 'Cuenta no disponible.',
      },
      {
        status: 403,
        statusText: 'Forbidden',
      }
    );

    expect(receivedUser).toBeNull();

    expect(service.currentUser()).toBeNull();

    expect(service.isAuthenticated()).toBe(
      false
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 7 - ERROR DEL SERVIDOR
  |--------------------------------------------------------------------------
  |
  | Un error 500 no debe interpretarse
  | automáticamente como una sesión inválida.
  |
  */

  it('should preserve local user state on a server error', () => {
    establishSession();

    let receivedError:
      HttpErrorResponse | null = null;

    service.checkSession().subscribe({
      error: (error: HttpErrorResponse) => {
        receivedError = error;
      },
    });

    const request = httpMock.expectOne(
      '/api/user'
    );

    request.flush(
      {
        message: 'Internal Server Error',
      },
      {
        status: 500,
        statusText: 'Server Error',
      }
    );

    expect(receivedError).toBeInstanceOf(
      HttpErrorResponse
    );

    expect(service.currentUser()).toEqual(
      testUser
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 8 - LOGOUT CORRECTO
  |--------------------------------------------------------------------------
  |
  | El usuario solo debe eliminarse
  | del estado local cuando Laravel
  | confirme el cierre de sesión.
  |
  */

  it('should log out and clear the authenticated user', () => {
    establishSession();

    let completed = false;

    service.logout().subscribe({
      next: () => {
        completed = true;
      },
    });

    const request = httpMock.expectOne(
      '/logout'
    );

    expect(request.request.method).toBe(
      'POST'
    );

    expect(
      request.request.withCredentials
    ).toBe(true);

    /*
     * Todavía no ha respondido Laravel.
     */
    expect(service.isAuthenticated()).toBe(
      true
    );

    expect(completed).toBe(false);

    request.flush({
      message: 'Sesión cerrada correctamente.',
    });

    expect(completed).toBe(true);

    expect(service.currentUser()).toBeNull();

    expect(service.isAuthenticated()).toBe(
      false
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 9 - LOGOUT FALLIDO
  |--------------------------------------------------------------------------
  |
  | Si el servidor falla, el servicio
  | no debe afirmar que cerró la sesión.
  |
  */

  it('should not report a successful logout when the server fails', () => {
    establishSession();

    let receivedError:
      HttpErrorResponse | null = null;

    service.logout().subscribe({
      error: (error: HttpErrorResponse) => {
        receivedError = error;
      },
    });

    const request = httpMock.expectOne(
      '/logout'
    );

    request.flush(
      {
        message: 'Error al cerrar sesión.',
      },
      {
        status: 500,
        statusText: 'Server Error',
      }
    );

    expect(receivedError).toBeInstanceOf(
      HttpErrorResponse
    );

    /*
     * Conservamos el estado local porque
     * Laravel no confirmó el logout.
     */
    expect(service.currentUser()).toEqual(
      testUser
    );

    expect(service.isAuthenticated()).toBe(
      true
    );
  });

});
