import {
  ComponentFixture,
  TestBed,
} from '@angular/core/testing';

import { provideHttpClient } from '@angular/common/http';

import {
  HttpTestingController,
  TestRequest,
  provideHttpClientTesting,
} from '@angular/common/http/testing';

import { provideRouter } from '@angular/router';

import { signInAs } from '../../testing/session';

import { IdentityDetail } from './identity-detail';
import type { IdentityDetail as IdentityDetailModel } from './identity.service';

/*
|--------------------------------------------------------------------------
| YESIAM - DETALLE DE IDENTIDAD
|--------------------------------------------------------------------------
|
| FASE 21
|
| Activación con contraseña temporal, roles,
| Mover, Leaver, inventario y separación de
| funciones (la propia identidad no se edita).
|
*/

describe('IdentityDetail', () => {

  let fixture: ComponentFixture<IdentityDetail>;
  let httpMock: HttpTestingController;

  const roles = [
    { code: 'employee', name: 'Empleado', permissions: ['access.request'] },
    { code: 'resource_manager', name: 'Responsable de recurso', permissions: ['access.approve'] },
    { code: 'iam_admin', name: 'Administrador IAM', permissions: ['users.manage'] },
  ];

  const departments = [
    { id: 1, name: 'Tecnología', code: 'TI', is_active: true },
    { id: 2, name: 'Finanzas', code: 'FIN', is_active: true },
    { id: 3, name: 'Ventas', code: 'VTA', is_active: true },
  ];

  function identity(
    overrides: Partial<IdentityDetailModel> = {}
  ): IdentityDetailModel {
    return {
      id: 7,
      name: 'Ana Torres',
      email: 'ana@example.test',
      status: 'suspended',
      department_id: 2,
      department: { id: 2, name: 'Finanzas', code: 'FIN', is_active: true },
      roles: [{ code: 'employee', name: 'Empleado' }],
      managed_resources: [],
      active_access_grants_count: 0,
      created_at: '2026-10-30T10:00:00Z',
      ...overrides,
    };
  }

  function grantsResponse(data: unknown[] = []) {
    return {
      message: 'ok',
      user: { id: 7, name: 'Ana Torres' },
      data,
      pagination: { current_page: 1, per_page: 20, total: data.length, last_page: 1 },
    };
  }

  function expectGet(url: string): TestRequest {
    return httpMock.expectOne(
      (request) => request.method === 'GET' && request.url === url
    );
  }

  function create(person: IdentityDetailModel = identity()): void {
    fixture = TestBed.createComponent(IdentityDetail);
    fixture.componentRef.setInput('id', person.id);
    fixture.detectChanges();

    expectGet('/api/roles').flush({ data: roles });
    expectGet('/api/departments').flush({ data: departments });
    expectGet(`/api/users/${person.id}`).flush({ data: person });
    fixture.detectChanges();

    expectGet(`/api/users/${person.id}/access-grants`).flush(grantsResponse());
    fixture.detectChanges();
  }

  function text(): string {
    return (fixture.nativeElement as HTMLElement).textContent ?? '';
  }

  function button(label: string): HTMLButtonElement {
    const found = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('button')
    ).find((item) => item.textContent?.trim() === label);

    if (!found) {
      throw new Error(`No se encontró el botón "${label}".`);
    }

    return found as HTMLButtonElement;
  }

  function confirmDialog(word?: string): void {
    const dialog = (fixture.nativeElement as HTMLElement)
      .querySelector('dialog[open]') as HTMLDialogElement;

    if (word) {
      const input = dialog.querySelector('input[name=confirm]') as HTMLInputElement;
      input.value = word;
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();
    }

    dialog.querySelector('form')!.dispatchEvent(
      new Event('submit', { cancelable: true })
    );
    fixture.detectChanges();
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [IdentityDetail],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);

    signInAs(httpMock);
  });

  afterEach(() => {
    fixture?.destroy();
    httpMock.verify();
  });

  it('should show the profile and the current lifecycle stage', () => {
    create();

    expect(text()).toContain('Ana Torres');
    expect(text()).toContain('Finanzas');
    expect(text()).toContain('Suspendida');

    const current = (fixture.nativeElement as HTMLElement)
      .querySelector('.lifecycle-step.is-current');

    expect(current?.textContent).toContain('Alta registrada');
  });

  it('should activate and show the temporary password once', () => {
    create();

    button('Activar identidad').click();
    fixture.detectChanges();

    const request = httpMock.expectOne('/api/users/7/activate');

    expect(request.request.method).toBe('PATCH');

    request.flush({
      message: 'ok',
      data: { id: 7, status: 'active', temporary_password: 'Xy7!temporal-123' },
    });
    fixture.detectChanges();

    expect(
      (fixture.nativeElement as HTMLElement).querySelector('.credential-value')?.textContent
    ).toContain('Xy7!temporal-123');

    expect(text()).toContain('Se muestra una sola vez');
    expect(text()).not.toContain('Activar identidad');

    button('Ya la entregué').click();
    fixture.detectChanges();

    expect(text()).not.toContain('Xy7!temporal-123');
  });

  it('should save roles with the full selected set', () => {
    create(identity({ status: 'active' }));

    const managerOption = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('label.role-option')
    ).find((label) => label.textContent?.includes('Responsable de recurso'))!;

    (managerOption.querySelector('input') as HTMLInputElement).click();
    fixture.detectChanges();

    button('Guardar roles').click();

    const request = httpMock.expectOne('/api/users/7/roles');

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({
      roles: ['employee', 'resource_manager'],
    });

    request.flush({
      message: 'ok',
      data: { id: 7, roles: ['employee', 'resource_manager'], changed: true, removed_approver_resource_ids: [] },
    });

    expectGet('/api/users/7').flush({ data: identity({ status: 'active' }) });
    expectGet('/api/users/7/access-grants').flush(grantsResponse());
  });

  it('should warn when the new roles remove approval duties', () => {
    create(identity({
      status: 'active',
      roles: [{ code: 'resource_manager', name: 'Responsable de recurso' }],
      managed_resources: [{ id: 3, name: 'CRM', code: 'CRM', is_active: true }],
    }));

    expect(text()).not.toContain('Dejará de ser responsable de aprobación');

    const managerOption = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('label.role-option')
    ).find((label) => label.textContent?.includes('Responsable de recurso'))!;

    (managerOption.querySelector('input') as HTMLInputElement).click();
    fixture.detectChanges();

    const employeeOption = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('label.role-option')
    ).find((label) => label.textContent?.includes('Empleado'))!;

    (employeeOption.querySelector('input') as HTMLInputElement).click();
    fixture.detectChanges();

    expect(text()).toContain('Dejará de ser responsable de aprobación');
  });

  it('should move the identity and report revoked grants', () => {
    create(identity({ status: 'active' }));

    const select = (fixture.nativeElement as HTMLElement)
      .querySelector('#mover-department') as HTMLSelectElement;

    const options = Array.from(select.options).map((option) => option.textContent?.trim());

    expect(options).not.toContain('Finanzas (FIN)');

    select.value = '3';
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    button('Trasladar').click();
    fixture.detectChanges();

    confirmDialog();

    const request = httpMock.expectOne('/api/users/7/department');

    expect(request.request.body).toEqual({ department_id: 3 });

    request.flush({
      message: 'ok',
      data: {
        id: 7,
        name: 'Ana Torres',
        previous_department_id: 2,
        department_id: 3,
        revoked_access_grants_count: 1,
      },
    });

    expectGet('/api/users/7').flush({ data: identity({ status: 'active', department_id: 3 }) });
    expectGet('/api/users/7/access-grants').flush(grantsResponse());
  });

  it('should require the confirmation word before deactivating', () => {
    create(identity({ status: 'active' }));

    button('Desactivar identidad').click();
    fixture.detectChanges();

    confirmDialog();
    httpMock.expectNone('/api/users/7/deactivate');

    confirmDialog('DESACTIVAR');

    const request = httpMock.expectOne('/api/users/7/deactivate');

    expect(request.request.method).toBe('PATCH');

    request.flush({
      message: 'ok',
      data: { id: 7, status: 'disabled', revoked_access_grants_count: 2 },
    });

    expectGet('/api/users/7').flush({ data: identity({ status: 'disabled' }) });
    expectGet('/api/users/7/access-grants').flush(grantsResponse());
    fixture.detectChanges();

    expect(text()).not.toContain('Trasladar (Mover)');
  });

  it('should revoke an active grant with a reason', () => {
    create(identity({ status: 'active' }));

    const select = (fixture.nativeElement as HTMLElement)
      .querySelector('#grant-filter') as HTMLSelectElement;

    select.value = 'active';
    select.dispatchEvent(new Event('change'));

    expectGet('/api/users/7/access-grants').flush(grantsResponse([
      {
        id: 41,
        status: 'active',
        resource: { id: 1, name: 'VPN corporativa', code: 'VPN', is_active: true },
        access_request: null,
        granted_at: '2026-10-30T10:00:00Z',
        granted_by: { id: 1, name: 'Omar García' },
        revoked_at: null,
        revoked_by: null,
      },
    ]));
    fixture.detectChanges();

    button('Revocar').click();
    fixture.detectChanges();

    const dialog = (fixture.nativeElement as HTMLElement)
      .querySelector('dialog[open]')!;
    const textarea = dialog.querySelector('textarea') as HTMLTextAreaElement;

    textarea.value = 'Fin del proyecto asignado.';
    textarea.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    confirmDialog();

    const request = httpMock.expectOne('/api/access-grants/41/revoke');

    expect(request.request.body).toEqual({
      revocation_reason: 'Fin del proyecto asignado.',
    });

    request.flush({ message: 'ok', data: { id: 41, status: 'revoked' } });

    expectGet('/api/users/7').flush({ data: identity({ status: 'active' }) });
    expectGet('/api/users/7/access-grants').flush(grantsResponse());
  });

  it('should not offer lifecycle actions on your own identity', () => {
    create(identity({ id: 1, name: 'Omar García', status: 'active' }));

    expect(text()).toContain('Esta es tu propia identidad');

    /*
     * Los diálogos existen en el DOM aunque estén
     * cerrados; por eso se verifican los botones
     * y paneles visibles de la columna lateral.
     */
    const element = fixture.nativeElement as HTMLElement;
    const buttons = Array.from(element.querySelectorAll('aside button'))
      .map((item) => item.textContent?.trim());

    expect(buttons).not.toContain('Desactivar identidad');
    expect(buttons).not.toContain('Guardar roles');
    expect(buttons).not.toContain('Trasladar');
    expect(element.querySelector('.panel--danger')).toBeNull();
  });

  it('should show a not found message', () => {
    fixture = TestBed.createComponent(IdentityDetail);
    fixture.componentRef.setInput('id', 99);
    fixture.detectChanges();

    expectGet('/api/roles').flush({ data: roles });
    expectGet('/api/departments').flush({ data: departments });
    expectGet('/api/users/99').flush(
      { message: 'Not Found' },
      { status: 404, statusText: 'Not Found' }
    );
    fixture.detectChanges();

    expect(text()).toContain('La identidad no existe.');
  });
});
