# Changelog

## [Unreleased]

### Added
- Menú móvil de pantalla completa en la tienda, con navegación a páginas comerciales y categorías; al abrirlo, el catálogo queda cubierto.
- Enlace de cierre propio para el menú móvil y navegación de cuenta reutilizable en las páginas comerciales.
- Acceso a la tienda desde el login administrativo.
- Diseño adaptable para la barra lateral del panel, tablas de pedidos, resúmenes del dashboard y formularios de configuración.
- CI de GitHub adaptado al stack activo: comprobación PHP/JS, compilación multipágina, control de archivos del artefacto y migraciones contra MariaDB efímera.

### Changed
- El README conserva el concepto y la paleta original de TechStore como historia, documenta identidad/funciones actuales de SmartISP y propone una modernización incremental compatible con Hostinger.
- Actualizada la prioridad del logo público para usar primero la identidad configurada desde el editor de landing.
- Reorganizados los enlaces de navegación pública y el acceso de cuenta en las páginas de categorías destacadas, productos, servicios y nosotros.
- El README ahora refleja el stack, el arranque local, la compilación Vite y el despliegue PHP/MySQL vigente.
- Actualizadas las instrucciones de publicación de Hostinger y la gestión de secretos fuera del webroot.

### Fixed
- Unificado el rol efectivo del panel con `users_rows.role`, refrescado desde la base en cada petición; se quitaron privilegios por allowlist de correo y los módulos distinguen 401 de 403 sin cerrar sesiones válidas.
- Reparadas métricas del dashboard y pedidos con conteos desde la base, ingresos solo de pagos confirmados, carga independiente/reintentable y serie diaria cuando hay datos.
- El catálogo público excluye credenciales SMTP y claves secretas; el artefacto ya no publica `package.json` y Apache bloquea handlers PHP internos.
- Añadidas pruebas de integración de autenticación, permisos, revocación, endpoints y métricas en MariaDB aislada.
- El modal de detalle de producto ahora queda por encima del menú, la navegación y el drawer del carrito.
- Corregidos objetivos de clic, estados accesibles y apertura/cierre del menú móvil del panel administrativo.
- Ajustes de desbordamiento horizontal en pedidos y otras vistas administrativas estrechas.
- Mantenidas en `main` las correcciones recientes de autenticación por rol, rutas PHP, catálogo y resolución del `.env` fuera de `public_html`.

### Notes
- La compilación no ejecuta migraciones ni altera la base de datos.
- El workflow de GitHub valida código, artefactos y migraciones en una base temporal; el despliegue sigue a cargo de Hostinger.
- `tablasnuevas.md` documenta una propuesta y consultas de Fase 0; las tablas propuestas no se crean ni se consideran desplegadas.
