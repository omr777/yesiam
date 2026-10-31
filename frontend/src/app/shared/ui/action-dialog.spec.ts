import {
  Component,
  signal,
} from '@angular/core';

import {
  ComponentFixture,
  TestBed,
} from '@angular/core/testing';

import { ActionDialog } from './action-dialog';

/*
|--------------------------------------------------------------------------
| YESIAM - DIÁLOGO DE ACCIÓN SENSIBLE
|--------------------------------------------------------------------------
|
| FASE 21
|
*/

@Component({
  standalone: true,
  imports: [ActionDialog],
  template: `
    <app-action-dialog
      [open]="open()"
      title="Revocar acceso"
      description="Se retirará el acceso."
      confirmLabel="Revocar"
      [reasonLabel]="reasonLabel()"
      [confirmWord]="confirmWord()"
      [busy]="busy()"
      (confirmed)="confirmed.push($event)"
      (closed)="closedCount = closedCount + 1"
    />
  `,
})
class Host {
  readonly open = signal(true);
  readonly reasonLabel = signal<string | null>('Motivo');
  readonly confirmWord = signal<string | null>(null);
  readonly busy = signal(false);
  confirmed: string[] = [];
  closedCount = 0;
}

describe('ActionDialog', () => {

  let fixture: ComponentFixture<Host>;
  let host: Host;

  function element(): HTMLElement {
    return fixture.nativeElement as HTMLElement;
  }

  function submit(): void {
    element().querySelector('form')!.dispatchEvent(
      new Event('submit', { cancelable: true })
    );
    fixture.detectChanges();
  }

  function type(selector: string, value: string): void {
    const input = element().querySelector(selector) as
      HTMLInputElement | HTMLTextAreaElement;

    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [Host],
    });

    fixture = TestBed.createComponent(Host);
    host = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should open the dialog with title and description', () => {
    const dialog = element().querySelector('dialog')!;

    expect(dialog.hasAttribute('open')).toBe(true);
    expect(dialog.textContent).toContain('Revocar acceso');
    expect(dialog.textContent).toContain('Se retirará el acceso.');
  });

  it('should require a reason with the minimum length', () => {
    type('textarea', 'corto');
    submit();

    expect(host.confirmed).toEqual([]);
    expect(element().textContent).toContain('Escribe al menos 10 caracteres');

    type('textarea', '  Motivo suficiente  ');
    submit();

    expect(host.confirmed).toEqual(['Motivo suficiente']);
  });

  it('should require the confirmation word', () => {
    host.reasonLabel.set(null);
    host.confirmWord.set('DESACTIVAR');
    fixture.detectChanges();

    const confirmButton = element().querySelector(
      'button[type=submit]'
    ) as HTMLButtonElement;

    expect(confirmButton.disabled).toBe(true);

    type('input[name=confirm]', 'desactivar');
    expect(confirmButton.disabled).toBe(true);

    type('input[name=confirm]', 'DESACTIVAR');
    expect(confirmButton.disabled).toBe(false);

    submit();

    expect(host.confirmed).toEqual(['']);
  });

  it('should emit closed on cancel but not while busy', () => {
    const cancel = element().querySelector(
      'button[type=button]'
    ) as HTMLButtonElement;

    cancel.click();
    expect(host.closedCount).toBe(1);

    host.busy.set(true);
    fixture.detectChanges();

    cancel.click();
    expect(host.closedCount).toBe(1);
  });

  it('should close when open becomes false', () => {
    host.open.set(false);
    fixture.detectChanges();

    expect(
      element().querySelector('dialog')!.hasAttribute('open')
    ).toBe(false);
  });
});
