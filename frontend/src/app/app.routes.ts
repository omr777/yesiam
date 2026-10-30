import { Routes } from '@angular/router';

import { MainLayout } from './layout/main-layout';

import { authGuard } from './core/auth/auth.guard';

/*
|--------------------------------------------------------------------------
| YESIAM - RUTAS PRINCIPALES
|--------------------------------------------------------------------------
|
| FASE 19.4.10
|
| /sign-in
|   Pantalla pública de autenticación.
|
| /
|   Dashboard principal de YESIAM.
|
| /identities
|   Directorio de identidades.
|
| SEGURIDAD:
|
| Las rutas privadas se encuentran
| dentro de MainLayout y están
| protegidas por authGuard.
|
| El guard verifica la sesión
| mediante GET /api/user.
|
| Laravel continúa siendo responsable
| de autorizar cada operación IAM.
|
*/

export const routes: Routes = [

  /*
  |--------------------------------------------------------------------------
  | AUTENTICACIÓN - RUTA PÚBLICA
  |--------------------------------------------------------------------------
  |
  | No aplicamos authGuard aquí.
  |
  | Un usuario sin sesión debe poder
  | acceder al formulario de login.
  |
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
  |
  | El AuthGuard comprueba que existe
  | una sesión válida de Laravel.
  |
  | Los módulos comparten MainLayout:
  |
  | - Dashboard
  | - Identidades
  |
  | Los demás módulos se incorporarán
  | conforme sean implementados.
  |
  */

  {
    path: '',

    component: MainLayout,

    canActivate: [authGuard],

    children: [

      /*
      |--------------------------------------------------------------------------
      | DASHBOARD
      |--------------------------------------------------------------------------
      */

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
      |--------------------------------------------------------------------------
      | IDENTITY MANAGEMENT
      |--------------------------------------------------------------------------
      |
      | GET /api/users
      |
      | La consulta requiere:
      |
      | - Sesión válida de Sanctum.
      | - Cuenta activa.
      | - Permiso users.manage.
      |
      | Estas comprobaciones se
      | realizan en Laravel.
      |
      */

      {
        path: 'identities',

        title: 'Identidades | YESIAM',

        canActivate: [authGuard],

        loadComponent: () =>
          import('./features/identities/identity-list').then(
            (module) => module.IdentityList
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
