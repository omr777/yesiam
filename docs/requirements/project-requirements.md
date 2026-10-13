# YESIAM — Requisitos del sistema

**Proyecto:** Your Expert System for Identity, Access, and Monitoring  
**Versión:** 0.3 — Borrador para la primera versión (MVP)  
**Estado:** Diseño preliminar  
**Alcance:** Una organización y múltiples departamentos

## 1. Problema

Las organizaciones necesitan controlar identidades y accesos a recursos digitales. La gestión manual puede provocar permisos acumulados, cuentas activas tras una baja y falta de trazabilidad. YESIAM permitirá gestionar identidades y accesos **dentro de su propia plataforma**; no administrará permisos en sistemas externos en el MVP.

## 2. Objetivo general

Desarrollar una aplicación web de gestión de identidades y accesos con Angular, Laravel y MySQL, aplicando principios Zero Trust en autenticación, autorización y auditoría.

## 3. Objetivos específicos

- Gestionar el ciclo de vida de identidades: alta, cambio y baja.
- Implementar autenticación segura y sesiones revocables.
- Autorizar operaciones mediante roles, permisos y reglas por recurso.
- Permitir solicitudes, aprobaciones y revocaciones de acceso.
- Mantener evidencia auditable de acciones de seguridad relevantes.
- Probar la autorización desde el backend, incluyendo intentos no autorizados.
- Documentar el desarrollo mediante Git y GitHub.

## 4. Requisitos funcionales

| ID | Descripción | Prioridad |
| --- | --- | --- |
| RF-01 | Iniciar y cerrar sesión. | Alta |
| RF-02 | Registrar y actualizar identidades de empleados. | Alta |
| RF-03 | Desactivar identidades e invalidar sus sesiones. | Alta |
| RF-04 | Administrar roles y asignaciones autorizadas. | Alta |
| RF-05 | Registrar y gestionar recursos protegidos internos. | Alta |
| RF-06 | Solicitar acceso a recursos disponibles. | Alta |
| RF-07 | Aprobar o rechazar solicitudes por responsables asignados. | Alta |
| RF-08 | Revocar accesos concedidos. | Alta |
| RF-09 | Registrar eventos relevantes de autenticación y administración. | Alta |
| RF-10 | Mostrar indicadores con datos reales en un dashboard. | Media |
| RF-11 | Consultar historial de solicitudes propias y asignadas. | Media |
| RF-12 | Consultar y filtrar auditoría con autorización administrativa. | Media |

## 5. Requisitos no funcionales

| ID | Descripción |
| --- | --- |
| RNF-01 | Frontend Angular + TypeScript; backend Laravel + PHP; MySQL 8. |
| RNF-02 | Contraseñas almacenadas mediante hashing seguro (nunca texto plano). |
| RNF-03 | Autorización obligatoria en Laravel, independiente de la interfaz. |
| RNF-04 | Sesiones y endpoints protegidos mediante mecanismos apropiados. |
| RNF-05 | Denegación por defecto ante falta de permisos. |
| RNF-06 | HTTPS en despliegue público; gestión segura de cookies y CSRF. |
| RNF-07 | Interfaz responsive y accesible para operaciones principales. |
| RNF-08 | Errores sin exponer credenciales ni detalles internos sensibles. |
| RNF-09 | Pruebas automatizadas de reglas de autorización y negocio. |
| RNF-10 | Documentación y control de versiones con Git/GitHub; secretos excluidos. |

## 6. Reglas de negocio

- La pertenencia a un departamento **no concede** automáticamente acceso a sus recursos.
- Solo responsables expresamente asignados pueden decidir solicitudes sobre un recurso.
- Nadie aprueba su propia solicitud.
- Un empleado no puede asignarse roles o permisos administrativos.
- Un cambio de puesto o departamento exige revisar y revocar accesos anteriores que ya no correspondan.
- Una identidad desactivada no puede autenticarse ni mantener sesiones utilizables.
- Los permisos sobre recursos se comprueban en cada operación protegida.
- La concesión y su aprobación deben ser consistentes mediante una transacción.
- Las decisiones y cambios de acceso se registran en auditoría.

## 7. Fuera del alcance de la primera versión

- Aprovisionamiento en servicios externos.
- Integración con Microsoft Entra ID y Microsoft Graph.
- MFA, multiempresa, SIEM y análisis avanzado de amenazas.
- Microservicios o despliegue empresarial de alta disponibilidad.

## 8. Criterios de aceptación iniciales

- Un usuario autenticado sin acceso a un recurso recibe HTTP 403 al solicitar su contenido.
- Una solicitud propia no puede ser aprobada por el solicitante.
- Una revocación impide consultar el recurso en solicitudes posteriores.
- Cada aprobación, rechazo y revocación genera un evento de auditoría.
- Desactivar un usuario invalida sus sesiones y bloquea nuevos accesos.

> Nota: los requisitos se revisarán durante la implementación y las pruebas. Un registro en auditoría de aplicación no garantiza inmutabilidad frente a un administrador de base de datos.
