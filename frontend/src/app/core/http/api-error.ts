import { HttpErrorResponse } from '@angular/common/http';

/*
|--------------------------------------------------------------------------
| YESIAM - TRADUCCIÓN DE ERRORES HTTP
|--------------------------------------------------------------------------
|
| FASE 21
|
| Centraliza cómo la interfaz explica un error
| de Laravel, para que todas las pantallas
| hablen igual.
|
| Reglas (las mismas de las Fases 19.4 y 19.5):
|
| - No se muestran mensajes internos ni trazas.
| - 401 y 419 orientan a iniciar sesión.
| - 403 explica que falta un permiso.
| - 409 sí muestra el mensaje de Laravel: son
|   reglas de negocio redactadas en español
|   para el usuario (por ejemplo, "La identidad
|   ya está desactivada").
| - 422 devuelve los campos rechazados para que
|   cada formulario los marque.
|
*/

export interface ApiError {
  status: number | null;
  message: string;
  fieldErrors: Record<string, string[]>;
  requiresLogin: boolean;
}

export interface ApiErrorContext {
  /*
   * Qué intentaba hacer el usuario,
   * por ejemplo "registrar el departamento".
   */
  action?: string;

  /*
   * Mensaje propio para 403.
   */
  forbidden?: string;

  /*
   * Mensaje propio para 404.
   */
  notFound?: string;
}

export function describeApiError(
  error: unknown,
  context: ApiErrorContext = {}
): ApiError {

  if (!(error instanceof HttpErrorResponse)) {
    return {
      status: null,
      message: 'Ocurrió un error inesperado en la aplicación.',
      fieldErrors: {},
      requiresLogin: false,
    };
  }

  const action = context.action ?? 'completar la operación';

  const body = error.error as {
    message?: unknown;
    errors?: unknown;
  } | null;

  const serverMessage =
    typeof body?.message === 'string' ? body.message : null;

  const fieldErrors =
    body && typeof body.errors === 'object' && body.errors !== null
      ? (body.errors as Record<string, string[]>)
      : {};

  const result = (
    message: string,
    requiresLogin = false
  ): ApiError => ({
    status: error.status,
    message,
    fieldErrors: error.status === 422 ? fieldErrors : {},
    requiresLogin,
  });

  switch (error.status) {

    case 0:
      return result(
        'No fue posible conectar con YESIAM. Verifica que Laravel esté disponible.'
      );

    case 401:
      return result(
        'Tu sesión no está disponible. Inicia sesión nuevamente.',
        true
      );

    case 403:
      return result(
        context.forbidden
          ?? `No tienes permisos para ${action}.`
      );

    case 404:
      return result(
        context.notFound
          ?? 'El elemento solicitado no existe o ya no está disponible.'
      );

    case 409:
      return result(
        serverMessage
          ?? 'La operación entra en conflicto con el estado actual. Actualiza la información e inténtalo de nuevo.'
      );

    case 419:
      return result(
        'La sesión de seguridad expiró. Inicia sesión nuevamente.',
        true
      );

    case 422:
      return result(
        'Revisa los datos marcados e inténtalo de nuevo.'
      );

    case 429:
      return result(
        'Demasiados intentos seguidos. Espera un momento antes de reintentar.'
      );

    default:
      return result(
        `El servidor no pudo ${action}.`
      );
  }
}

/*
 * Primer mensaje de validación de un campo.
 *
 * Los mensajes de Laravel pueden venir en inglés
 * (validaciones genéricas) o en español (reglas
 * propias de YESIAM). Cada formulario decide si
 * usar este texto o uno propio.
 */
export function firstFieldError(
  error: ApiError,
  field: string
): string | null {
  const messages = error.fieldErrors[field];

  return Array.isArray(messages) && messages.length > 0
    ? messages[0]
    : null;
}
