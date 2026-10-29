
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { App } from './app';

/*
|--------------------------------------------------------------------------
| YESIAM - PRUEBAS DEL COMPONENTE RAÍZ
|--------------------------------------------------------------------------
|
| Verificamos que:
|
| 1. Angular pueda crear el componente raíz.
| 2. El componente proporcione RouterOutlet.
|
| Nuestro MainLayout se mostrará mediante
| el sistema de rutas de Angular.
|
| Estas pruebas no dependen del contenido
| visual predeterminado de Angular.
|
*/

describe('YESIAM - App', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [
        provideRouter([]),
      ],
    }).compileComponents();
  });

  it('should create the application', () => {
    const fixture = TestBed.createComponent(App);

    const app = fixture.componentInstance;

    expect(app).toBeTruthy();
  });

  it('should provide a router outlet', () => {
    const fixture = TestBed.createComponent(App);

    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;

    const routerOutlet = compiled.querySelector(
      'router-outlet'
    );

    expect(routerOutlet).not.toBeNull();
  });
});
