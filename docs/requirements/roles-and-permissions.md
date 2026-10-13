# YESIAM — Roles y permisos

**Versión:** 0.3 — Borrador del modelo de autorización

## 1. Distinciones

- **Identidad:** persona o cuenta registrada.
- **Departamento:** área organizativa; no equivale a un permiso.
- **Rol global:** funciones administrativas dentro de YESIAM.
- **Permiso:** capacidad para ejecutar una operación.
- **Recurso:** objeto protegido registrado en YESIAM.
- **Concesión:** autorización específica para acceder a un recurso.

## 2. Roles iniciales

### Administrador IAM
Gestiona identidades, departamentos, recursos, asignaciones de roles permitidas y auditoría. Puede revocar concesiones según las reglas. **No tiene acceso automático al contenido de todos los recursos.**

### Responsable de recurso
Puede revisar solicitudes y revocar accesos **únicamente** sobre recursos para los que figura como responsable. No puede aprobar solicitudes propias.

### Empleado
Consulta su perfil, solicita accesos habilitados y revisa el estado de sus solicitudes y concesiones. No puede concederse privilegios.

> Una persona con funciones de responsable también puede usar funciones comunes de empleado. Su ámbito de aprobación procede de la asignación al recurso, no solo del rol global.

## 3. Matriz inicial

| Operación | Administrador IAM | Responsable de recurso | Empleado |
| --- | :---: | :---: | :---: |
| Consultar perfil propio | Sí | Sí | Sí |
| Gestionar identidades | Sí | No | No |
| Gestionar departamentos | Sí | No | No |
| Administrar catálogo de recursos | Sí | No | No |
| Solicitar acceso | Sí | Sí | Sí |
| Ver solicitudes propias | Sí | Sí | Sí |
| Resolver solicitudes de recursos asignados | No* | Sí | No |
| Revocar concesiones autorizadas | Sí | Solo sus recursos | No |
| Consultar auditoría general | Sí | No | No |
| Consultar contenido de recurso | Solo con concesión | Solo con concesión | Solo con concesión |

\*En el MVP, la decisión ordinaria corresponde al responsable asignado; un administrador IAM no debe poder saltarse por defecto ese control. El tratamiento de excepciones deberá definirse y auditarse antes de implementarlo.

## 4. Reglas de autorización

1. Verificar sesión autenticada e identidad activa.
2. Validar autorización en el backend para cada operación.
3. Aplicar **denegar por defecto**.
4. Comprobar el ámbito del recurso: no basta poseer el rol de responsable.
5. Rechazar autoaprobaciones y operaciones sobre solicitudes ya decididas.
6. Consultar concesiones vigentes antes de entregar contenido protegido.
7. Evitar la elevación de privilegios mediante solicitudes manipuladas.
8. Registrar cambios críticos de roles, solicitudes y concesiones.

## 5. Pruebas mínimas de autorización

- Un empleado intenta editar el rol de otro usuario → denegado.
- Un responsable intenta aprobar una solicitud de un recurso ajeno → denegado.
- Un responsable intenta aprobar su propia solicitud → denegado.
- Un usuario sin concesión intenta leer contenido protegido → HTTP 403.
- Una concesión revocada ya no permite consultar el contenido.
- Una cuenta desactivada no puede continuar usando sus sesiones.

## 6. Principios Zero Trust aplicados

- **Verify explicitly:** autenticar y revalidar condiciones para cada operación protegida.
- **Least privilege:** permisos mínimos y ámbitos por recurso.
- **Assume breach:** compartimentación, revocación y evidencia de eventos.
