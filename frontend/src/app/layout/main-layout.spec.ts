
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

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

});
