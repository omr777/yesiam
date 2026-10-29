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

import { AuthService } from './auth.service';
import { LogoutButton } from './logout-button';

/*
|--------------------------------------------------------------------------
| YESIAM - LOGOUT BUTTON TESTS
|--------------------------------------------------------------------------
|
| Fase 19.3.16
|
| Verificamos:
|
| 1. Renderización del botón.
| 2. Logout correcto.
| 3. Prevención de solicitudes duplicadas.
| 4. Manejo de errores del servidor.
| 5. Reintento después de un error.
|
| Todas las solicitudes HTTP son simuladas.
| Ninguna prueba modifica MySQL.
|
*/

describe('YESIAM - LogoutButton', () => {
  let auth: AuthService;
  let httpMock: HttpTestingController;
  let router: Router;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [LogoutButton],

      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    auth = TestBed.inject(AuthService);

    httpMock = TestBed.inject(
      HttpTestingController
    );

    router = TestBed.inject(Router);
  });

  afterEach(() => {
    httpMock.verify();
    vi.restoreAllMocks();
  });

  /*
  |--------------------------------------------------------------------------
  | SESIÓN SIMULADA
  |--------------------------------------------------------------------------
  */

  function establishSession(): void {
    auth.checkSession().subscribe();

    const request = httpMock.expectOne(
      '/api/user'
    );

    expect(request.request.method).toBe('GET');

    request.flush({
      id: 1,
      name: 'Administrador de prueba',
      email: 'admin@example.test',
      status: 'active',
    });

    expect(auth.isAuthenticated()).toBe(true);
  }

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 1 - RENDERIZACIÓN
  |--------------------------------------------------------------------------
  */

  it('should render the logout button', () => {
    const fixture = TestBed.createComponent(
      LogoutButton
    );

    fixture.detectChanges();

    const button: HTMLButtonElement =
      fixture.nativeElement.querySelector(
        '.logout-button'
      );

    expect(button).toBeTruthy();

    expect(button.textContent).toContain(
      'Cerrar sesión'
    );

    expect(button.disabled).toBe(false);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 2 - LOGOUT CORRECTO
  |--------------------------------------------------------------------------
  */

  it('should clear the session and redirect after a successful logout', () => {
    establishSession();

    const navigateSpy = vi
      .spyOn(router, 'navigateByUrl')
      .mockResolvedValue(true);

    const fixture = TestBed.createComponent(
      LogoutButton
    );

    fixture.detectChanges();

    const button: HTMLButtonElement =
      fixture.nativeElement.querySelector(
        '.logout-button'
      );

    button.click();

    const request = httpMock.expectOne(
      '/logout'
    );

    expect(request.request.method).toBe('POST');

    expect(request.request.body).toEqual({});

    expect(
      request.request.withCredentials
    ).toBe(true);

    // Laravel todavía no confirma el cierre.
    expect(auth.isAuthenticated()).toBe(true);

    expect(navigateSpy).not.toHaveBeenCalled();

    fixture.detectChanges();

    expect(button.disabled).toBe(true);

    // Laravel confirma el cierre.
    request.flush({
      message: 'Sesión cerrada correctamente.',
    });

    expect(auth.currentUser()).toBeNull();

    expect(auth.isAuthenticated()).toBe(false);

    expect(navigateSpy).toHaveBeenCalledWith(
      '/sign-in',
      {
        replaceUrl: true,
      }
    );

    expect(
      fixture.componentInstance.isLoggingOut()
    ).toBe(false);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 3 - EVITAR SOLICITUDES DUPLICADAS
  |--------------------------------------------------------------------------
  |
  | CORRECCIÓN:
  |
  | Simulamos también la navegación.
  |
  | Así no intentamos abrir una ruta real
  | que no está registrada en el Router
  | de este entorno de pruebas.
  |
  */

  it('should prevent duplicate logout requests', () => {
    const navigateSpy = vi
      .spyOn(router, 'navigateByUrl')
      .mockResolvedValue(true);

    const fixture = TestBed.createComponent(
      LogoutButton
    );

    fixture.detectChanges();

    fixture.componentInstance.onLogout();
    fixture.componentInstance.onLogout();

    const requests = httpMock.match(
      '/logout'
    );

    // Solo debe existir una solicitud.
    expect(requests.length).toBe(1);

    requests[0].flush({
      message: 'Sesión cerrada correctamente.',
    });

    // Comprobamos que también solicitó
    // la navegación al finalizar el logout.
    expect(navigateSpy).toHaveBeenCalledOnce();

    expect(navigateSpy).toHaveBeenCalledWith(
      '/sign-in',
      {
        replaceUrl: true,
      }
    );
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 4 - ERROR DEL SERVIDOR
  |--------------------------------------------------------------------------
  */

  it('should preserve the user and display an error when logout fails', () => {
    establishSession();

    const navigateSpy = vi
      .spyOn(router, 'navigateByUrl')
      .mockResolvedValue(true);

    const fixture = TestBed.createComponent(
      LogoutButton
    );

    fixture.detectChanges();

    const button: HTMLButtonElement =
      fixture.nativeElement.querySelector(
        '.logout-button'
      );

    button.click();

    const request = httpMock.expectOne(
      '/logout'
    );

    request.flush(
      {
        message: 'Error interno del servidor.',
      },
      {
        status: 500,
        statusText: 'Server Error',
      }
    );

    fixture.detectChanges();

    const errorMessage: HTMLElement =
      fixture.nativeElement.querySelector(
        '[role="alert"]'
      );

    expect(errorMessage).toBeTruthy();

    expect(errorMessage.textContent).toContain(
      'No fue posible confirmar el cierre de sesión'
    );

    expect(auth.isAuthenticated()).toBe(true);

    expect(navigateSpy).not.toHaveBeenCalled();

    expect(button.disabled).toBe(false);
  });

  /*
  |--------------------------------------------------------------------------
  | PRUEBA 5 - REINTENTO DESPUÉS DE ERROR
  |--------------------------------------------------------------------------
  */

  it('should allow retrying logout after a server error', () => {
    const navigateSpy = vi
      .spyOn(router, 'navigateByUrl')
      .mockResolvedValue(true);

    const fixture = TestBed.createComponent(
      LogoutButton
    );

    fixture.detectChanges();

    const button: HTMLButtonElement =
      fixture.nativeElement.querySelector(
        '.logout-button'
      );

    // Primer intento: falla.
    button.click();

    const failedRequest = httpMock.expectOne(
      '/logout'
    );

    failedRequest.flush(
      {
        message: 'Servicio temporalmente no disponible.',
      },
      {
        status: 503,
        statusText: 'Service Unavailable',
      }
    );

    fixture.detectChanges();

    expect(button.disabled).toBe(false);

    expect(
      fixture.componentInstance.errorMessage()
    ).not.toBeNull();

    // Segundo intento: funciona.
    button.click();

    expect(
      fixture.componentInstance.errorMessage()
    ).toBeNull();

    const successfulRequest = httpMock.expectOne(
      '/logout'
    );

    successfulRequest.flush({
      message: 'Sesión cerrada correctamente.',
    });

    expect(navigateSpy).toHaveBeenCalledWith(
      '/sign-in',
      {
        replaceUrl: true,
      }
    );

    expect(
      fixture.componentInstance.errorMessage()
    ).toBeNull();
  });
});
