
import { Component } from '@angular/core';

/*
|--------------------------------------------------------------------------
| YESIAM - DASHBOARD
|--------------------------------------------------------------------------
|
| Centro de operaciones de Identity & Access Management.
|
| Esta primera versión presenta la estructura visual.
|
| IMPORTANTE:
| - No contiene estadísticas inventadas.
| - No realiza llamadas HTTP todavía.
| - No implementa autorizaciones.
|
| Los datos reales vendrán posteriormente
| de los endpoints protegidos de Laravel.
|
*/

@Component({
  selector: 'app-dashboard',
  standalone: true,
  template: `
    <section class="dashboard">

      <!-- Encabezado -->
      <header class="dashboard-header">
        <div>
          <span class="eyebrow">OPERATIONS OVERVIEW</span>

          <h2>Resumen de identidades y accesos</h2>

          <p>
            Supervisa el ciclo de vida de las identidades,
            las solicitudes de acceso y los eventos de seguridad.
          </p>
        </div>

        <span class="api-status">
          API pendiente de conectar
        </span>
      </header>

      <!-- Indicadores principales -->
      <section
        class="metrics-section"
        aria-labelledby="metrics-title"
      >
        <h3 id="metrics-title">Indicadores principales</h3>

        <div class="metrics-grid">

          <article class="metric-card">
            <div class="metric-heading">
              <span>Identidades activas</span>
              <span class="metric-icon" aria-hidden="true">ID</span>
            </div>

            <strong class="metric-value">—</strong>

            <span class="metric-description">
              Información pendiente de la API
            </span>
          </article>

          <article class="metric-card">
            <div class="metric-heading">
              <span>Accesos activos</span>
              <span class="metric-icon" aria-hidden="true">AC</span>
            </div>

            <strong class="metric-value">—</strong>

            <span class="metric-description">
              Información pendiente de la API
            </span>
          </article>

          <article class="metric-card">
            <div class="metric-heading">
              <span>Solicitudes pendientes</span>
              <span class="metric-icon" aria-hidden="true">SR</span>
            </div>

            <strong class="metric-value">—</strong>

            <span class="metric-description">
              Información pendiente de la API
            </span>
          </article>

          <article class="metric-card">
            <div class="metric-heading">
              <span>Eventos de auditoría</span>
              <span class="metric-icon" aria-hidden="true">AU</span>
            </div>

            <strong class="metric-value">—</strong>

            <span class="metric-description">
              Información pendiente de la API
            </span>
          </article>

        </div>
      </section>

      <!-- Paneles informativos -->
      <div class="dashboard-panels">

        <section
          class="panel"
          aria-labelledby="requests-title"
        >
          <div class="panel-header">
            <div>
              <h3 id="requests-title">
                Solicitudes recientes
              </h3>

              <p>
                Actividad de solicitudes de acceso.
              </p>
            </div>
          </div>

          <div class="empty-state">
            <div class="empty-icon" aria-hidden="true">SR</div>

            <strong>Sin datos disponibles</strong>

            <p>
              Las solicitudes aparecerán aquí cuando
              conectemos el módulo con Laravel.
            </p>
          </div>
        </section>

        <section
          class="panel"
          aria-labelledby="audit-title"
        >
          <div class="panel-header">
            <div>
              <h3 id="audit-title">
                Actividad de seguridad
              </h3>

              <p>
                Eventos relevantes del sistema IAM.
              </p>
            </div>
          </div>

          <div class="empty-state">
            <div class="empty-icon" aria-hidden="true">AU</div>

            <strong>Auditoría sin conectar</strong>

            <p>
              Los eventos de identidad, concesión
              y revocación se mostrarán aquí
              después de integrar la API.
            </p>
          </div>
        </section>

      </div>

      <!-- Información sobre el módulo -->
      <section class="integration-note">
        <div class="note-symbol" aria-hidden="true">i</div>

        <div>
          <strong>Dashboard en desarrollo</strong>

          <p>
            Esta vista todavía no consulta datos reales.
            Los indicadores y registros estarán sujetos
            a la autenticación y autorización de Laravel.
          </p>
        </div>
      </section>

    </section>
  `,
  styles: [`
    :host {
      display: block;
      font-family: Inter, "Segoe UI", Arial, sans-serif;
      color: #17243b;
    }

    * {
      box-sizing: border-box;
    }

    .dashboard {
      display: flex;
      flex-direction: column;
      gap: 32px;
    }

    .dashboard-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 20px;
    }

    .eyebrow {
      color: #6178aa;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 0.12em;
    }

    .dashboard-header h2 {
      margin: 10px 0 9px;
      font-size: 23px;
      font-weight: 750;
      letter-spacing: -0.035em;
    }

    .dashboard-header p {
      max-width: 650px;
      margin: 0;
      color: #77849a;
      font-size: 13px;
      line-height: 1.7;
    }

    .api-status {
      flex-shrink: 0;
      padding: 9px 12px;
      border: 1px solid #e6eaf2;
      border-radius: 8px;
      background: #fff;
      color: #7a879c;
      font-size: 11px;
    }

    .metrics-section h3 {
      margin: 0 0 17px;
      font-size: 14px;
      font-weight: 700;
    }

    .metrics-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 16px;
    }

    .metric-card {
      display: flex;
      flex-direction: column;
      min-height: 155px;
      padding: 20px;
      border: 1px solid #e6eaf2;
      border-radius: 12px;
      background: #fff;
    }

    .metric-heading {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
      color: #66758d;
      font-size: 12px;
      font-weight: 600;
    }

    .metric-icon {
      display: grid;
      place-items: center;
      width: 30px;
      height: 30px;
      border-radius: 8px;
      background: #eef1ff;
      color: #6174cc;
      font-size: 10px;
      font-weight: 800;
    }

    .metric-value {
      margin-top: 16px;
      color: #25334d;
      font-size: 29px;
      line-height: 1;
    }

    .metric-description {
      margin-top: auto;
      padding-top: 12px;
      color: #98a3b5;
      font-size: 11px;
    }

    .dashboard-panels {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 18px;
    }

    .panel {
      min-height: 310px;
      padding: 24px;
      border: 1px solid #e6eaf2;
      border-radius: 13px;
      background: #fff;
    }

    .panel-header h3 {
      margin: 0 0 7px;
      font-size: 15px;
    }

    .panel-header p {
      margin: 0;
      color: #8b98ac;
      font-size: 12px;
    }

    .empty-state {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      min-height: 210px;
      padding: 20px;
      text-align: center;
    }

    .empty-icon {
      display: grid;
      place-items: center;
      width: 46px;
      height: 46px;
      margin-bottom: 13px;
      border-radius: 12px;
      background: #f1f4fa;
      color: #91a0b8;
      font-size: 13px;
      font-weight: 800;
    }

    .empty-state strong {
      font-size: 13px;
    }

    .empty-state p {
      max-width: 285px;
      margin: 9px 0 0;
      color: #93a0b2;
      font-size: 12px;
      line-height: 1.7;
    }

    .integration-note {
      display: flex;
      align-items: flex-start;
      gap: 13px;
      padding: 18px;
      border: 1px solid #dfe7f8;
      border-radius: 11px;
      background: #f0f5ff;
    }

    .note-symbol {
      display: grid;
      flex: 0 0 23px;
      place-items: center;
      width: 23px;
      height: 23px;
      border-radius: 50%;
      background: #dce6fc;
      color: #5372b5;
      font-size: 13px;
      font-weight: 800;
    }

    .integration-note strong {
      color: #38588e;
      font-size: 12px;
    }

    .integration-note p {
      margin: 6px 0 0;
      color: #677fa8;
      font-size: 12px;
      line-height: 1.7;
    }

    @media (max-width: 1100px) {
      .metrics-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 700px) {
      .dashboard-header {
        flex-direction: column;
      }

      .dashboard-header h2 {
        font-size: 20px;
      }

      .dashboard-panels {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 450px) {
      .metrics-grid {
        grid-template-columns: 1fr;
      }
    }
  `],
})
export class Dashboard {}
