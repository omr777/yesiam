import { inject } from '@angular/core';

import {
  CanActivateFn,
  Router,
} from '@angular/router';

import {
  catchError,
  map,
  of,
} from 'rxjs';

import { AuthService } from './auth.service';

/*
|--------------------------------------------------------------------------
| YESIAM - AUTH GUARD
|--------------------------------------------------------------------------
|
| FASE 19.3.11
|
| Responsabilidad:
|
| Comprobar que el usuario tenga una sesión
| válida antes de permitirle acceder
| a las rutas privadas de Angular.
|
| SEGURIDAD:
|
| - No confiamos únicamente en el estado
|   de autenticación almacenado en Angular.
|
| - Consultamos GET /api/user.
|
| - Laravel valida auth:sanctum
|   y account.active.
|
| - Los errores no conceden acceso.
|
| IMPORTANTE:
|
| Este guard protege la navegación visual.
| La autorización real sigue siendo
| responsabilidad del backend.
|
*/

export const authGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  /*
   * Verificamos la sesión directamente
   * contra el backend.
   *
   * checkSession() devuelve:
   *
   * - Usuario: sesión válida.
   * - null: respuesta 401 o 403.
   * - Error: problema de red o servidor.
   */

  return auth.checkSession().pipe(
    map((user) => {
      /*
       * Laravel confirmó la sesión.
       */
      if (user !== null) {
        return true;
      }

      /*
       * No existe una sesión utilizable.
       *
       * Redirigimos al formulario
       * sin permitir el Dashboard.
       */
      return router.createUrlTree([
        '/sign-in',
      ]);
    }),

    /*
     * Si falla la comunicación con Laravel,
     * no debemos permitir el acceso.
     *
     * Usamos un motivo diferente para
     * distinguir un problema técnico
     * de una sesión no autenticada.
     */
    catchError(() => {
      return of(
        router.createUrlTree(
          ['/sign-in'],
          {
            queryParams: {
              reason: 'service-unavailable',
            },
          }
        )
      );
    })
  );
};
