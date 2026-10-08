# Changelog

## [Unreleased]

### Added
- Menú móvil de pantalla completa en la tienda, con navegación a páginas comerciales y categorías; al abrirlo, el catálogo queda cubierto.
- Enlace de cierre propio para el menú móvil y navegación de cuenta reutilizable en las páginas comerciales.
- Acceso a la tienda desde el login administrativo.
- Diseño adaptable para la barra lateral del panel, tablas de pedidos, resúmenes del dashboard y formularios de configuración.

### Changed
- Actualizada la prioridad del logo público para usar primero la identidad configurada desde el editor de landing.
- Reorganizados los enlaces de navegación pública y el acceso de cuenta en las páginas de categorías destacadas, productos, servicios y nosotros.
- El README ahora refleja el stack, el arranque local, la compilación Vite y el despliegue PHP/MySQL vigente.
- Actualizadas las instrucciones de publicación de Hostinger y la gestión de secretos fuera del webroot.

### Fixed
- El modal de detalle de producto ahora queda por encima del menú, la navegación y el drawer del carrito.
- Corregidos objetivos de clic, estados accesibles y apertura/cierre del menú móvil del panel administrativo.
- Ajustes de desbordamiento horizontal en pedidos y otras vistas administrativas estrechas.
- Mantenidas en `main` las correcciones recientes de autenticación por rol, rutas PHP, catálogo y resolución del `.env` fuera de `public_html`.

### Notes
- La compilación no ejecuta migraciones ni altera la base de datos.
- `tablasnuevas.md` documenta una propuesta y consultas de Fase 0; las tablas propuestas no se crean ni se consideran desplegadas.
