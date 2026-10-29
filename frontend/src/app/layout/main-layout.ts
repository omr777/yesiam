
import { Component, signal } from '@angular/core';

import {
  RouterLink,
  RouterLinkActive,
  RouterOutlet,
} from '@angular/router';

import { LogoutButton } from '../core/auth/logout-button';

/*
|--------------------------------------------------------------------------
| YESIAM - MAIN APPLICATION LAYOUT
|--------------------------------------------------------------------------
|
| Fase 19.3.15
|
| Responsabilidades:
|
| - Mostrar el sidebar empresarial.
| - Mostrar el encabezado.
| - Contener las páginas mediante RouterOutlet.
| - Permitir navegación a las rutas implementadas.
| - Adaptar el menú a dispositivos móviles.
| - Incorporar el cierre de sesión con Laravel Sanctum.
|
| La autorización de operaciones IAM
| seguirá siendo responsabilidad de Laravel.
|
*/

@Component({
  selector: 'app-main-layout',
  standalone: true,
  imports: [
    RouterOutlet,
    RouterLink,
    RouterLinkActive,
    LogoutButton,
  ],
  template: `
    <div class="app-shell">

      <!-- SIDEBAR -->
      <aside
        class="sidebar"
        aria-label="Barra lateral de YESIAM"
      >

        <!-- IDENTIDAD DE MARCA -->
        <div class="brand-row">

          <div class="brand">
            <div
              class="brand-symbol"
              aria-hidden="true"
            >
              Y
            </div>

            <div class="brand-text">
              <strong>YESIAM</strong>

              <span>
                Identity & Access Management
              </span>
            </div>
          </div>

          <!-- BOTÓN PARA DISPOSITIVOS MÓVILES -->
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

        <span class="workspace-label">
          WORKSPACE
        </span>

        <!-- NAVEGACIÓN -->
        <nav
          id="yesiam-navigation"
          class="navigation"
          aria-label="Módulos de YESIAM"
          [class.is-open]="mobileMenuOpen()"
        >

          <!-- DASHBOARD: RUTA FUNCIONAL -->
          <a
            class="nav-item"
            routerLink="/"
            routerLinkActive="selected"
            [routerLinkActiveOptions]="{ exact: true }"
            ariaCurrentWhenActive="page"
            title="Dashboard"
            (click)="closeMobileMenu()"
          >
            <span
              class="nav-icon"
              aria-hidden="true"
            >
              DB
            </span>

            <span class="nav-label">
              Dashboard
            </span>
          </a>

          <!-- IDENTITY MANAGEMENT -->
          <span class="nav-section">
            IDENTITY MANAGEMENT
          </span>

          <span
            class="nav-item nav-item--pending"
            title="Módulo pendiente de implementar"
          >
            <span class="nav-icon" aria-hidden="true">
              ID
            </span>

            <span class="nav-label">
              Identidades
            </span>
          </span>

          <span
            class="nav-item nav-item--pending"
            title="Módulo pendiente de implementar"
          >
            <span class="nav-icon" aria-hidden="true">
              DP
            </span>

            <span class="nav-label">
              Departamentos
            </span>
          </span>

          <!-- ACCESS GOVERNANCE -->
          <span class="nav-section">
            ACCESS GOVERNANCE
          </span>

          <span
            class="nav-item nav-item--pending"
            title="Módulo pendiente de implementar"
          >
            <span class="nav-icon" aria-hidden="true">
              RC
            </span>

            <span class="nav-label">
              Recursos
            </span>
          </span>

          <span
            class="nav-item nav-item--pending"
            title="Módulo pendiente de implementar"
          >
            <span class="nav-icon" aria-hidden="true">
              SO
            </span>

            <span class="nav-label">
              Solicitudes de acceso
            </span>
          </span>

          <span
            class="nav-item nav-item--pending"
            title="Módulo pendiente de implementar"
          >
            <span class="nav-icon" aria-hidden="true">
              AP
            </span>

            <span class="nav-label">
              Aprobaciones
            </span>
          </span>

          <span
            class="nav-item nav-item--pending"
            title="Módulo pendiente de implementar"
          >
            <span class="nav-icon" aria-hidden="true">
              GR
            </span>

            <span class="nav-label">
              Concesiones activas
            </span>
          </span>

          <!-- SECURITY & COMPLIANCE -->
          <span class="nav-section">
            SECURITY & COMPLIANCE
          </span>

          <span
            class="nav-item nav-item--pending"
            title="Módulo pendiente de implementar"
          >
            <span class="nav-icon" aria-hidden="true">
              AU
            </span>

            <span class="nav-label">
              Auditoría
            </span>
          </span>

        </nav>

        <!-- PIE DEL SIDEBAR -->
        <div class="sidebar-footer">

          <div class="environment-indicator">
            <span
              class="status-dot"
              aria-hidden="true"
            ></span>

            Entorno de desarrollo
          </div>

          <span class="version-label">
            YESIAM | Frontend en desarrollo
          </span>

        </div>

      </aside>

      <!-- CONTENIDO PRINCIPAL -->
      <div class="main-area">

        <!-- HEADER -->
        <header class="topbar">

          <div class="topbar-heading">

            <span class="breadcrumb">
              YESIAM / Workspace
            </span>

            <strong>
              Identity Governance
            </strong>

          </div>

          <div class="topbar-actions">

            <span class="connection-status">
              Acceso protegido
            </span>

            <div
              class="profile-avatar"
              aria-label="YESIAM"
              title="YESIAM"
            >
              Y
            </div>

            <!-- CIERRE DE SESIÓN -->
            <div class="logout-action">
              <app-logout-button />
            </div>

          </div>

        </header>

        <!-- PÁGINAS DE ANGULAR -->
        <main
          class="page-content"
          id="main-content"
        >
          <router-outlet />
        </main>

        <!-- FOOTER -->
        <footer class="main-footer">

          <span>
            YESIAM — Your Expert System,
            Identity, Access and Monitoring
          </span>

          <span>
            Development Environment
          </span>

        </footer>

      </div>

    </div>
  `,
  styles: [`
    :host {
      display: block;
      min-height: 100vh;
      font-family: Inter, "Segoe UI", Arial, sans-serif;
      color: #17243b;
    }

    * {
      box-sizing: border-box;
    }

    .app-shell {
      display: grid;
      grid-template-columns: 264px minmax(0, 1fr);
      min-height: 100vh;
      background: #f6f8fc;
    }

    /* SIDEBAR */

    .sidebar {
      display: flex;
      flex-direction: column;
      min-width: 0;
      padding: 26px 16px 20px;
      color: #b5c1d6;
      background: #101b30;
    }

    .brand-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 35px;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 0;
      padding: 0 12px;
    }

    .brand-symbol {
      display: grid;
      flex: 0 0 42px;
      place-items: center;
      width: 42px;
      height: 42px;
      border-radius: 12px;
      background: #5b74ec;
      color: #fff;
      font-size: 24px;
      font-weight: 800;
    }

    .brand-text {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }

    .brand-text strong {
      color: #fff;
      font-size: 19px;
      letter-spacing: 0.04em;
    }

    .brand-text span {
      font-size: 9px;
      letter-spacing: 0.02em;
    }

    .mobile-menu-button {
      display: none;
    }

    .workspace-label,
    .nav-section {
      display: block;
      padding: 0 14px;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.12em;
    }

    .workspace-label {
      margin-bottom: 18px;
      color: #8194b5;
    }

    .navigation {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }

    .nav-section {
      margin: 25px 0 9px;
      color: #788ba9;
    }

    .nav-item {
      display: flex;
      align-items: center;
      gap: 13px;
      min-height: 44px;
      padding: 0 14px;
      border-radius: 9px;
      color: #b5c1d6;
      font-size: 13px;
      text-decoration: none;
    }

    a.nav-item {
      cursor: pointer;
      transition:
        background 150ms ease,
        color 150ms ease;
    }

    a.nav-item:hover {
      background: #1d2c47;
      color: #fff;
    }

    a.nav-item:focus-visible {
      outline: 2px solid #9db0ff;
      outline-offset: 2px;
    }

    .nav-item.selected {
      background: #243453;
      color: #fff;
      font-weight: 600;
    }

    .nav-item--pending {
      color: #8493ad;
      cursor: default;
    }

    .nav-icon {
      display: inline-grid;
      flex: 0 0 24px;
      place-items: center;
      width: 24px;
      height: 24px;
      border-radius: 6px;
      background: #263651;
      color: #aabaff;
      font-size: 10px;
      font-weight: 800;
    }

    .nav-item.selected .nav-icon {
      background: #394e80;
      color: #fff;
    }

    .nav-label {
      min-width: 0;
    }

    .sidebar-footer {
      display: flex;
      flex-direction: column;
      gap: 14px;
      margin-top: auto;
      padding: 28px 13px 0;
    }

    .environment-indicator {
      display: flex;
      align-items: center;
      gap: 9px;
      font-size: 12px;
    }

    .status-dot {
      flex: 0 0 8px;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #45ce9d;
    }

    .version-label {
      color: #788ba9;
      font-size: 10px;
    }

    /* CONTENIDO */

    .main-area {
      display: flex;
      flex-direction: column;
      min-width: 0;
    }

    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      min-height: 84px;
      padding: 18px 36px;
      border-bottom: 1px solid #e7eaf1;
      background: #fff;
    }

    .topbar-heading {
      display: flex;
      flex-direction: column;
      gap: 7px;
    }

    .breadcrumb {
      color: #8793a7;
      font-size: 12px;
    }

    .topbar-heading strong {
      font-size: 14px;
    }

    .topbar-actions {
      display: flex;
      align-items: center;
      gap: 18px;
    }

    .connection-status {
      padding: 8px 11px;
      border: 1px solid #e5e9f2;
      border-radius: 8px;
      color: #78869b;
      font-size: 11px;
    }

    .profile-avatar {
      display: grid;
      place-items: center;
      flex: 0 0 36px;
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: #e9edff;
      color: #536bd5;
      font-size: 15px;
      font-weight: 700;
    }

    /* CIERRE DE SESIÓN */

    .logout-action {
      flex: 0 0 150px;
      width: 150px;
    }

    .page-content {
      flex: 1;
      width: 100%;
      max-width: 1400px;
      margin: 0 auto;
      padding: 42px 36px;
    }

    .main-footer {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      padding: 20px 36px;
      color: #91a0b3;
      font-size: 11px;
    }

    /* TABLET */

    @media (max-width: 900px) {
      .app-shell {
        grid-template-columns: 76px minmax(0, 1fr);
      }

      .sidebar {
        padding-inline: 10px;
      }

      .brand-row {
        justify-content: center;
      }

      .brand {
        justify-content: center;
        padding-inline: 0;
      }

      .brand-text,
      .workspace-label,
      .nav-section,
      .nav-label,
      .sidebar-footer {
        display: none;
      }

      .nav-item {
        justify-content: center;
        padding: 0;
      }

      .topbar,
      .page-content,
      .main-footer {
        padding-inline: 22px;
      }
    }

    /* MÓVIL */

    @media (max-width: 600px) {
      .app-shell {
        grid-template-columns: minmax(0, 1fr);
      }

      .sidebar {
        padding: 12px 18px;
      }

      .brand-row {
        justify-content: space-between;
        margin-bottom: 0;
      }

      .brand {
        padding: 0;
      }

      .brand-text {
        display: flex;
      }

      .mobile-menu-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 38px;
        padding: 0 12px;
        border: 1px solid #445572;
        border-radius: 8px;
        background: #263651;
        color: #fff;
        font: inherit;
        font-size: 12px;
        cursor: pointer;
      }

      .mobile-menu-button:focus-visible {
        outline: 2px solid #9db0ff;
        outline-offset: 2px;
      }

      .navigation {
        display: none;
      }

      .navigation.is-open {
        display: flex;
        margin-top: 20px;
        padding-bottom: 10px;
      }

      .navigation.is-open .nav-section,
      .navigation.is-open .nav-label {
        display: block;
      }

      .navigation.is-open .nav-item {
        justify-content: flex-start;
        padding: 0 14px;
      }

      .topbar {
        min-height: 70px;
        padding: 14px 18px;
      }

      .topbar-actions {
        gap: 8px;
      }

      .connection-status,
      .profile-avatar {
        display: none;
      }

      .logout-action {
        flex: 0 0 130px;
        width: 130px;
      }

      .page-content {
        padding: 28px 18px;
      }

      .main-footer {
        padding: 18px;
      }

      .main-footer span:last-child {
        display: none;
      }
    }
  `],
})
export class MainLayout {

  /*
   * Angular Signal:
   * indica si el menú móvil está abierto.
   */
  readonly mobileMenuOpen = signal(false);

  toggleMobileMenu(): void {
    this.mobileMenuOpen.update(
      (currentValue) => !currentValue
    );
  }

  closeMobileMenu(): void {
    this.mobileMenuOpen.set(false);
  }
}
