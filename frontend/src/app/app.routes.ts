import { Routes } from '@angular/router';

import { MainLayout } from './layout/main-layout';

import { authGuard } from './core/auth/auth.guard';
import { permissionGuard } from './core/auth/permission.guard';

/*
|--------------------------------------------------------------------------
| YESIAM - RUTAS PRINCIPALES
|--------------------------------------------------------------------------
|
| FASE 21
|
| /sign-in               Inicio de sesión (público).
| /                      Dashboard.
| /requests              Solicitar acceso y mis solicitudes.
| /my-access             Inventario propio.
| /resources             Catálogo de recursos.
| /resources/:id         Detalle, elegibilidad y responsables.
| /approvals             Bandeja del responsable.
| /provisioning          Cola de concesión.
| /identities            Directorio.
| /identities/new        Alta (Joiner).
| /identities/:id        Ciclo de vida de una identidad.
| /departments           Departamentos.
| /audit                 Auditoría.
| /access-denied         Módulo no disponible para el perfil.
|
| SEGURIDAD:
|
| - authGuard (en MainLayout) verifica la sesión
|   con GET /api/user, que también entrega los
|   permisos efectivos.
| - permissionGuard evita abrir módulos que el
|   perfil no puede usar.
| - Laravel sigue autorizando cada operación.
|
*/

export const routes: Routes = [

  /*
  |--------------------------------------------------------------------------
  | AUTENTICACIÓN - RUTA PÚBLICA
  |--------------------------------------------------------------------------
  */

  {
    path: 'sign-in',

    title: 'Iniciar sesión | YESIAM',

    loadComponent: () =>
      import('./features/auth/login').then(
        (module) => module.Login
      ),
  },

  /*
  |--------------------------------------------------------------------------
  | APLICACIÓN PRINCIPAL - RUTAS PROTEGIDAS
  |--------------------------------------------------------------------------
  */

  {
    path: '',

    component: MainLayout,

    canActivate: [authGuard],

    children: [

      {
        path: '',
        pathMatch: 'full',
        title: 'Dashboard | YESIAM',
        loadComponent: () =>
          import('./features/dashboard/dashboard').then(
            (module) => module.Dashboard
          ),
      },

      /*
      | MI ESPACIO
      */

      {
        path: 'requests',
        title: 'Solicitar acceso | YESIAM',
        canActivate: [permissionGuard('access.request')],
        loadComponent: () =>
          import('./features/access/request-access').then(
            (module) => module.RequestAccess
          ),
      },

      {
        path: 'my-access',
        title: 'Mis accesos | YESIAM',
        loadComponent: () =>
          import('./features/access/my-access').then(
            (module) => module.MyAccess
          ),
      },

      /*
      | GOBIERNO DE ACCESOS
      */

      {
        path: 'resources',
        title: 'Recursos | YESIAM',
        canActivate: [permissionGuard('resources.view')],
        loadComponent: () =>
          import('./features/resources/resource-list').then(
            (module) => module.ResourceList
          ),
      },

      {
        path: 'resources/:id',
        title: 'Recurso | YESIAM',
        canActivate: [permissionGuard('resources.view')],
        loadComponent: () =>
          import('./features/resources/resource-detail').then(
            (module) => module.ResourceDetail
          ),
      },

      {
        path: 'approvals',
        title: 'Aprobaciones | YESIAM',
        canActivate: [permissionGuard('access.approve')],
        loadComponent: () =>
          import('./features/access/approvals').then(
            (module) => module.Approvals
          ),
      },

      {
        path: 'provisioning',
        title: 'Concesiones | YESIAM',
        canActivate: [permissionGuard('access.grant')],
        loadComponent: () =>
          import('./features/access/provisioning').then(
            (module) => module.Provisioning
          ),
      },

      /*
      | ADMINISTRACIÓN
      |
      | Se conserva el authGuard por ruta que
      | ya tenían las rutas de identidades.
      */

      {
        path: 'identities/new',
        title: 'Nueva identidad | YESIAM',
        canActivate: [authGuard, permissionGuard('users.manage')],
        loadComponent: () =>
          import('./features/identities/identity-create').then(
            (module) => module.IdentityCreate
          ),
      },

      {
        path: 'identities/:id',
        title: 'Identidad | YESIAM',
        canActivate: [authGuard, permissionGuard('users.manage')],
        loadComponent: () =>
          import('./features/identities/identity-detail').then(
            (module) => module.IdentityDetail
          ),
      },

      {
        path: 'identities',
        title: 'Identidades | YESIAM',
        canActivate: [authGuard, permissionGuard('users.manage')],
        loadComponent: () =>
          import('./features/identities/identity-list').then(
            (module) => module.IdentityList
          ),
      },

      {
        path: 'departments',
        title: 'Departamentos | YESIAM',
        canActivate: [permissionGuard('departments.view')],
        loadComponent: () =>
          import('./features/departments/department-list').then(
            (module) => module.DepartmentList
          ),
      },

      /*
      | CUMPLIMIENTO
      */

      {
        path: 'audit',
        title: 'Auditoría | YESIAM',
        canActivate: [permissionGuard('audit.view')],
        loadComponent: () =>
          import('./features/audit/audit-log').then(
            (module) => module.AuditLog
          ),
      },

      {
        path: 'access-denied',
        title: 'Sin acceso | YESIAM',
        loadComponent: () =>
          import('./shared/ui/access-denied').then(
            (module) => module.AccessDenied
          ),
      },

    ],
  },

  /*
  |--------------------------------------------------------------------------
  | RUTAS DESCONOCIDAS
  |--------------------------------------------------------------------------
  */

  {
    path: '**',

    redirectTo: '',
  },

];
