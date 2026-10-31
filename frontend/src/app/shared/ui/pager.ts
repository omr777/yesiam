import {
  ChangeDetectionStrategy,
  Component,
  input,
  output,
} from '@angular/core';

/*
|--------------------------------------------------------------------------
| YESIAM - PAGINACIÓN
|--------------------------------------------------------------------------
|
| Paginación compacta para respuestas
| paginadas de Laravel.
|
*/

@Component({
  selector: 'app-pager',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,

  template: `
    @if (total() > 0) {
      <div class="pager">
        <span>
          {{ from() ?? 0 }}–{{ to() ?? 0 }} de {{ total() }}
        </span>

        @if (lastPage() > 1) {
          <div class="actions">
            <button
              type="button"
              class="btn btn-sm"
              [disabled]="disabled() || page() <= 1"
              (click)="pageChange.emit(page() - 1)"
            >
              Anterior
            </button>

            <span>Página {{ page() }} de {{ lastPage() }}</span>

            <button
              type="button"
              class="btn btn-sm"
              [disabled]="disabled() || page() >= lastPage()"
              (click)="pageChange.emit(page() + 1)"
            >
              Siguiente
            </button>
          </div>
        }
      </div>
    }
  `,
})
export class Pager {
  readonly page = input(1);
  readonly lastPage = input(1);
  readonly total = input(0);
  readonly from = input<number | null>(null);
  readonly to = input<number | null>(null);
  readonly disabled = input(false);

  readonly pageChange = output<number>();
}
