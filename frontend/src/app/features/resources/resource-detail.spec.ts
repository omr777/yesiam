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

import {
  EMPLOYEE_PERMISSIONS,
  signInAs,
} from '../../testing/session';

import { ResourceDetail } from './resource-detail';

/*
|--------------------------------------------------------------------------
| YESIAM - DETALLE DE RECURSO
|--------------------------------------------------------------------------
|
| FASE 21
|
*/

describe('ResourceDetail', () => {

  let fixture: ComponentFixture<ResourceDetail>;
  let httpMock: HttpTestingController;

  const crm = {
    id: 3,
    name: 'CRM Comercial',
    code: 'CRM',
    description: 'Clientes y oportunidades.',
    is_active: true,
    department_restricted: true,
    created_at: '2026-10-30T10:00:00Z',
    updated_at: '2026-10-30T10:00:00Z',
  };

  const eligibility = {
    resource_id: 3,
    resource_name: 'CRM Comercial',
    department_restricted: true,
    eligible_departments: [
      { id: 3, name: 'Ventas', code: 'VTA', is_active: true },
    ],
  };

  function root(): HTMLElement {
    return fixture.nativeElement as HTMLElement;
  }

  function setupAdmin(): void {
    signInAs(httpMock);

    fixture = TestBed.createComponent(ResourceDetail);
    fixture.componentRef.setInput('id', 3);
    fixture.detectChanges();

    httpMock.expectOne('/api/resources/3').flush({ data: crm });
    httpMock.expectOne('/api/resources/3/eligibility').flush({ message: 'ok', data: eligibility });
    httpMock.expectOne('/api/resources/3/approvers').flush({
      data: [
        { id: 4, name: 'Lucía Herrera', email: 'lucia@example.test', status: 'active', assigned_at: null },
      ],
    });
    httpMock.expectOne('/api/departments').flush({
      data: [
        { id: 2, name: 'Finanzas', code: 'FIN', is_active: true },
        { id: 3, name: 'Ventas', code: 'VTA', is_active: true },
      ],
    });

    const approverPool = httpMock.expectOne((req) => req.url === '/api/users');

    expect(approverPool.request.params.get('permission')).toBe('access.approve');

    approverPool.flush({
      data: [
        { id: 4, name: 'Lucía Herrera', email: 'lucia@example.test', status: 'active', department_id: 1 },
        { id: 8, name: 'Diego Paz', email: 'diego@example.test', status: 'active', department_id: 1 },
      ],
      current_page: 1,
      last_page: 1,
      per_page: 100,
      total: 2,
      from: 1,
      to: 2,
      next_page_url: null,
      prev_page_url: null,
    });

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

  it('should show read-only eligibility and a request link for employees', () => {
    signInAs(httpMock, { permissions: EMPLOYEE_PERMISSIONS });

    fixture = TestBed.createComponent(ResourceDetail);
    fixture.componentRef.setInput('id', 3);
    fixture.detectChanges();

    httpMock.expectOne('/api/resources/3').flush({ data: crm });
    httpMock.expectOne('/api/resources/3/eligibility').flush({ message: 'ok', data: eligibility });
    fixture.detectChanges();

    expect(root().textContent).toContain('Ventas');
    expect(root().querySelector('input[type=radio]')).toBeNull();
    expect(root().textContent).not.toContain('Responsables de aprobación');
    expect(root().querySelector('a[href="/requests?resource=3"]')).not.toBeNull();
  });

  it('should offer only candidates that are not already approvers', () => {
    setupAdmin();

    const options = Array.from(
      (root().querySelector('#approver-select') as HTMLSelectElement).options
    ).map((option) => option.textContent ?? '');

    expect(options.some((option) => option.includes('Diego Paz'))).toBe(true);
    expect(options.some((option) => option.includes('Lucía Herrera'))).toBe(false);
  });

  it('should add Finanzas to the eligible departments', () => {
    setupAdmin();

    const finanzas = Array.from(root().querySelectorAll('label.department-option'))
      .find((label) => label.textContent?.includes('Finanzas'))!;

    (finanzas.querySelector('input') as HTMLInputElement).click();
    fixture.detectChanges();

    const save = Array.from(root().querySelectorAll('button'))
      .find((button) => button.textContent?.trim() === 'Guardar elegibilidad') as HTMLButtonElement;

    expect(save.disabled).toBe(false);

    save.click();

    const request = httpMock.expectOne((req) =>
      req.method === 'PUT' && req.url === '/api/resources/3/eligibility'
    );

    expect(request.request.body).toEqual({
      department_restricted: true,
      department_ids: [2, 3],
    });

    request.flush({ message: 'ok', data: { changed: true } });

    httpMock.expectOne('/api/resources/3/eligibility').flush({ message: 'ok', data: eligibility });
  });

  it('should warn when a restricted resource has no departments', () => {
    setupAdmin();

    const ventas = Array.from(root().querySelectorAll('label.department-option'))
      .find((label) => label.textContent?.includes('Ventas'))!;

    (ventas.querySelector('input') as HTMLInputElement).click();
    fixture.detectChanges();

    expect(root().textContent).toContain('Nadie podrá solicitar este recurso');
  });

  it('should show Laravel conflicts when eligibility contradicts active grants', () => {
    setupAdmin();

    const radios = root().querySelectorAll('input[type=radio]');

    (radios[0] as HTMLInputElement).click();
    fixture.detectChanges();

    const save = Array.from(root().querySelectorAll('button'))
      .find((button) => button.textContent?.trim() === 'Guardar elegibilidad') as HTMLButtonElement;

    save.click();

    httpMock.expectOne((req) => req.method === 'PUT').flush(
      { message: 'Existen concesiones activas incompatibles con la nueva restricción.' },
      { status: 409, statusText: 'Conflict' }
    );
    fixture.detectChanges();

    expect(root().textContent).toContain('Existen concesiones activas incompatibles');
  });

  it('should assign a new approver', () => {
    setupAdmin();

    const select = root().querySelector('#approver-select') as HTMLSelectElement;
    select.value = '8';
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    const assign = Array.from(root().querySelectorAll('button'))
      .find((button) => button.textContent?.trim() === 'Asignar') as HTMLButtonElement;

    assign.click();

    const request = httpMock.expectOne((req) =>
      req.method === 'POST' && req.url === '/api/resources/3/approvers'
    );

    expect(request.request.body).toEqual({ user_id: 8 });

    request.flush({ message: 'ok' }, { status: 201, statusText: 'Created' });

    httpMock.expectOne('/api/resources/3/approvers').flush({ data: [] });
  });
});
