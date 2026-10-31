import { TestBed } from '@angular/core/testing';

import { provideHttpClient } from '@angular/common/http';

import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';

import { DepartmentService } from './department.service';

/*
|--------------------------------------------------------------------------
| YESIAM - DEPARTMENT SERVICE: ESCRITURA
|--------------------------------------------------------------------------
|
| FASE 21
|
*/

describe('DepartmentService - escritura', () => {

  let service: DepartmentService;
  let httpMock: HttpTestingController;

  const department = {
    id: 4,
    name: 'Seguridad',
    code: 'SEC',
    is_active: true,
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    service = TestBed.inject(DepartmentService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should create with only name and code', () => {
    service.create({ name: 'Seguridad', code: 'SEC' }).subscribe();

    const request = httpMock.expectOne('/api/departments');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ name: 'Seguridad', code: 'SEC' });
    expect(request.request.withCredentials).toBe(true);

    request.flush({ message: 'ok', data: department }, { status: 201, statusText: 'Created' });
  });

  it('should update with a PATCH request', () => {
    service.update(4, { name: 'Ciberseguridad', code: 'SEC' }).subscribe();

    const request = httpMock.expectOne('/api/departments/4');

    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({ name: 'Ciberseguridad', code: 'SEC' });

    request.flush({ message: 'ok', data: department });
  });

  it('should return the revoked grants count on deactivation', () => {
    let revoked: number | undefined;

    service.deactivate(4).subscribe((response) => {
      revoked = response.revoked_access_grants_count;
    });

    const request = httpMock.expectOne('/api/departments/4/deactivate');

    expect(request.request.method).toBe('PATCH');

    request.flush({
      message: 'ok',
      data: { ...department, is_active: false },
      revoked_access_grants_count: 3,
    });

    expect(revoked).toBe(3);
  });

  it('should propagate conflicts', () => {
    let status: number | undefined;

    service.deactivate(4).subscribe({
      error: (error) => {
        status = error.status;
      },
    });

    httpMock.expectOne('/api/departments/4/deactivate').flush(
      { message: 'El departamento ya está inactivo.' },
      { status: 409, statusText: 'Conflict' }
    );

    expect(status).toBe(409);
  });
});
