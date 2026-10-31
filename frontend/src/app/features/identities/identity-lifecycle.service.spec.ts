import { TestBed } from '@angular/core/testing';

import { provideHttpClient } from '@angular/common/http';

import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';

import { IdentityService } from './identity.service';

/*
|--------------------------------------------------------------------------
| YESIAM - IDENTITY SERVICE: CICLO DE VIDA
|--------------------------------------------------------------------------
|
| FASE 21
|
| Detalle, activación, roles, Mover, Leaver,
| inventario y filtro por permiso.
|
*/

describe('IdentityService - ciclo de vida', () => {

  let service: IdentityService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    service = TestBed.inject(IdentityService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should unwrap the identity detail', () => {
    let name: string | undefined;

    service.get(7).subscribe((identity) => {
      name = identity.name;
    });

    httpMock.expectOne('/api/users/7').flush({
      data: {
        id: 7,
        name: 'Ana Torres',
        email: 'ana@example.test',
        status: 'suspended',
        department_id: 2,
        department: null,
        roles: [],
        managed_resources: [],
        active_access_grants_count: 0,
        created_at: null,
      },
    });

    expect(name).toBe('Ana Torres');
  });

  it('should activate with an empty body', () => {
    let password: string | undefined;

    service.activate(7).subscribe((response) => {
      password = response.data.temporary_password;
    });

    const request = httpMock.expectOne('/api/users/7/activate');

    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({});

    request.flush({
      message: 'ok',
      data: { id: 7, status: 'active', temporary_password: 'Temporal-1234567' },
    });

    expect(password).toBe('Temporal-1234567');
  });

  it('should move an identity with only the department id', () => {
    service.changeDepartment(7, 3).subscribe();

    const request = httpMock.expectOne('/api/users/7/department');

    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({ department_id: 3 });

    request.flush({ message: 'ok', data: {} });
  });

  it('should deactivate an identity', () => {
    service.deactivate(7).subscribe();

    const request = httpMock.expectOne('/api/users/7/deactivate');

    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({});

    request.flush({ message: 'ok', data: {} });
  });

  it('should replace roles with a PUT request', () => {
    service.updateRoles(7, ['employee', 'resource_manager']).subscribe();

    const request = httpMock.expectOne('/api/users/7/roles');

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({
      roles: ['employee', 'resource_manager'],
    });

    request.flush({ message: 'ok', data: {} });
  });

  it('should list the role catalog', () => {
    let codes: string[] = [];

    service.listRoles().subscribe((roles) => {
      codes = roles.map((role) => role.code);
    });

    httpMock.expectOne('/api/roles').flush({
      data: [
        { code: 'employee', name: 'Empleado', permissions: [] },
      ],
    });

    expect(codes).toEqual(['employee']);
  });

  it('should page the access inventory with an optional status', () => {
    service.accessGrants(7, { status: 'active', page: 2 }).subscribe();
    service.accessGrants(7).subscribe();

    const requests = httpMock.match((req) => req.url === '/api/users/7/access-grants');

    expect(requests.length).toBe(2);
    expect(requests[0].request.params.get('status')).toBe('active');
    expect(requests[0].request.params.get('page')).toBe('2');
    expect(requests[1].request.params.has('status')).toBe(false);

    const empty = {
      message: 'ok',
      user: { id: 7, name: 'Ana' },
      data: [],
      pagination: { current_page: 1, per_page: 20, total: 0, last_page: 1 },
    };

    requests.forEach((request) => request.flush(empty));
  });

  it('should filter the directory by permission', () => {
    service.list({ permission: 'access.approve', status: 'active' }).subscribe();

    const request = httpMock.expectOne((req) => req.url === '/api/users');

    expect(request.request.params.get('permission')).toBe('access.approve');
    expect(request.request.params.get('status')).toBe('active');

    request.flush({
      data: [],
      current_page: 1,
      last_page: 1,
      per_page: 15,
      total: 0,
      from: null,
      to: null,
      next_page_url: null,
      prev_page_url: null,
    });
  });
});
