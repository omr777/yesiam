import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';

import { RouterLink } from '@angular/router';

import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import { AuthService } from '../../core/auth/auth.service';
import { describeApiError } from '../../core/http/api-error';

import { formatDateTime } from '../../shared/labels';
import { Pager } from '../../shared/ui/pager';
import { StatusBadge } from '../../shared/ui/status-badge';

import {
  AccessGrantItem,
  GrantStatus,
  IdentityService,
} from '../identities/identity.service';

/*
|--------------------------------------------------------------------------
| YESIAM - MIS ACCESOS
|--------------------------------------------------------------------------
|
| FASE 21
|
| Inventario propio: GET /api/users/{yo}/access-grants.
|
| Cualquier identidad activa puede consultar
| sus propios accesos (AccessGrantPolicy).
| Nadie puede revocarse a sí misma, así que
| esta vista es de solo lectura.
|
*/

@Component({
  selector: 'app-my-access',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    RouterLink,
    StatusBadge,
    Pager,
  ],

  template: `
    <section class="page">

      <header class="page-header">
        <div>
          <h1>Mis accesos</h1>
          <p>
            Recursos a los que tienes acceso y el historial de los que
            te fueron revocados.
          </p>
        </div>

        @if (canRequest()) {
          <a class="btn btn-primary" routerLink="/requests">Solicitar acceso</a>
        }
      </header>

      <section class="panel" aria-labelledby="my-grants-title">
        <div class="panel-header">
          <h2 class="section-title" id="my-grants-title">Inventario</h2>

          <div class="segmented" role="group" aria-label="Filtrar por estado">
            @for (option of filters; track option.value) {
              <button
                type="button"
                class="segment"
                [class.is-selected]="filter() === option.value"
                [attr.aria-pressed]="filter() === option.value"
                (click)="changeFilter(option.value)"
              >
                {{ option.label }}
              </button>
            }
          </div>
        </div>

        @if (isLoading()) {
          <div class="empty-state" role="status">Cargando accesos...</div>
        } @else if (error()) {
          <div class="panel-body">
            <div class="notice error" role="alert">
              <strong>No fue posible consultar tus accesos.</strong>
              <p>{{ error() }}</p>
              <button type="button" class="btn" (click)="load(page())">Reintentar</button>
            </div>
          </div>
        } @else if (grants().length === 0) {
          <div class="empty-state">
            <strong>No hay accesos en esta vista</strong>
            Cuando un administrador conceda una solicitud aprobada, aparecerá aquí.
          </div>
        } @else {
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th scope="col">Recurso</th>
                  <th scope="col">Estado</th>
                  <th scope="col">Concedido</th>
                  <th scope="col">Revocado</th>
                </tr>
              </thead>
              <tbody>
                @for (grant of grants(); track grant.id) {
                  <tr>
                    <td>
                      <div class="cell-primary">{{ grant.resource?.name ?? 'Recurso no disponible' }}</div>
                      @if (grant.resource) {
                        <div class="cell-secondary"><span class="code">{{ grant.resource.code }}</span></div>
                      }
                    </td>
                    <td><app-status-badge kind="grant" [value]="grant.status" /></td>
                    <td class="small">
                      <div>{{ formatDateTime(grant.granted_at) }}</div>
                      <div class="cell-secondary">Por {{ grant.granted_by?.name ?? '—' }}</div>
                    </td>
                    <td class="small">
                      @if (grant.revoked_at) {
                        <div>{{ formatDateTime(grant.revoked_at) }}</div>
                        <div class="cell-secondary">Por {{ grant.revoked_by?.name ?? '—' }}</div>
                      } @else {
                        <span class="muted">—</span>
                      }
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>

          <app-pager
            [page]="page()"
            [lastPage]="lastPage()"
            [total]="total()"
            [from]="from()"
            [to]="to()"
            (pageChange)="load($event)"
          />
        }
      </section>

    </section>
  `,

  styles: [`
    .segmented {
      display: inline-flex;
      padding: 3px;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: var(--surface-sunken);
    }

    .segment {
      min-height: 30px;
      padding: 4px 12px;
      border: 0;
      border-radius: 6px;
      background: transparent;
      color: var(--text-muted);
      font: inherit;
      font-size: 13px;
      cursor: pointer;
    }

    .segment.is-selected {
      background: var(--surface);
      color: var(--text);
      font-weight: 600;
      box-shadow: var(--shadow-panel);
    }
  `],
})
export class MyAccess {

  private readonly identityService = inject(IdentityService);
  private readonly auth = inject(AuthService);
  private readonly destroyRef = inject(DestroyRef);

  readonly formatDateTime = formatDateTime;

  readonly canRequest = computed(() => this.auth.can('access.request'));

  readonly filters: { value: GrantStatus | ''; label: string }[] = [
    { value: 'active', label: 'Activos' },
    { value: 'revoked', label: 'Revocados' },
    { value: '', label: 'Todos' },
  ];

  readonly filter = signal<GrantStatus | ''>('active');
  readonly grants = signal<AccessGrantItem[]>([]);
  readonly isLoading = signal(false);
  readonly error = signal<string | null>(null);
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly total = signal(0);
  readonly from = signal<number | null>(null);
  readonly to = signal<number | null>(null);

  constructor() {
    this.load(1);
  }

  changeFilter(value: GrantStatus | ''): void {
    this.filter.set(value);
    this.load(1);
  }

  load(page: number): void {
    const user = this.auth.currentUser();

    if (!user) {
      this.error.set('Tu sesión no está disponible. Inicia sesión nuevamente.');
      return;
    }

    this.isLoading.set(true);
    this.error.set(null);

    this.identityService.accessGrants(user.id, {
      status: this.filter(),
      page,
    })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (inventory) => {
          const { current_page, last_page, per_page, total } = inventory.pagination;
          const start = (current_page - 1) * per_page;

          this.grants.set(inventory.data);
          this.page.set(current_page);
          this.lastPage.set(last_page);
          this.total.set(total);
          this.from.set(inventory.data.length > 0 ? start + 1 : null);
          this.to.set(inventory.data.length > 0 ? start + inventory.data.length : null);
          this.isLoading.set(false);
        },
        error: (error: unknown) => {
          this.grants.set([]);
          this.error.set(
            describeApiError(error, { action: 'consultar tus accesos' }).message
          );
          this.isLoading.set(false);
        },
      });
  }
}
