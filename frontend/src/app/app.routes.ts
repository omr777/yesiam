import { Routes } from '@angular/router';

import { MainLayout } from './layout/main-layout';

import { authGuard } from './core/auth/auth.guard';

/*
|--------------------------------------------------------------------------
| YESIAM - RUTAS PRINCIPALES
|--------------------------------------------------------------------------
|
| FASE 19.3.12
|
| /sign-in
|   Pantalla pública de autenticación.
|
| /
|   Área privada de YESIAM.
|   Requiere una sesión válida de Laravel.
|
| SEGURIDAD:
|
| El AuthGuard verifica la sesión mediante
| GET /api/user antes de activar
| las rutas protegidas.
|
| La autorización real de los recursos
| sigue siendo responsabilidad de Laravel.
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
  | canActivate verifica la sesión antes
  | de activar el MainLayout.
  |
  | En el futuro podremos añadir aquí:
  |
  | - Usuarios
  | - Departamentos
  | - Recursos
  | - Solicitudes de acceso
  | - Auditoría
  |
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
