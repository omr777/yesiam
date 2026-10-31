/*
|--------------------------------------------------------------------------
| YESIAM - VOCABULARIO DE LA INTERFAZ
|--------------------------------------------------------------------------
|
| FASE 21
|
| Un solo lugar para nombrar estados, roles y
| eventos. Así "Revocada" significa lo mismo
| en el inventario, la auditoría y el dashboard.
|
*/

export type Tone = 'verified' | 'pending' | 'danger' | 'neutral';

export interface StatusLabel {
  label: string;
  tone: Tone;
}

export type StatusKind =
  | 'identity'
  | 'request'
  | 'grant'
  | 'catalog';

const STATUS_LABELS: Record<StatusKind, Record<string, StatusLabel>> = {
  identity: {
    active: { label: 'Activa', tone: 'verified' },
    suspended: { label: 'Suspendida', tone: 'pending' },
    disabled: { label: 'Desactivada', tone: 'danger' },
  },
  request: {
    pending: { label: 'Pendiente', tone: 'pending' },
    approved: { label: 'Aprobada', tone: 'verified' },
    rejected: { label: 'Rechazada', tone: 'danger' },
  },
  grant: {
    active: { label: 'Activo', tone: 'verified' },
    revoked: { label: 'Revocado', tone: 'danger' },
  },
  catalog: {
    true: { label: 'Activo', tone: 'verified' },
    false: { label: 'Inactivo', tone: 'neutral' },
  },
};

export function statusLabel(
  kind: StatusKind,
  value: string | boolean | null | undefined
): StatusLabel {
  const key = String(value);

  return STATUS_LABELS[kind][key]
    ?? { label: 'Desconocido', tone: 'neutral' };
}

/*
|--------------------------------------------------------------------------
| ROLES
|--------------------------------------------------------------------------
*/

export const ROLE_DESCRIPTIONS: Record<string, string> = {
  employee:
    'Solicita accesos y consulta su propio inventario.',
  resource_manager:
    'Aprueba o rechaza solicitudes de los recursos que tiene asignados.',
  iam_admin:
    'Administra identidades, departamentos, recursos, concesiones y auditoría.',
};

/*
|--------------------------------------------------------------------------
| EVENTOS DE AUDITORÍA
|--------------------------------------------------------------------------
*/

export const AUDIT_ACTION_LABELS: Record<string, string> = {
  'identity.created': 'Identidad creada (Joiner)',
  'identity.activated': 'Identidad activada',
  'identity.department_changed': 'Cambio de departamento (Mover)',
  'identity.deactivated': 'Identidad desactivada (Leaver)',
  'identity.roles_changed': 'Roles modificados',
  'department.created': 'Departamento creado',
  'department.updated': 'Departamento modificado',
  'department.deactivated': 'Departamento desactivado',
  'resource.created': 'Recurso creado',
  'resource.updated': 'Recurso modificado',
  'resource.deactivated': 'Recurso desactivado',
  'resource.approver_assigned': 'Responsable asignado',
  'resource.approver_removed': 'Responsable retirado',
  'access.requested': 'Acceso solicitado',
  'access.approved': 'Solicitud aprobada',
  'access.rejected': 'Solicitud rechazada',
  'access.granted': 'Acceso concedido',
  'access.revoked': 'Acceso revocado',
};

export const AUDIT_ENTITY_LABELS: Record<string, string> = {
  user: 'Identidad',
  department: 'Departamento',
  resource: 'Recurso',
  access_request: 'Solicitud de acceso',
};

export function auditActionLabel(action: string): string {
  return AUDIT_ACTION_LABELS[action] ?? action;
}

export function auditEntityLabel(entityType: string): string {
  return AUDIT_ENTITY_LABELS[entityType] ?? entityType;
}

/*
|--------------------------------------------------------------------------
| FECHAS
|--------------------------------------------------------------------------
|
| Formato local de México. Si el valor no es
| una fecha válida, se muestra un guion.
|
*/

const DATE_TIME_FORMAT = new Intl.DateTimeFormat('es-MX', {
  dateStyle: 'medium',
  timeStyle: 'short',
});

const DATE_FORMAT = new Intl.DateTimeFormat('es-MX', {
  dateStyle: 'medium',
});

export function formatDateTime(value: string | null | undefined): string {
  if (!value) {
    return '—';
  }

  const date = new Date(value);

  return Number.isNaN(date.getTime())
    ? '—'
    : DATE_TIME_FORMAT.format(date);
}

export function formatDate(value: string | null | undefined): string {
  if (!value) {
    return '—';
  }

  const date = new Date(value);

  return Number.isNaN(date.getTime())
    ? '—'
    : DATE_FORMAT.format(date);
}
