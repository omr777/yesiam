import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  signal,
} from '@angular/core';

import {
  RouterLink,
  RouterLinkActive,
  RouterOutlet,
} from '@angular/router';

import {
  AuthService,
  Permission,
} from '../core/auth/auth.service';

import { LogoutButton } from '../core/auth/logout-button';

import { ToastOutlet } from '../shared/ui/toast-outlet';

/*
|--------------------------------------------------------------------------
| YESIAM - MAIN APPLICATION LAYOUT
|--------------------------------------------------------------------------
|
| FASE 21
|
| Responsabilidades:
|
| - Navegación agrupada por el trabajo que
|   hace cada perfil (no por tabla de BD).
| - Mostrar solo los módulos que los permisos
|   de la sesión permiten usar.
| - Mostrar quién está trabajando: nombre,
|   departamento y roles.
| - Menú adaptable a móviles.
| - Cierre de sesión con Laravel Sanctum.
|
| Ocultar un módulo es experiencia de usuario.
| Laravel sigue autorizando cada operación.
|
*/

interface NavItem {
  path: string;
  label: string;
  icon: string;
  exact?: boolean;
  permissions?: Permission[];
}

interface NavGroup {
  label: string | null;
  items: NavItem[];
}

/*
 * Íconos de trazo, 20x20.
 */
const ICONS: Record<string, string> = {
  home: 'M3 9.5 10 4l7 5.5V16a1 1 0 0 1-1 1h-3.5v-4.5h-5V17H4a1 1 0 0 1-1-1V9.5Z',
  request: 'M10 4v12M4 10h12',
  keys: 'M12.5 7.5a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0ZM9 11v6m0-3h3m-3 2h2',
  catalog: 'M4 5h12M4 10h12M4 15h12',
  inbox: 'M3 11h4l1.5 2h3L13 11h4M4 5h12l1 6v5H3v-5l1-6Z',
  grant: 'm5 10 3 3 7-7M4 4h12v12H4z',
  people: 'M7.5 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM2.5 16c0-2.8 2.2-4.5 5-4.5s5 1.7 5 4.5M13 4.2a2.5 2.5 0 0 1 0 4.6M14.5 11.7c1.8.4 3 1.9 3 4.3',
  building: 'M4 17V4h8v13M12 8h4v9M7 7h2M7 10h2M7 13h2',
  audit: 'M6 3h6l3 3v11H6zM12 3v3h3M8.5 10h4M8.5 13h4',
};

