
import { provideHttpClient } from '@angular/common/http';

import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';

import { TestBed } from '@angular/core/testing';

import {
  provideRouter,
  Router,
} from '@angular/router';

import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
} from 'vitest';

import { AuthService } from '../../core/auth/auth.service';
import { Login } from './login';

/*
|--------------------------------------------------------------------------
| YESIAM - LOGIN COMPONENT TESTS
|--------------------------------------------------------------------------
|
| Fase 19.3.18
|
| Pruebas del formulario de autenticación.
|
| - No utilizamos credenciales reales.
| - No accedemos a MySQL.
| - No realizamos solicitudes HTTP reales.
| - Verificamos validación y manejo de errores.
|
*/

describe('YESIAM - Login', () => {
  let httpMock: HttpTestingController;
  let router: Router;
  let auth: AuthService;

  const credentials = {
    email: 'admin@example.test',
    password: 'PasswordDePrueba123!',
  };

  const testUser = {
    id: 1,
    name: 'Administrador de prueba',
    email: 'admin@example.test',
    status: 'active',
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [Login],

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
    httpMock.verify();
    vi.restoreAllMocks();
  });

  /*
   * Crea el componente con su formulario
   * reactivo y ejecuta el primer render.
   */
  function createLogin() {
    const fixture = TestBed.createComponent(Login);
    fixture.detectChanges();
    return fixture;
  }

  /*
   * Completa el formulario con datos
   * ficticios válidos.
   */
  function fillValidForm(
    component: Login
  ): void {
    component.form.setValue(credentials);
  }

  /*
   * Simula la respuesta de Sanctum
   * al solicitar la cookie CSRF.
   */
  function completeCsrf(): void {
    const request = httpMock.expectOne(
      '/sanctum/csrf-cookie'
    );

    expect(request.request.method).toBe('GET');

    request.flush(null);
  }

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 1 - RENDERIZACIÓN
  |--------------------------------------------------------------------------
  */

  it('should render the login form', () => {
    const fixture = createLogin();

    const email: HTMLInputElement =
      fixture.nativeElement.querySelector(
        '#email'
      );

    const password: HTMLInputElement =
      fixture.nativeElement.querySelector(
        '#password'
      );

    const button: HTMLButtonElement =
      fixture.nativeElement.querySelector(
        '.submit-button'
      );

    expect(email).toBeTruthy();
    expect(password).toBeTruthy();
    expect(button).toBeTruthy();

    expect(button.textContent).toContain(
      'Iniciar sesión'
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 2 - CAMPOS OBLIGATORIOS
  |--------------------------------------------------------------------------
  */

  it('should reject an empty form without making HTTP requests', () => {
    const fixture = createLogin();

    fixture.componentInstance.onSubmit();
    fixture.detectChanges();

    expect(
      fixture.componentInstance.form.invalid
    ).toBe(true);

    expect(
      fixture.componentInstance.form.controls.email.touched
    ).toBe(true);

    expect(
      fixture.componentInstance.form.controls.password.touched
    ).toBe(true);

    expect(
      fixture.nativeElement.textContent
    ).toContain(
      'Ingresa tu correo electrónico.'
    );

    expect(
      fixture.nativeElement.textContent
    ).toContain(
      'Ingresa tu contraseña.'
    );

    httpMock.expectNone(
      '/sanctum/csrf-cookie'
    );

    httpMock.expectNone('/login');
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 3 - FORMATO DE CORREO
  |--------------------------------------------------------------------------
  */

  it('should reject an invalid email', () => {
    const fixture = createLogin();

    fixture.componentInstance.form.setValue({
      email: 'correo-invalido',
      password: 'PasswordDePrueba123!',
    });

    fixture.componentInstance.onSubmit();
    fixture.detectChanges();

    expect(
      fixture.componentInstance.form.invalid
    ).toBe(true);

    expect(
      fixture.nativeElement.textContent
    ).toContain(
      'Ingresa un correo válido.'
    );

    httpMock.expectNone('/login');
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 4 - LOGIN CORRECTO
  |--------------------------------------------------------------------------
  |
  | Flujo esperado:
  |
  | CSRF -> LOGIN -> API USER -> DASHBOARD
  |
  */

  it('should navigate to the Dashboard after successful authentication', () => {
    const navigateSpy = vi
      .spyOn(router, 'navigateByUrl')
      .mockResolvedValue(true);

    const fixture = createLogin();

    fillValidForm(fixture.componentInstance);

    /*
     * Enviamos el formulario desde el DOM.
     */
    const form: HTMLFormElement =
      fixture.nativeElement.querySelector(
        'form'
      );

    form.dispatchEvent(
      new Event('submit', {
        bubbles: true,
        cancelable: true,
      })
    );

    /*
     * PASO 1: CSRF
     */
    completeCsrf();

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

    loginRequest.flush({
      message: 'Sesión iniciada correctamente.',
      user: testUser,
    });

    /*
     * PASO 3: USUARIO AUTENTICADO
     */
    const userRequest = httpMock.expectOne(
      '/api/user'
    );

    userRequest.flush(testUser);

    /*
     * PASO 4: DASHBOARD
     */
    expect(auth.isAuthenticated()).toBe(true);

    expect(navigateSpy).toHaveBeenCalledWith(
      '/'
    );

    /*
     * La contraseña se limpia
     * después del login correcto.
     */
    expect(
      fixture.componentInstance.form.controls.password.value
    ).toBe('');

    expect(
      fixture.componentInstance.isSubmitting()
    ).toBe(false);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 5 - CREDENCIALES RECHAZADAS
  |--------------------------------------------------------------------------
  */

  it('should show an error when Laravel rejects credentials', () => {
    const navigateSpy = vi
      .spyOn(router, 'navigateByUrl')
      .mockResolvedValue(true);

    const fixture = createLogin();

    fillValidForm(fixture.componentInstance);

    fixture.componentInstance.onSubmit();

    completeCsrf();

    const loginRequest = httpMock.expectOne(
      '/login'
    );

    loginRequest.flush(
      {
        message: 'Credenciales inválidas.',
      },
      {
        status: 422,
        statusText: 'Unprocessable Entity',
      }
    );

    fixture.detectChanges();

    expect(
      fixture.componentInstance.errorMessage()
    ).toBe(
      'Credenciales inválidas o cuenta no disponible.'
    );

    expect(auth.isAuthenticated()).toBe(false);

    expect(navigateSpy).not.toHaveBeenCalled();

    expect(
      fixture.componentInstance.isSubmitting()
    ).toBe(false);

    httpMock.expectNone('/api/user');
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 6 - ERROR CSRF
  |--------------------------------------------------------------------------
  */

  it('should handle an expired CSRF session', () => {
    const fixture = createLogin();

    fillValidForm(fixture.componentInstance);

    fixture.componentInstance.onSubmit();

    const csrfRequest = httpMock.expectOne(
      '/sanctum/csrf-cookie'
    );

    csrfRequest.flush(
      {
        message: 'Page Expired',
      },
      {
        status: 419,
        statusText: 'Page Expired',
      }
    );

    expect(
      fixture.componentInstance.errorMessage()
    ).toContain(
      'La sesión de seguridad expiró'
    );

    /*
     * No enviamos credenciales
     * si falló el paso CSRF.
     */
    httpMock.expectNone('/login');
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 7 - LÍMITE DE INTENTOS
  |--------------------------------------------------------------------------
  |
  | Laravel protege POST /login
  | con throttle:5,1.
  |
  */

  it('should handle HTTP 429 from Laravel', () => {
    const fixture = createLogin();

    fillValidForm(fixture.componentInstance);

    fixture.componentInstance.onSubmit();

    completeCsrf();

    const loginRequest = httpMock.expectOne(
      '/login'
    );

    loginRequest.flush(
      {
        message: 'Too Many Requests',
      },
      {
        status: 429,
        statusText: 'Too Many Requests',
      }
    );

    expect(
      fixture.componentInstance.errorMessage()
    ).toContain(
      'Demasiados intentos'
    );

    expect(
      fixture.componentInstance.isSubmitting()
    ).toBe(false);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 8 - ERROR DE CONEXIÓN
  |--------------------------------------------------------------------------
  */

  it('should show a connection error when Laravel is unreachable', () => {
    const fixture = createLogin();

    fillValidForm(fixture.componentInstance);

    fixture.componentInstance.onSubmit();

    const csrfRequest = httpMock.expectOne(
      '/sanctum/csrf-cookie'
    );

    csrfRequest.error(
      new ProgressEvent('error')
    );

    expect(
      fixture.componentInstance.errorMessage()
    ).toBe(
      'No fue posible conectar con el servidor.'
    );

    expect(auth.isAuthenticated()).toBe(false);

    httpMock.expectNone('/login');
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 9 - PREVENIR ENVÍOS DUPLICADOS
  |--------------------------------------------------------------------------
  */

  it('should prevent duplicate login submissions', () => {
    const fixture = createLogin();

    fillValidForm(fixture.componentInstance);

    /*
     * Simulamos dos intentos mientras
     * la primera solicitud está pendiente.
     */
    fixture.componentInstance.onSubmit();
    fixture.componentInstance.onSubmit();

    const csrfRequests = httpMock.match(
      '/sanctum/csrf-cookie'
    );

    expect(csrfRequests.length).toBe(1);

    expect(
      fixture.componentInstance.isSubmitting()
    ).toBe(true);

    /*
     * Terminamos la solicitud pendiente
     * con un error controlado.
     */
    csrfRequests[0].flush(
      {
        message: 'Servicio no disponible.',
      },
      {
        status: 503,
        statusText: 'Service Unavailable',
      }
    );

    expect(
      fixture.componentInstance.isSubmitting()
    ).toBe(false);

    httpMock.expectNone('/login');
  });
});
