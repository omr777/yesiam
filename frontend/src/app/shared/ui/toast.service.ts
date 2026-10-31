import {
  Injectable,
  signal,
} from '@angular/core';

/*
|--------------------------------------------------------------------------
| YESIAM - NOTIFICACIONES
|--------------------------------------------------------------------------
|
| FASE 21
|
| Confirma operaciones exitosas con un aviso
| breve. Los errores se muestran junto al
| formulario o la acción que los produjo,
| no aquí, para que el contexto no se pierda.
|
*/

export interface Toast {
  id: number;
  message: string;
}

@Injectable({
  providedIn: 'root',
})
export class ToastService {

  private nextId = 1;

  private readonly state = signal<Toast[]>([]);

  readonly toasts = this.state.asReadonly();

  success(message: string, durationMs = 4500): void {
    const toast: Toast = {
      id: this.nextId++,
      message,
    };

    this.state.update((current) => [...current, toast].slice(-3));

    if (durationMs > 0) {
      setTimeout(() => this.dismiss(toast.id), durationMs);
    }
  }

  dismiss(id: number): void {
    this.state.update((current) =>
      current.filter((toast) => toast.id !== id)
    );
  }
}