@Component({
  selector: 'app-main-layout',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    RouterOutlet,
    RouterLink,
    RouterLinkActive,
    LogoutButton,
    ToastOutlet,
  ],
  template: `
    <div class="app-shell">

      <aside class="sidebar" aria-label="Barra lateral de YESIAM">

        <div class="brand-row">
          <a class="brand" routerLink="/" (click)="closeMobileMenu()">
            <span class="brand-mark" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22">
                <path
                  d="M12 2.5 4 6v5.5c0 4.6 3.3 8.6 8 10 4.7-1.4 8-5.4 8-10V6l-8-3.5Z"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.6"
                  stroke-linejoin="round"
                />
                <path
                  d="m8.5 12 2.4 2.4 4.6-4.8"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </span>

            <span class="brand-text">
              <strong>YESIAM</strong>
              <span>Gobierno de identidades</span>
            </span>
          </a>

          <button
            type="button"
            class="mobile-menu-button"
            aria-label="Mostrar u ocultar navegación"
            aria-controls="yesiam-navigation"
            [attr.aria-expanded]="mobileMenuOpen()"
            (click)="toggleMobileMenu()"
          >
            {{ mobileMenuOpen() ? 'Cerrar' : 'Menú' }}
          </button>
        </div>

        <div
          id="yesiam-navigation"
          class="navigation-panel"
          [class.is-open]="mobileMenuOpen()"
        >

          <nav class="navigation" aria-label="Módulos de YESIAM">

            @for (group of visibleGroups(); track $index) {

              @if (group.label) {
                <span class="nav-section">{{ group.label }}</span>
              }

              @for (item of group.items; track item.path) {
                <a
                  class="nav-item"
                  [routerLink]="item.path"
                  routerLinkActive="selected"
                  [routerLinkActiveOptions]="{ exact: item.exact ?? false }"
                  ariaCurrentWhenActive="page"
                  (click)="closeMobileMenu()"
                >
                  <svg
                    class="nav-icon"
                    viewBox="0 0 20 20"
                    width="18"
                    height="18"
                    aria-hidden="true"
                  >
                    <path
                      [attr.d]="icon(item.icon)"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="1.5"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                  </svg>

                  <span class="nav-label">{{ item.label }}</span>
                </a>
              }
            }

          </nav>

          <section class="session-card" aria-label="Sesión actual">
            @if (user(); as current) {
              <div class="session-person">
                <span class="session-avatar" aria-hidden="true">
                  {{ initials() }}
                </span>

                <div class="session-text">
                  <strong>{{ current.name }}</strong>
                  <span>{{ current.department?.name ?? 'Sin departamento' }}</span>
                </div>
              </div>

              @if (roleNames().length > 0) {
                <ul class="session-roles" aria-label="Roles asignados">
                  @for (role of roleNames(); track role) {
                    <li>{{ role }}</li>
                  }
                </ul>
              }
            }

            <div class="logout-action">
              <app-logout-button />
            </div>
          </section>

        </div>

      </aside>

      <div class="main-area">
        <main class="page-content" id="main-content">
          <router-outlet />
        </main>

        <footer class="main-footer">
          YESIAM — Your Expert System, Identity, Access and Monitoring
        </footer>
      </div>

      <app-toast-outlet />

    </div>
  `,
  styles: [`
    :host {
      display: block;
      min-height: 100vh;
    }

    .app-shell {
      display: grid;
      grid-template-columns: 264px minmax(0, 1fr);
      min-height: 100vh;
    }

    /*
    | SIDEBAR
    */

    .sidebar {
      position: sticky;
      top: 0;
      align-self: start;
      display: flex;
      flex-direction: column;
      height: 100vh;
      background: var(--ink);
      color: var(--text-on-ink);
      border-right: 1px solid var(--ink-line);
    }

    .brand-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      padding: 20px 18px 18px;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 11px;
      color: inherit;
      text-decoration: none;
    }

    .brand-mark {
      display: grid;
      place-items: center;
      width: 38px;
      height: 38px;
      border-radius: 9px;
      background: var(--verified);
      color: #ffffff;
    }

    .brand-text {
      display: flex;
      flex-direction: column;
    }

    .brand-text strong {
      font-size: 17px;
      font-weight: 700;
      letter-spacing: 0.04em;
    }

    .brand-text span {
      font-size: 12px;
      color: var(--text-on-ink-muted);
    }

    .mobile-menu-button {
      display: none;
      min-height: 34px;
      padding: 6px 12px;
      border: 1px solid var(--ink-line);
      border-radius: var(--radius-control);
      background: transparent;
      color: var(--text-on-ink);
      font: inherit;
      font-size: 13px;
      cursor: pointer;
    }

    .navigation-panel {
      display: flex;
      flex: 1;
      flex-direction: column;
      min-height: 0;
    }

    .navigation {
      flex: 1;
      overflow-y: auto;
      padding: 4px 12px 16px;
    }

    .nav-section {
      display: block;
      margin: 18px 10px 6px;
      font-size: 12px;
      font-weight: 500;
      color: var(--text-on-ink-muted);
    }

    .nav-item {
      display: flex;
      align-items: center;
      gap: 11px;
      min-height: 38px;
      padding: 8px 10px;
      border-radius: var(--radius-control);
      color: #c9d6d6;
      font-size: 14px;
      text-decoration: none;
      position: relative;
    }

    .nav-item:hover {
      background: var(--ink-soft);
      color: #ffffff;
    }

    .nav-item.selected {
      background: var(--ink-soft);
      color: #ffffff;
      font-weight: 600;
    }

    .nav-item.selected::before {
      content: '';
      position: absolute;
      left: -12px;
      top: 8px;
      bottom: 8px;
      width: 3px;
      border-radius: 0 3px 3px 0;
      background: #5eead4;
    }

    .nav-icon {
      flex: 0 0 18px;
      color: var(--text-on-ink-muted);
    }

    .nav-item.selected .nav-icon {
      color: #5eead4;
    }

    /*
    | TARJETA DE SESIÓN
    */

    .session-card {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin: 0 12px 14px;
      padding: 14px;
      border: 1px solid var(--ink-line);
      border-radius: var(--radius-panel);
      background: var(--ink-soft);
    }

    .session-person {
      display: flex;
      align-items: center;
      gap: 10px;
      min-width: 0;
    }

    .session-avatar {
      display: grid;
      flex: 0 0 34px;
      place-items: center;
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: #234350;
      color: #5eead4;
      font-size: 13px;
      font-weight: 700;
    }

    .session-text {
      display: flex;
      flex-direction: column;
      min-width: 0;
    }

    .session-text strong {
      overflow: hidden;
      font-size: 14px;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .session-text span {
      font-size: 12px;
      color: var(--text-on-ink-muted);
    }

    .session-roles {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .session-roles li {
      padding: 2px 8px;
      border: 1px solid var(--ink-line);
      border-radius: 999px;
      font-size: 11px;
      color: #c9d6d6;
    }

    /*
    | CONTENIDO
    */

    .main-area {
      display: flex;
      flex-direction: column;
      min-width: 0;
    }

    .page-content {
      flex: 1;
      padding: 32px 36px 40px;
    }

    .main-footer {
      padding: 18px 36px;
      border-top: 1px solid var(--line);
      font-size: 12px;
      color: var(--text-faint);
    }

    @media (max-width: 900px) {
      .app-shell {
        grid-template-columns: minmax(0, 1fr);
      }

      .sidebar {
        position: sticky;
        z-index: 30;
        height: auto;
        border-right: 0;
      }

      .brand-row {
        padding: 12px 16px;
      }

      .mobile-menu-button {
        display: inline-flex;
        align-items: center;
      }

      .navigation-panel {
        display: none;
        max-height: calc(100vh - 62px);
        overflow-y: auto;
      }

      .navigation-panel.is-open {
        display: flex;
      }

      .page-content {
        padding: 22px 16px 32px;
      }

      .main-footer {
        padding: 16px;
      }
    }
  `],
})
export class MainLayout {

