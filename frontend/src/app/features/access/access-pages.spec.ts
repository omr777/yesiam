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

import { Approvals } from './approvals';
import { MyAccess } from './my-access';
import { Provisioning } from './provisioning';
import { RequestAccess } from './request-access';

import type { AccessRequest, Paginated } from './access.service';

/*
|--------------------------------------------------------------------------
| YESIAM - PANTALLAS DEL FLUJO DE ACCESO
|--------------------------------------------------------------------------
|
| FASE 21
|
| Solicitar -> Aprobar -> Conceder -> Mis accesos
|
*/

function page<T>(data: T[]): Paginated<T> {
  return {
    data,
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: data.length,
    from: data.length > 0 ? 1 : null,
    to: data.length > 0 ? data.length : null,
  };
}

const pendingRequest: AccessRequest = {
  id: 21,
  requester_user_id: 7,
  resource_id: 2,
  justification: 'Cierre mensual de cuentas por pagar.',
  status: 'pending',
  decided_by_user_id: null,
  decision_reason: null,
  decided_at: null,
  created_at: '2026-10-30T10:00:00Z',
  resource: { id: 2, name: 'ERP Finanzas', code: 'ERP-FIN' },
  requester: { id: 7, name: 'Ana Torres', email: 'ana@example.test' },
};

function submitDialog(root: HTMLElement, reason?: string): void {
  const dialog = root.querySelector('dialog[open]')!;

  if (reason !== undefined) {
    const textarea = dialog.querySelector('textarea') as HTMLTextAreaElement;
    textarea.value = reason;
    textarea.dispatchEvent(new Event('input'));
  }

  dialog.querySelector('form')!.dispatchEvent(
    new Event('submit', { cancelable: true })
  );
}

function clickButton(root: HTMLElement, label: string): void {
  const found = Array.from(root.querySelectorAll('button'))
    .find((item) => item.textContent?.trim() === label);

  if (!found) {
    throw new Error(`No se encontró el botón "${label}".`);
  }

  (found as HTMLButtonElement).click();
}

