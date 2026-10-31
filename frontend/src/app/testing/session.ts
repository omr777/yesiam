import { TestBed } from '@angular/core/testing';

import { HttpTestingController } from '@angular/common/http/testing';

import {
  AuthService,
  AuthenticatedUser,
} from '../core/auth/auth.service';

/*
|--------------------------------------------------------------------------
| YESIAM - SESIÓN SIMULADA PARA PRUEBAS
|--------------------------------------------------------------------------
|
| FASE 21
|
| Carga una sesión en AuthService respondiendo
| a GET /api/user, igual que en la aplicación.
|
| Este archivo solo se usa en pruebas; está
| excluido del build (tsconfig.app.json).
|
*/

export const ADMIN_PERMISSIONS = [
  'access.grant',
  'access.request',
  'access.revoke',
  'access.view_all',
  'audit.view',
  'departments.create',
  'departments.deactivate',
  'departments.update',
  'departments.view',
  'profile.view',
  'resources.manage',
  'resources.view',
  'users.manage',
  'users.view',
];

export const EMPLOYEE_PERMISSIONS = [
  'access.request',
  'profile.view',
  'resources.view',
];

export function signInAs(
  httpMock: HttpTestingController,
  overrides: Partial<AuthenticatedUser> = {}
): AuthenticatedUser {
  const user: AuthenticatedUser = {
    id: 1,
    name: 'Omar García',
    email: 'admin@example.test',
    status: 'active',
    department_id: 1,
    department: {
      id: 1,
      name: 'Tecnología',
      code: 'TI',
      is_active: true,
    },
    roles: [
      { code: 'iam_admin', name: 'Administrador IAM' },
    ],
    permissions: ADMIN_PERMISSIONS,
    ...overrides,
  };

  TestBed.inject(AuthService).checkSession().subscribe();

  httpMock.expectOne('/api/user').flush(user);

  return user;
}
