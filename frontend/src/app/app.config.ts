import {
  ApplicationConfig,
  provideBrowserGlobalErrorListeners,
} from '@angular/core';

import { provideRouter } from '@angular/router';

import {
  provideHttpClient,
  withXsrfConfiguration,
} from '@angular/common/http';

import { routes } from './app.routes';

/*
|--------------------------------------------------------------------------
| YESIAM - CONFIGURACIÓN PRINCIPAL DE ANGULAR
|--------------------------------------------------------------------------
|
| FASE 19.3.6
|
| Responsabilidades:
|
| 1. Registrar las rutas de Angular.
| 2. Habilitar HttpClient.
| 3. Configurar protección XSRF para Laravel Sanctum.
|
| Nuestra aplicación utiliza autenticación
| basada en sesiones y cookies.
|
| Las solicitudes HTTP utilizarán rutas relativas
| para aprovechar el proxy de desarrollo:
|
| /sanctum/csrf-cookie
| /login
| /logout
| /api/user
|
| Laravel seguirá siendo responsable
| de la autenticación y autorización.
|
*/

export const appConfig: ApplicationConfig = {
  providers: [

    /*
     * Manejo global de errores del navegador.
     */
    provideBrowserGlobalErrorListeners(),

    /*
     * Sistema de navegación de YESIAM.
     */
    provideRouter(routes),

    /*
     * Cliente HTTP de Angular.
     *
     * XSRF:
     *
     * Laravel entrega una cookie llamada:
     * XSRF-TOKEN
     *
     * Angular la utiliza para enviar:
     * X-XSRF-TOKEN
     *
     * en solicitudes HTTP que modifican datos
     * y utilizan URLs relativas.
     */
    provideHttpClient(
      withXsrfConfiguration({
        cookieName: 'XSRF-TOKEN',
        headerName: 'X-XSRF-TOKEN',
      })
    ),

  ],
};
