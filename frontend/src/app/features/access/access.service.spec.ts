import { TestBed } from '@angular/core/testing';

import { provideHttpClient } from '@angular/common/http';

import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';

import { AccessService } from './access.service';

/*
|--------------------------------------------------------------------------
| YESIAM - ACCESS SERVICE TESTS
|--------------------------------------------------------------------------
|
| FASE 21
|
| Verificamos el contrato HTTP del flujo de
| acceso y que Angular nunca envíe campos de
| control (user_id, status, granted_by).
|
*/

describe('AccessService', () => {

  let service: AccessService;
  let httpMock: HttpTestingController;

  const emptyPage = {
    data: [],
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: 0,
    from: null,
    to: null,
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    service = TestBed.inject(AccessService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should request access with only resource and justification', () => {
    service.requestAccess(4, 'Necesito revisar el cierre.').subscribe();

    const request = httpMock.expectOne('/api/access-requests');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      resource_id: 4,
      justification: 'Necesito revisar el cierre.',
    });
    expect(request.request.withCredentials).toBe(true);
    expect(request.request.headers.has('Authorization')).toBe(false);

    request.flush({ message: 'ok', data: {} }, { status: 201, statusText: 'Created' });
  });

  it('should page my requests, assigned and ready-to-grant queues', () => {
    service.myRequests(2).subscribe();
    service.assigned(3).subscribe();
    service.readyToGrant(1).subscribe();

    const mine = httpMock.expectOne((req) => req.url === '/api/access-requests');
    const assigned = httpMock.expectOne((req) => req.url === '/api/access-requests/assigned');
    const ready = httpMock.expectOne((req) => req.url === '/api/access-requests/ready-to-grant');

    expect(mine.request.params.get('page')).toBe('2');
    expect(assigned.request.params.get('page')).toBe('3');
    expect(ready.request.params.get('page')).toBe('1');

    mine.flush(emptyPage);
    assigned.flush(emptyPage);
    ready.flush(emptyPage);
  });

  it('should send the decision reason when approving or rejecting', () => {
    service.approve(9, 'Justificación válida.').subscribe();
    service.reject(10, 'No corresponde a su puesto.').subscribe();

    const approve = httpMock.expectOne('/api/access-requests/9/approve');
    const reject = httpMock.expectOne('/api/access-requests/10/reject');

    expect(approve.request.method).toBe('POST');
    expect(approve.request.body).toEqual({ decision_reason: 'Justificación válida.' });
    expect(reject.request.body).toEqual({ decision_reason: 'No corresponde a su puesto.' });

    approve.flush({ message: 'ok', data: {} });
    reject.flush({ message: 'ok', data: {} });
  });

  it('should grant with an empty body', () => {
    service.grant(12).subscribe();

    const request = httpMock.expectOne('/api/access-requests/12/grant');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({});

    request.flush({ message: 'ok', data: {} }, { status: 201, statusText: 'Created' });
  });

  it('should revoke with a reason', () => {
    service.revoke(5, 'Fin del proyecto asignado.').subscribe();

    const request = httpMock.expectOne('/api/access-grants/5/revoke');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      revocation_reason: 'Fin del proyecto asignado.',
    });

    request.flush({ message: 'ok', data: {} });
  });

  it('should propagate forbidden errors', () => {
    let status: number | undefined;

    service.grant(3).subscribe({
      error: (error) => {
        status = error.status;
      },
    });

    httpMock.expectOne('/api/access-requests/3/grant').flush(
      { message: 'This action is unauthorized.' },
      { status: 403, statusText: 'Forbidden' }
    );

    expect(status).toBe(403);
  });
});
