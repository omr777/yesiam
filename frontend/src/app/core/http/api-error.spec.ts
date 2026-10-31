import { HttpErrorResponse } from '@angular/common/http';

import {
  describeApiError,
  firstFieldError,
} from './api-error';

/*
|--------------------------------------------------------------------------
| YESIAM - TRADUCCIÓN DE ERRORES HTTP
|--------------------------------------------------------------------------
|
| FASE 21
|
*/

function httpError(
  status: number,
  body: unknown = null
): HttpErrorResponse {
  return new HttpErrorResponse({
    status,
    error: body,
    url: '/api/test',
  });
}

describe('describeApiError', () => {

  it('should explain connection failures', () => {
    const error = describeApiError(httpError(0));

    expect(error.status).toBe(0);
    expect(error.message).toContain('No fue posible conectar');
    expect(error.requiresLogin).toBe(false);
  });

  it('should require login on 401 and 419', () => {
    expect(describeApiError(httpError(401)).requiresLogin).toBe(true);
    expect(describeApiError(httpError(419)).requiresLogin).toBe(true);
    expect(describeApiError(httpError(419)).message).toContain('expiró');
  });

  it('should describe a 403 using the attempted action', () => {
    const error = describeApiError(httpError(403, {
      message: 'This action is unauthorized.',
    }), {
      action: 'crear departamentos',
    });

    expect(error.message).toBe('No tienes permisos para crear departamentos.');
    expect(error.message).not.toContain('unauthorized');
  });

  it('should prefer a custom forbidden message', () => {
    const error = describeApiError(httpError(403), {
      forbidden: 'Mensaje propio.',
    });

    expect(error.message).toBe('Mensaje propio.');
  });

  it('should show Laravel business rules on 409', () => {
    const error = describeApiError(httpError(409, {
      message: 'La identidad ya está desactivada.',
    }));

    expect(error.message).toBe('La identidad ya está desactivada.');
  });

  it('should expose validation fields only on 422', () => {
    const error = describeApiError(httpError(422, {
      message: 'The given data was invalid.',
      errors: {
        code: ['The code has already been taken.'],
      },
    }));

    expect(error.message).toContain('Revisa los datos');
    expect(error.message).not.toContain('given data');
    expect(firstFieldError(error, 'code')).toBe('The code has already been taken.');
    expect(firstFieldError(error, 'name')).toBeNull();
  });

  it('should not expose server errors on 500', () => {
    const error = describeApiError(httpError(500, {
      message: 'SQLSTATE[HY000]: General error',
    }), {
      action: 'guardar los cambios',
    });

    expect(error.message).toBe('El servidor no pudo guardar los cambios.');
    expect(error.message).not.toContain('SQLSTATE');
    expect(error.fieldErrors).toEqual({});
  });

  it('should handle non-HTTP errors', () => {
    const error = describeApiError(new Error('boom'));

    expect(error.status).toBeNull();
    expect(error.message).toContain('inesperado');
  });
});
