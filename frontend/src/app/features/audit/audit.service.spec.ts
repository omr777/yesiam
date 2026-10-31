import { TestBed } from '@angular/core/testing';

import { provideHttpClient } from '@angular/common/http';

import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';

import { AuditService } from './audit.service';

import { DashboardService } from '../dashboard/dashboard.service';

/*
|--------------------------------------------------------------------------
| YESIAM - AUDITORÍA Y DASHBOARD (SERVICIOS)
|--------------------------------------------------------------------------
|
| FASE 21
|
*/

describe('AuditService y DashboardService', () => {

  let audit: AuditService;
  let dashboard: DashboardService;
  let httpMock: HttpTestingController;

  const emptyPage = {
    data: [],
    current_page: 1,
    last_page: 1,
    per_page: 25,
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

    audit = TestBed.inject(AuditService);
    dashboard = TestBed.inject(DashboardService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should send audit filters as query parameters', () => {
    audit.list({
      action: 'access.revoked',
      entityType: 'access_request',
      entityId: 12,
      page: 2,
    }).subscribe();

    const request = httpMock.expectOne((req) => req.url === '/api/audit-logs');

    expect(request.request.method).toBe('GET');
    expect(request.request.params.get('action')).toBe('access.revoked');
    expect(request.request.params.get('entity_type')).toBe('access_request');
    expect(request.request.params.get('entity_id')).toBe('12');
    expect(request.request.params.get('page')).toBe('2');
    expect(request.request.params.get('per_page')).toBe('25');

    request.flush(emptyPage);
  });

  it('should omit empty audit filters', () => {
    audit.list({ action: '', entityType: '', entityId: null }).subscribe();

    const request = httpMock.expectOne((req) => req.url === '/api/audit-logs');

    expect(request.request.params.has('action')).toBe(false);
    expect(request.request.params.has('entity_type')).toBe(false);
    expect(request.request.params.has('entity_id')).toBe(false);

    request.flush(emptyPage);
  });

  it('should unwrap the dashboard summary', () => {
    let activeGrants: number | undefined;

    dashboard.summary().subscribe((summary) => {
      activeGrants = summary.my_access.active_grants;
    });

    const request = httpMock.expectOne('/api/dashboard');

    expect(request.request.withCredentials).toBe(true);

    request.flush({
      data: {
        my_access: { active_grants: 3 },
      },
    });

    expect(activeGrants).toBe(3);
  });
});
