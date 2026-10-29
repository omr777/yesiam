
import { Routes } from '@angular/router';
import { MainLayout } from './layout/main-layout';

/*
|--------------------------------------------------------------------------
| YESIAM - CONFIGURACIÓN DE RUTAS
|--------------------------------------------------------------------------
|
| FASE 19.2.6
|
| Estructura:
|
| App
|  └── MainLayout
|       └── Dashboard
|
| El MainLayout es el contenedor principal
| de las páginas del sistema.
|
| Los módulos se cargarán progresivamente
| utilizando lazy loading.
|
| IMPORTANTE:
| Las rutas del frontend no reemplazan
| las autorizaciones de Laravel.
|
*/

export const routes: Routes = [
  {
    path: '',
    component: MainLayout,

    children: [
      {
        path: '',
        pathMatch: 'full',

        title: 'Dashboard | YESIAM',

        loadComponent: () =>
          import('./features/dashboard/dashboard')
            .then((module) => module.Dashboard),
      },
    ],
  },

  /*
   * Ruta desconocida:
   * regresar al Dashboard.
   *
   * Más adelante podremos implementar
   * una página 404 específica.
   */
  {
    path: '**',
    redirectTo: '',
  },
];
