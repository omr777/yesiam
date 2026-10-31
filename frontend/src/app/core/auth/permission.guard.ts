import { inject } from '@angular/core';

import {
  CanActivateFn,
  Router,
} from '@angular/router';

import {
  AuthService,
  Permission,
} from './auth.service';

/*
|--------------------------------------------------------------------------
| YESIAM - GUARD DE PERMISOS
|--------------------------------------------------------------------------
|
| FASE 21
|
| Se usa en rutas hijas de MainLayout. El
| authGuard del padre ya verificó la sesión
| con GET /api/user, así que el usuario y
| sus permisos están cargados.
|
| Si falta el permiso, se muestra la página
| "Sin acceso" en lugar de una pantalla que
| solo recibiría errores 403.
|
| IMPORTANTE: esto es experiencia de usuario.
| La autorización real la aplica Laravel.
|
*/

export function permissionGuard(
  ...required: Permission[]
): CanActivateFn {
  return () => {
    const auth = inject(AuthService);
    const router = inject(Router);

    if (required.length === 0 || auth.canAny(required)) {
      return true;
    }

    return router.createUrlTree(['/access-denied']);
  };
}
