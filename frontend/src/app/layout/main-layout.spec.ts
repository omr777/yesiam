
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';

import {
  EMPLOYEE_PERMISSIONS,
  signInAs,
} from '../testing/session';

import { MainLayout } from './main-layout';

/*
|--------------------------------------------------------------------------
| YESIAM - MAIN LAYOUT TESTS
|--------------------------------------------------------------------------
|
| Verifica:
|
| 1. Creación del componente.
| 2. Presencia del menú y del enlace Dashboard.
| 3. Presencia del RouterOutlet para páginas hijas.
| 4. Apertura y cierre del menú móvil.
|
| Las pruebas no requieren Laravel ni MySQL.
|
*/

describe('YESIAM - MainLayout', () => {

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [MainLayout],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    }).compileComponents();
  });

  it('should create the layout', () => {
    const fixture = TestBed.createComponent(
      MainLayout
    );

    expect(fixture.componentInstance).toBeTruthy();
  });

  it('should display the navigation and Dashboard link', () => {
    const fixture = TestBed.createComponent(
      MainLayout
    );

    fixture.detectChanges();

    const element =
      fixture.nativeElement as HTMLElement;

    const navigation = element.querySelector(
      'nav[aria-label="Módulos de YESIAM"]'
    );

    const dashboardLink = element.querySelector(
      'nav a.nav-item'
    ) as HTMLAnchorElement | null;

    expect(navigation).not.toBeNull();
    expect(dashboardLink).not.toBeNull();

    expect(
      dashboardLink?.getAttribute('href')
    ).toBe('/');

    expect(
      dashboardLink?.textContent
    ).toContain('Dashboard');
  });

  it('should provide an outlet for child routes', () => {
    const fixture = TestBed.createComponent(
      MainLayout
    );

    fixture.detectChanges();

    const element =
      fixture.nativeElement as HTMLElement;

    expect(
      element.querySelector(
        'main#main-content router-outlet'
      )
    ).not.toBeNull();
  });

  it('should open and close the mobile menu', () => {
    const fixture = TestBed.createComponent(
      MainLayout
    );

    fixture.detectChanges();

    const element =
      fixture.nativeElement as HTMLElement;

    const button = element.querySelector(
      '.mobile-menu-button'
    ) as HTMLButtonElement | null;

    const navigation = element.querySelector(
      '#yesiam-navigation'
    ) as HTMLElement | null;

    expect(button).not.toBeNull();
    expect(navigation).not.toBeNull();

    if (!button || !navigation) {
      throw new Error(
        'No se encontraron los elementos del menú.'
      );
    }

    // Estado inicial: cerrado.
    expect(
      fixture.componentInstance.mobileMenuOpen()
    ).toBe(false);

    expect(
      button.getAttribute('aria-expanded')
    ).toBe('false');

    expect(
      navigation.classList.contains('is-open')
    ).toBe(false);

    // Abrir.
    button.click();
    fixture.detectChanges();

    expect(
      fixture.componentInstance.mobileMenuOpen()
    ).toBe(true);

    expect(
      button.getAttribute('aria-expanded')
    ).toBe('true');

    expect(
      navigation.classList.contains('is-open')
    ).toBe(true);

    // Cerrar.
    button.click();
    fixture.detectChanges();

    expect(
      fixture.componentInstance.mobileMenuOpen()
    ).toBe(false);

    expect(
      button.getAttribute('aria-expanded')
    ).toBe('false');

    expect(
      navigation.classList.contains('is-open')
    ).toBe(false);
  });


  /*
  |--------------------------------------------------------------------------
  | FASE 21 - NAVEGACIÓN POR PERMISOS
  |--------------------------------------------------------------------------
  */

  it('should show only the modules allowed for an employee', () => {
    signInAs(TestBed.inject(HttpTestingController), {
      name: 'Ana Torres',
      roles: [{ code: 'employee', name: 'Empleado' }],
      permissions: EMPLOYEE_PERMISSIONS,
    });

    const fixture = TestBed.createComponent(MainLayout);
    fixture.detectChanges();

    const nav = (fixture.nativeElement as HTMLElement)
      .querySelector('nav.navigation')!.textContent ?? '';

    expect(nav).toContain('Solicitar acceso');
    expect(nav).toContain('Mis accesos');
    expect(nav).toContain('Recursos');
    expect(nav).not.toContain('Identidades');
    expect(nav).not.toContain('Aprobaciones');
    expect(nav).not.toContain('Concesiones');
    expect(nav).not.toContain('Auditoría');
  });

  it('should show administration modules and the session card for an admin', () => {
    signInAs(TestBed.inject(HttpTestingController));

    const fixture = TestBed.createComponent(MainLayout);
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;
    const nav = element.querySelector('nav.navigation')!.textContent ?? '';

    expect(nav).toContain('Identidades');
    expect(nav).toContain('Departamentos');
    expect(nav).toContain('Concesiones');
    expect(nav).toContain('Auditoría');

    const session = element.querySelector('.session-card')!.textContent ?? '';

    expect(session).toContain('Omar García');
    expect(session).toContain('Tecnología');
    expect(session).toContain('Administrador IAM');
    expect(element.querySelector('.session-avatar')!.textContent).toContain('OG');
  });
});
