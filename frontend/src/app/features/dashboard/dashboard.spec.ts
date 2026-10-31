import { TestBed } from '@angular/core/testing';

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

import { Dashboard } from './dashboard';

import { AuditLog } from '../audit/audit-log';

/*
|--------------------------------------------------------------------------
| YESIAM - DASHBOARD Y AUDITORÍA
|--------------------------------------------------------------------------
|
| FASE 21
|
*/

describe('Dashboard y AuditLog', () => {

  let httpMock: HttpTestingController;

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
    httpMock.verify();
  });

  it('should greet and show only the employee sections', () => {
    signInAs(httpMock, {
      name: 'Ana Torres',
      roles: [{ code: 'employee', name: 'Empleado' }],
      permissions: EMPLOYEE_PERMISSIONS,
    });

    const fixture = TestBed.createComponent(Dashboard);
    fixture.detectChanges();

    httpMock.expectOne('/api/dashboard').flush({
      data: {
        my_access: { active_grants: 2 },
        my_requests: { pending: 1, approved: 2, rejected: 0 },
        resources: { active: 4, restricted: 3 },
      },
    });
    fixture.detectChanges();

    const root = fixture.nativeElement as HTMLElement;

    expect(root.querySelector('h1')?.textContent).toContain('Hola, Ana');
    expect(root.textContent).toContain('Empleado en Tecnología');
    expect(root.querySelector('.big-figure strong')?.textContent).toContain('2');
    expect(root.textContent).toContain('Nada pendiente');
    expect(root.textContent).toContain('recursos disponibles');
    expect(root.querySelector('.status-bar')).toBeNull();
    expect(root.querySelector('.activity-list')).toBeNull();
  });

  it('should list pending work and organization status for an admin', () => {
    signInAs(httpMock);

    const fixture = TestBed.createComponent(Dashboard);
    fixture.detectChanges();

    httpMock.expectOne('/api/dashboard').flush({
      data: {
        my_access: { active_grants: 0 },
        identities: { active: 5, suspended: 2, disabled: 1 },
        departments: { active: 4, inactive: 0 },
        resources: { active: 4, restricted: 3 },
        provisioning: { ready_to_grant: 3 },
        access: { active_grants: 9, revoked_grants: 2 },
        recent_activity: [
          {
            id: 1,
            action: 'access.granted',
            entity_type: 'access_request',
            entity_id: 4,
            outcome: 'success',
            actor: { id: 1, name: 'Omar García' },
            created_at: '2026-10-30T10:00:00Z',
          },
        ],
      },
    });
    fixture.detectChanges();

    const root = fixture.nativeElement as HTMLElement;
    const pending = root.querySelector('.pending-list')?.textContent ?? '';

    expect(pending).toContain('Accesos por conceder');
    expect(pending).toContain('Identidades por activar');
    expect(pending).not.toContain('Solicitudes por decidir');

    expect(root.querySelector('.status-bar')?.getAttribute('aria-label'))
      .toBe('5 activas, 2 suspendidas, 1 desactivadas');

    expect(root.querySelector('.activity-list')?.textContent).toContain('Acceso concedido');
  });

  it('should explain a failed dashboard load', () => {
    signInAs(httpMock);

    const fixture = TestBed.createComponent(Dashboard);
    fixture.detectChanges();

    httpMock.expectOne('/api/dashboard').error(new ProgressEvent('error'));
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).textContent)
      .toContain('No fue posible conectar con YESIAM');
  });

  it('should render audit events with readable labels and filter them', () => {
    signInAs(httpMock);

    const fixture = TestBed.createComponent(AuditLog);
    fixture.detectChanges();

    httpMock.expectOne((req) => req.url === '/api/audit-logs').flush({
      data: [
        {
          id: 10,
          actor_user_id: 1,
          action: 'identity.department_changed',
          entity_type: 'user',
          entity_id: 7,
          outcome: 'success',
          metadata: { previous_department_id: 2, new_department_id: 3 },
          ip_address: '127.0.0.1',
          created_at: '2026-10-30T10:00:00Z',
          actor: { id: 1, name: 'Omar García' },
        },
      ],
      current_page: 1,
      last_page: 1,
      per_page: 25,
      total: 1,
      from: 1,
      to: 1,
    });
    fixture.detectChanges();

    const root = fixture.nativeElement as HTMLElement;

    expect(root.textContent).toContain('Cambio de departamento (Mover)');
    expect(root.textContent).toContain('Omar García');
    expect(root.querySelector('a[href="/identities/7"]')).not.toBeNull();
    expect(root.textContent).toContain('new_department_id');

    const select = root.querySelector('#audit-action') as HTMLSelectElement;
    select.value = 'access.revoked';
    select.dispatchEvent(new Event('change'));

    root.querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }));

    const filtered = httpMock.expectOne((req) => req.url === '/api/audit-logs');

    expect(filtered.request.params.get('action')).toBe('access.revoked');

    filtered.flush({
      data: [],
      current_page: 1,
      last_page: 1,
      per_page: 25,
      total: 0,
      from: null,
      to: null,
    });
    fixture.detectChanges();

    expect(root.textContent).toContain('No hay eventos que coincidan');
  });
});