describe('Pantallas del flujo de acceso', () => {

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

  /*
  |--------------------------------------------------------------------------
  | SOLICITAR ACCESO
  |--------------------------------------------------------------------------
  */

  describe('RequestAccess', () => {

    function setup(preselected?: string) {
      signInAs(httpMock, { permissions: EMPLOYEE_PERMISSIONS });

      const fixture = TestBed.createComponent(RequestAccess);

      if (preselected) {
        fixture.componentRef.setInput('resource', preselected);
      }

      fixture.detectChanges();

      httpMock.expectOne((req) => req.url === '/api/resources').flush({
        data: [
          { id: 1, name: 'VPN corporativa', code: 'VPN', description: null, is_active: true, department_restricted: false },
          { id: 2, name: 'ERP Finanzas', code: 'ERP-FIN', description: 'Contabilidad', is_active: true, department_restricted: true },
        ],
      });

      httpMock.expectOne((req) => req.url === '/api/access-requests').flush(page([]));

      fixture.detectChanges();

      return fixture;
    }

    it('should validate before sending', () => {
      const fixture = setup();
      const root = fixture.nativeElement as HTMLElement;

      root.querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }));
      fixture.detectChanges();

      httpMock.expectNone((req) => req.method === 'POST');

      expect(root.textContent).toContain('Selecciona el recurso que necesitas.');
      expect(root.textContent).toContain('Escribe al menos 10 caracteres.');
    });

    it('should preselect the resource from the catalog link and submit', () => {
      const fixture = setup('2');
      const root = fixture.nativeElement as HTMLElement;

      expect((root.querySelector('#request-resource') as HTMLSelectElement).value).toBe('2');

      const textarea = root.querySelector('#request-justification') as HTMLTextAreaElement;
      textarea.value = '  Cierre mensual de cuentas.  ';
      textarea.dispatchEvent(new Event('input'));

      root.querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }));

      const request = httpMock.expectOne((req) =>
        req.method === 'POST' && req.url === '/api/access-requests'
      );

      expect(request.request.body).toEqual({
        resource_id: 2,
        justification: 'Cierre mensual de cuentas.',
      });

      request.flush(
        { message: 'ok', data: { ...pendingRequest } },
        { status: 201, statusText: 'Created' }
      );

      httpMock.expectOne((req) => req.method === 'GET' && req.url === '/api/access-requests')
        .flush(page([pendingRequest]));
      fixture.detectChanges();

      expect(root.textContent).toContain('ERP Finanzas');
      expect(root.textContent).toContain('Pendiente');
    });

    it('should show the eligibility rule returned by Laravel', () => {
      const fixture = setup('2');
      const root = fixture.nativeElement as HTMLElement;

      const textarea = root.querySelector('#request-justification') as HTMLTextAreaElement;
      textarea.value = 'Necesito revisar el cierre.';
      textarea.dispatchEvent(new Event('input'));

      root.querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }));

      httpMock.expectOne((req) => req.method === 'POST').flush(
        {
          message: 'No cumples las condiciones de elegibilidad para solicitar este recurso.',
          errors: {
            resource_id: ['No cumples las condiciones de elegibilidad para solicitar este recurso.'],
          },
        },
        { status: 422, statusText: 'Unprocessable Content' }
      );
      fixture.detectChanges();

      expect(root.querySelector('.field-error')?.textContent)
        .toContain('No cumples las condiciones de elegibilidad');
    });
  });

  /*
  |--------------------------------------------------------------------------
  | APROBACIONES
  |--------------------------------------------------------------------------
  */

  describe('Approvals', () => {

    function setup(requests: AccessRequest[] = [pendingRequest]) {
      signInAs(httpMock, {
        permissions: ['access.approve', 'access.request', 'resources.view'],
      });

      const fixture = TestBed.createComponent(Approvals);
      fixture.detectChanges();

      httpMock.expectOne((req) => req.url === '/api/access-requests/assigned')
        .flush(page(requests));
      fixture.detectChanges();

      return fixture;
    }

    it('should list pending requests with justification', () => {
      const fixture = setup();
      const root = fixture.nativeElement as HTMLElement;

      expect(root.querySelectorAll('.request-card').length).toBe(1);
      expect(root.textContent).toContain('Ana Torres');
      expect(root.textContent).toContain('ERP Finanzas');
      expect(root.textContent).toContain('Cierre mensual de cuentas por pagar.');
    });

    it('should approve with a reason', () => {
      const fixture = setup();
      const root = fixture.nativeElement as HTMLElement;

      clickButton(root, 'Aprobar');
      fixture.detectChanges();

      submitDialog(root, 'Necesidad justificada por su función.');
      fixture.detectChanges();

      const request = httpMock.expectOne('/api/access-requests/21/approve');

      expect(request.request.body).toEqual({
        decision_reason: 'Necesidad justificada por su función.',
      });

      request.flush({ message: 'ok', data: { ...pendingRequest, status: 'approved' } });

      httpMock.expectOne((req) => req.url === '/api/access-requests/assigned').flush(page([]));
      fixture.detectChanges();

      expect(root.textContent).toContain('No tienes solicitudes pendientes');
    });

    it('should reject with a reason and keep the dialog open on 403', () => {
      const fixture = setup();
      const root = fixture.nativeElement as HTMLElement;

      clickButton(root, 'Rechazar');
      fixture.detectChanges();

      expect(root.querySelector('dialog[open]')?.textContent).toContain('Rechazar solicitud');

      submitDialog(root, 'No corresponde a sus funciones.');

      httpMock.expectOne('/api/access-requests/21/reject').flush(
        { message: 'This action is unauthorized.' },
        { status: 403, statusText: 'Forbidden' }
      );
      fixture.detectChanges();

      expect(root.querySelector('dialog[open]')?.textContent)
        .toContain('Ya no puedes decidir esta solicitud');
    });
  });

  /*
  |--------------------------------------------------------------------------
  | CONCESIONES
  |--------------------------------------------------------------------------
  */

  describe('Provisioning', () => {

    const approved: AccessRequest = {
      ...pendingRequest,
      status: 'approved',
      decision_reason: 'Necesidad justificada.',
      decided_at: '2026-10-30T11:00:00Z',
      decided_by: { id: 3, name: 'Lucía Herrera' },
    };

    function setup() {
      signInAs(httpMock);

      const fixture = TestBed.createComponent(Provisioning);
      fixture.detectChanges();

      httpMock.expectOne((req) => req.url === '/api/access-requests/ready-to-grant')
        .flush(page([approved]));
      fixture.detectChanges();

      return fixture;
    }

    it('should show who approved and why', () => {
      const root = setup().nativeElement as HTMLElement;

      expect(root.textContent).toContain('Lucía Herrera');
      expect(root.textContent).toContain('Necesidad justificada.');
      expect(root.querySelector('a[href="/identities/7"]')).not.toBeNull();
    });

    it('should grant after confirmation', () => {
      const fixture = setup();
      const root = fixture.nativeElement as HTMLElement;

      clickButton(root, 'Conceder');
      fixture.detectChanges();

      submitDialog(root);

      const request = httpMock.expectOne('/api/access-requests/21/grant');

      expect(request.request.method).toBe('POST');

      request.flush(
        { message: 'ok', data: { id: 50, status: 'active' } },
        { status: 201, statusText: 'Created' }
      );

      httpMock.expectOne((req) => req.url === '/api/access-requests/ready-to-grant')
        .flush(page([]));
      fixture.detectChanges();

      expect(root.textContent).toContain('No hay solicitudes por conceder');
    });

    it('should explain a lost eligibility (422)', () => {
      const fixture = setup();
      const root = fixture.nativeElement as HTMLElement;

      clickButton(root, 'Conceder');
      fixture.detectChanges();
      submitDialog(root);

      httpMock.expectOne('/api/access-requests/21/grant').flush(
        { message: 'invalid', errors: { access_request_id: ['x'] } },
        { status: 422, statusText: 'Unprocessable Content' }
      );
      fixture.detectChanges();

      expect(root.querySelector('dialog[open]')?.textContent)
        .toContain('ya no cumple las condiciones de elegibilidad');
    });
  });

  /*
  |--------------------------------------------------------------------------
  | MIS ACCESOS
  |--------------------------------------------------------------------------
  */

  describe('MyAccess', () => {

    it('should load the signed-in identity inventory and filter it', () => {
      signInAs(httpMock, { id: 7, permissions: EMPLOYEE_PERMISSIONS });

      const fixture = TestBed.createComponent(MyAccess);
      fixture.detectChanges();

      const first = httpMock.expectOne((req) => req.url === '/api/users/7/access-grants');

      expect(first.request.params.get('status')).toBe('active');

      first.flush({
        message: 'ok',
        user: { id: 7, name: 'Ana' },
        data: [{
          id: 1,
          status: 'active',
          resource: { id: 1, name: 'VPN corporativa', code: 'VPN', is_active: true },
          access_request: null,
          granted_at: '2026-10-30T10:00:00Z',
          granted_by: { id: 1, name: 'Omar García' },
          revoked_at: null,
          revoked_by: null,
        }],
        pagination: { current_page: 1, per_page: 20, total: 1, last_page: 1 },
      });
      fixture.detectChanges();

      const root = fixture.nativeElement as HTMLElement;

      expect(root.textContent).toContain('VPN corporativa');
      expect(root.textContent).not.toContain('Revocar');

      clickButton(root, 'Revocados');

      const second = httpMock.expectOne((req) => req.url === '/api/users/7/access-grants');

      expect(second.request.params.get('status')).toBe('revoked');

      second.flush({
        message: 'ok',
        user: { id: 7, name: 'Ana' },
        data: [],
        pagination: { current_page: 1, per_page: 20, total: 0, last_page: 1 },
      });
      fixture.detectChanges();

      expect(root.textContent).toContain('No hay accesos en esta vista');
    });
  });
});
