import { TestBed } from '@angular/core/testing';

import { provideHttpClient } from '@angular/common/http';

import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';

import {
  Resource,
  ResourceService,
} from './resource.service';

/*
|--------------------------------------------------------------------------
| YESIAM - RESOURCE SERVICE TESTS
|--------------------------------------------------------------------------
|
| FASE 21
|
*/

describe('ResourceService', () => {

  let service: ResourceService;
  let httpMock: HttpTestingController;

  const vpn: Resource = {
    id: 1,
    name: 'VPN corporativa',
    code: 'VPN',
    description: null,
    is_active: true,
    department_restricted: false,
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    service = TestBed.inject(ResourceService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should list active resources by default', () => {
    let result: Resource[] | undefined;

    service.list().subscribe((resources) => {
      result = resources;
    });

    const request = httpMock.expectOne((req) => req.url === '/api/resources');

    expect(request.request.params.has('include_inactive')).toBe(false);
    expect(request.request.withCredentials).toBe(true);

    request.flush({ data: [vpn] });

    expect(result).toEqual([vpn]);
  });

  it('should ask for inactive resources when requested', () => {
    service.list(true).subscribe();

    const request = httpMock.expectOne((req) => req.url === '/api/resources');

    expect(request.request.params.get('include_inactive')).toBe('1');

    request.flush({ data: [] });
  });

  it('should send only editable fields when creating', () => {
    service.create({
      name: 'ERP',
      code: 'ERP',
      description: null,
    }).subscribe();

    const request = httpMock.expectOne('/api/resources');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      name: 'ERP',
      code: 'ERP',
      description: null,
    });

    request.flush({ message: 'ok', data: vpn }, { status: 201, statusText: 'Created' });
  });

  it('should clear department ids when eligibility is not restricted', () => {
    service.updateEligibility(1, false, [2, 3]).subscribe();

    const request = httpMock.expectOne('/api/resources/1/eligibility');

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({
      department_restricted: false,
      department_ids: [],
    });

    request.flush({ message: 'ok', data: {} });
  });

  it('should send selected departments for restricted resources', () => {
    service.updateEligibility(1, true, [2, 3]).subscribe();

    const request = httpMock.expectOne('/api/resources/1/eligibility');

    expect(request.request.body).toEqual({
      department_restricted: true,
      department_ids: [2, 3],
    });

    request.flush({ message: 'ok', data: {} });
  });

  it('should list, add and remove approvers', () => {
    service.approvers(1).subscribe();
    service.addApprover(1, 8).subscribe();
    service.removeApprover(1, 8).subscribe();

    const list = httpMock.expectOne((req) =>
      req.method === 'GET' && req.url === '/api/resources/1/approvers'
    );
    const add = httpMock.expectOne((req) =>
      req.method === 'POST' && req.url === '/api/resources/1/approvers'
    );
    const remove = httpMock.expectOne((req) =>
      req.method === 'DELETE' && req.url === '/api/resources/1/approvers/8'
    );

    expect(add.request.body).toEqual({ user_id: 8 });

    list.flush({ data: [] });
    add.flush({ message: 'ok' }, { status: 201, statusText: 'Created' });
    remove.flush({ message: 'ok' });
  });

  it('should deactivate with a PATCH request', () => {
    service.deactivate(1).subscribe();

    const request = httpMock.expectOne('/api/resources/1/deactivate');

    expect(request.request.method).toBe('PATCH');

    request.flush({
      message: 'ok',
      data: { ...vpn, is_active: false },
      revoked_access_grants_count: 2,
    });
  });
});
