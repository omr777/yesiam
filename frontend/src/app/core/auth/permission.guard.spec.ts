import { TestBed } from '@angular/core/testing';

import {
  provideHttpClient,
} from '@angular/common/http';

import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';

import {
  ActivatedRouteSnapshot,
  Router,
  RouterStateSnapshot,
  UrlTree,
  provideRouter,
} from '@angular/router';

import { AuthService } from './auth.service';
import { permissionGuard } from './permission.guard';

/*
|--------------------------------------------------------------------------
| YESIAM - GUARD DE PERMISOS
|--------------------------------------------------------------------------
|
| FASE 21
|
| La sesión se carga con GET /api/user,
| que incluye los permisos efectivos.
|
*/

describe('permissionGuard', () => {

  let auth: AuthService;
  let httpMock: HttpTestingController;

  function signInWith(permissions: string[]): void {
    auth.checkSession().subscribe();

    httpMock.expectOne('/api/user').flush({
      id: 7,
      name: 'Ana Torres',
      email: 'ana@example.test',
      status: 'active',
      department_id: 1,
      department: null,
      roles: [],
      permissions,
    });
  }

  function run(...required: Parameters<typeof permissionGuard>) {
    return TestBed.runInInjectionContext(() =>
      permissionGuard(...required)(
        {} as ActivatedRouteSnapshot,
        {} as RouterStateSnapshot
      )
    );
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    });

    auth = TestBed.inject(AuthService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
  });

  it('should allow a session with the required permission', () => {
    signInWith(['users.manage']);

    expect(run('users.manage')).toBe(true);
  });

  it('should allow when any of several permissions is present', () => {
    signInWith(['access.approve']);

    expect(run('access.grant', 'access.approve')).toBe(true);
  });

  it('should redirect to access-denied without the permission', () => {
    signInWith(['access.request']);

    const result = run('audit.view');

    expect(result).toBeInstanceOf(UrlTree);

    expect(
      TestBed.inject(Router).serializeUrl(result as UrlTree)
    ).toBe('/access-denied');
  });

  it('should deny when there is no session', () => {
    const result = run('users.manage');

    expect(result).toBeInstanceOf(UrlTree);
  });

  it('should expose can and canAny helpers', () => {
    signInWith(['resources.view', 'access.request']);

    expect(auth.can('resources.view')).toBe(true);
    expect(auth.can('resources.manage')).toBe(false);
    expect(auth.canAny(['audit.view', 'access.request'])).toBe(true);
    expect(auth.canAny(['audit.view'])).toBe(false);
  });
});
