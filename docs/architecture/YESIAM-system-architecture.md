# YESIAM — Arquitectura del sistema

**Proyecto:** Your Expert System for Identity, Access, and Monitoring  
**Versión:** 0.3 — Diseño inicial  
**Arquitectura:** Aplicación web con frontend independiente y backend API REST  
**Alcance:** Una organización con múltiples departamentos

## 1. Propósito

Definir la estructura técnica de YESIAM y las responsabilidades de sus componentes, estableciendo una base mantenible para gestionar identidades, roles, recursos, solicitudes de acceso y eventos de auditoría.

## 2. Componentes principales

### Frontend — Angular

Responsable de presentar la información y proporcionar las interfaces de usuario.

Módulos funcionales previstos:

- Autenticación y gestión de sesión.
- Panel administrativo.
- Gestión de identidades y departamentos.
- Roles y permisos.
- Recursos protegidos.
- Solicitudes y revisiones de acceso.
- Consulta de auditoría.

Angular podrá ocultar acciones no disponibles para mejorar la experiencia de usuario, pero no constituirá la autoridad definitiva para conceder permisos.

### Backend — Laravel

Responsable de procesar solicitudes, aplicar reglas de negocio y verificar autorizaciones.

Se organizará en controladores, validadores, servicios, modelos y políticas de autorización.

Las operaciones de aprobación y concesión de permisos deberán usar transacciones para mantener la consistencia de los datos.

### Base de datos — MySQL 8

Almacenará las identidades, departamentos, roles, permisos, recursos, solicitudes, concesiones y eventos de auditoría.

El esquema se administrará mediante migraciones de Laravel y se utilizarán claves foráneas, índices y restricciones de integridad.

## 3. Comunicación

El frontend consumirá endpoints HTTP de Laravel utilizando JSON. Los endpoints protegidos exigirán una sesión válida y la autorización correspondiente.

La base de datos no será accesible directamente desde el frontend.

## 4. Autenticación

Se utilizará Laravel Sanctum mediante autenticación de sesión basada en cookies para la aplicación Angular.

Se aplicarán protecciones CSRF, cookies configuradas de forma segura y HTTPS en entornos publicados.

Las contraseñas se almacenarán con hashing seguro y no se incluirán en registros de auditoría.

## 5. Autorización

YESIAM combinará:

- Roles globales para controlar las funciones administrativas.
- Permisos sobre operaciones específicas.
- Responsables asignados a recursos concretos.
- Concesiones individuales para consultar recursos protegidos.

Las políticas de Laravel verificarán las operaciones sensibles. Se aplicará denegación por defecto.

## 6. Auditoría

Se registrarán eventos relevantes como inicios de sesión, cambios de estado de identidades, asignaciones de roles, aprobaciones, rechazos y revocaciones.

Los registros tendrán información sobre la acción, el actor cuando sea identificable, el momento y la entidad afectada, evitando almacenar secretos.

## 7. Principios Zero Trust

**Verify Explicitly:** validar autenticación, identidad activa y autorización en cada solicitud protegida.

**Use Least Privilege:** conceder únicamente las capacidades necesarias y limitar las acciones al recurso correspondiente.

**Assume Breach:** reducir el alcance de un posible compromiso mediante separación de funciones, revocación de accesos, controles de sesión y auditoría.

## 8. Desarrollo y despliegue

El desarrollo inicial se realizará en Windows con Angular, Laravel y MySQL ejecutándose localmente.

El código se almacenará en un único repositorio GitHub denominado `yesiam`, con carpetas independientes para frontend, backend y documentación.

Las credenciales, contraseñas y variables de entorno sensibles no se almacenarán en el repositorio.

La configuración de producción y el despliegue público se definirán después de completar y probar la primera versión funcional.

## 9. Fuera del alcance inicial

No se implementarán inicialmente múltiples organizaciones, integración con Microsoft Entra ID, aprovisionamiento sobre servicios externos, microservicios, sistemas SIEM completos ni análisis avanzado de amenazas.

Estas capacidades podrán evaluarse en fases posteriores.