  private readonly auth = inject(AuthService);

  readonly mobileMenuOpen = signal(false);

  readonly user = this.auth.currentUser;

  private readonly groups: NavGroup[] = [
    {
      label: null,
      items: [
        { path: '/', label: 'Dashboard', icon: 'home', exact: true },
      ],
    },
    {
      label: 'Mi espacio',
      items: [
        {
          path: '/requests',
          label: 'Solicitar acceso',
          icon: 'request',
          permissions: ['access.request'],
        },
        { path: '/my-access', label: 'Mis accesos', icon: 'keys' },
      ],
    },
    {
      label: 'Gobierno de accesos',
      items: [
        {
          path: '/resources',
          label: 'Recursos',
          icon: 'catalog',
          permissions: ['resources.view'],
        },
        {
          path: '/approvals',
          label: 'Aprobaciones',
          icon: 'inbox',
          permissions: ['access.approve'],
        },
        {
          path: '/provisioning',
          label: 'Concesiones',
          icon: 'grant',
          permissions: ['access.grant'],
        },
      ],
    },
    {
      label: 'Administración',
      items: [
        {
          path: '/identities',
          label: 'Identidades',
          icon: 'people',
          permissions: ['users.manage'],
        },
        {
          path: '/departments',
          label: 'Departamentos',
          icon: 'building',
          permissions: ['departments.view'],
        },
      ],
    },
    {
      label: 'Cumplimiento',
      items: [
        {
          path: '/audit',
          label: 'Auditoría',
          icon: 'audit',
          permissions: ['audit.view'],
        },
      ],
    },
  ];

  /*
   * Grupos con al menos un módulo permitido.
   */
  readonly visibleGroups = computed(() => {
    this.auth.permissions();

    return this.groups
      .map((group) => ({
        ...group,
        items: group.items.filter(
          (item) => !item.permissions
            || this.auth.canAny(item.permissions)
        ),
      }))
      .filter((group) => group.items.length > 0);
  });

  readonly roleNames = computed(() =>
    (this.user()?.roles ?? []).map((role) => role.name)
  );

  readonly initials = computed(() => {
    const name = this.user()?.name ?? '';

    return name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('');
  });

  icon(name: string): string {
    return ICONS[name] ?? '';
  }

  toggleMobileMenu(): void {
    this.mobileMenuOpen.update((open) => !open);
  }

  closeMobileMenu(): void {
    this.mobileMenuOpen.set(false);
  }
}
