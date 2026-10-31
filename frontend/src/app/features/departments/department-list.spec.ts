import {
  ComponentFixture,
  TestBed,
} from '@angular/core/testing';

import { provideHttpClient } from '@angular/common/http';

import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';

import { provideRouter } from '@angular/router';

import { signInAs } from '../../testing/session';

import { DepartmentList } from './department-list';

/*
|--------------------------------------------------------------------------
| YESIAM - PANTALLA DE DEPARTAMENTOS
|--------------------------------------------------------------------------
|
| FASE 21
|
*/

describe('DepartmentList', () => {

  let fixture: ComponentFixture<DepartmentList>;
  let httpMock: HttpTestingController;

  const departments = [
    { id: 1, name: 'Tecnología', code: 'TI', is_active: true },
    { id: 2, name: 'Archivo', code: 'ARC', is_active: false },
    { id: 3, name: 'Finanzas', code: 'FIN', is_active: true },
  ];

  function root(): HTMLElement {
    return fixture.nativeElement as HTMLElement;
  }

  function setup(permissions?: string[]): void {
    signInAs(httpMock, permissions ? { permissions } : {});

    fixture = TestBed.createComponent(DepartmentList);
    fixture.detectChanges();

    httpMock.expectOne('/api/departments').flush({ data: departments });
    fixture.detectChanges();
  }

  function type(selector: string, value: string): void {
    const input = root().querySelector(selector) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    fixture?.destroy();
    httpMock.verify();
  });

  it('should list active departments first', () => {
    setup();

    const names = Array.from(root().querySelectorAll('tbody tr .cell-primary'))
      .map((cell) => cell.textContent?.trim());

    expect(names).toEqual(['Finanzas', 'Tecnología', 'Archivo']);
    expect(root().textContent).toContain('2 activos, 1 inactivos');
  });

  it('should hide management actions for read-only profiles', () => {
    setup(['departments.view']);

    expect(root().querySelector('#department-name')).toBeNull();
    expect(root().textContent).not.toContain('Editar');
  });

  it('should upper-case the code and create the department', () => {
    setup();

    type('#department-name', ' Seguridad ');
    type('#department-code', 'sec');

    expect((root().querySelector('#department-code') as HTMLInputElement).value).toBe('SEC');

    root().querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }));

    const request = httpMock.expectOne((req) =>
      req.method === 'POST' && req.url === '/api/departments'
    );

    expect(request.request.body).toEqual({ name: 'Seguridad', code: 'SEC' });

    request.flush(
      { message: 'ok', data: { id: 4, name: 'Seguridad', code: 'SEC', is_active: true } },
      { status: 201, statusText: 'Created' }
    );

    httpMock.expectOne('/api/departments').flush({ data: departments });
  });

  it('should reject an invalid code without calling Laravel', () => {
    setup();

    type('#department-name', 'Seguridad');
    type('#department-code', 'SE C');

    root().querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }));
    fixture.detectChanges();

    httpMock.expectNone((req) => req.method === 'POST');

    expect(root().textContent).toContain('Usa solo mayúsculas');
  });

  it('should mark duplicated codes returned by Laravel', () => {
    setup();

    type('#department-name', 'Otro');
    type('#department-code', 'TI');

    root().querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }));

    httpMock.expectOne((req) => req.method === 'POST').flush(
      { message: 'invalid', errors: { code: ['The code has already been taken.'] } },
      { status: 422, statusText: 'Unprocessable Content' }
    );
    fixture.detectChanges();

    expect(root().textContent).toContain('El código no es válido o ya existe.');
    expect(root().textContent).not.toContain('already been taken');
  });

  it('should deactivate only after typing the department code', () => {
    setup();

    const row = Array.from(root().querySelectorAll('tbody tr'))
      .find((item) => item.textContent?.includes('Finanzas'))!;

    const deactivate = Array.from(row.querySelectorAll('button'))
      .find((button) => button.textContent?.trim() === 'Desactivar') as HTMLButtonElement;

    deactivate.click();
    fixture.detectChanges();

    const dialog = root().querySelector('dialog[open]')!;
    const confirm = dialog.querySelector('button[type=submit]') as HTMLButtonElement;

    expect(confirm.disabled).toBe(true);

    const input = dialog.querySelector('input[name=confirm]') as HTMLInputElement;
    input.value = 'FIN';
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(confirm.disabled).toBe(false);

    dialog.querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }));

    const request = httpMock.expectOne('/api/departments/3/deactivate');

    expect(request.request.method).toBe('PATCH');

    request.flush({
      message: 'ok',
      data: { id: 3, name: 'Finanzas', code: 'FIN', is_active: false },
      revoked_access_grants_count: 2,
    });

    httpMock.expectOne('/api/departments').flush({ data: departments });
  });
});
