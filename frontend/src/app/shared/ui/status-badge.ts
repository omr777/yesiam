import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
} from '@angular/core';

import {
  StatusKind,
  statusLabel,
} from '../labels';

/*
|--------------------------------------------------------------------------
| YESIAM - INSIGNIA DE ESTADO
|--------------------------------------------------------------------------
|
| <app-status-badge kind="identity" [value]="identity.status" />
|
| El texto siempre acompaña al color, para no
| depender solo del color (accesibilidad).
|
*/

@Component({
  selector: 'app-status-badge',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,

  template: `
    <span
      class="badge"
      [class.badge--verified]="status().tone === 'verified'"
      [class.badge--pending]="status().tone === 'pending'"
      [class.badge--danger]="status().tone === 'danger'"
    >
      {{ status().label }}
    </span>
  `,
})
export class StatusBadge {
  readonly kind = input.required<StatusKind>();

  readonly value = input.required<string | boolean | null | undefined>();

  readonly status = computed(() =>
    statusLabel(this.kind(), this.value())
  );
}
