import {
  ChangeDetectionStrategy,
  Component,
} from '@angular/core';

import { RouterLink } from '@angular/router';

/*
|--------------------------------------------------------------------------
| YESIAM - SIN ACCESO
|--------------------------------------------------------------------------
|
| Destino de permissionGuard cuando la sesión
| no tiene el permiso del módulo.
|
*/

@Component({
  selector: 'app-access-denied',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink],

  template: `
    <section class="page page--narrow">
      <div class="panel denied">
        <h1>Este módulo no está disponible para tu perfil</h1>

        <p>
          Tus roles actuales no incluyen el permiso que exige esta
          sección. Si lo necesitas para tu trabajo, pide a un
          administrador IAM que revise tus roles.
        </p>

        <a class="btn btn-primary" routerLink="/">Ir al dashboard</a>
      </div>
    </section>
  `,

  styles: [`
    .denied {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 14px;
      padding: 32px;
    }

    .denied h1 {
      font-size: 22px;
      font-weight: 600;
    }

    .denied p {
      color: var(--text-muted);
      max-width: 60ch;
    }
  `],
})
export class AccessDenied {}
