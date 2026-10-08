# Changelog

## [Unreleased]

### Added
- Modo de acceso administrativo separado en el login compartido, con retorno a la página solicitada.
- Accion para eliminar pedidos cancelados desde su fila, con confirmacion y proteccion para pedidos con pagos asociados.
- Insights operativos en el dashboard: productos incluidos en pedidos recientes e inventario disponible/reservado cuando existen datos normalizados.
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
- Optimizada la primera carga de la tienda: fuentes no bloqueantes, Lucide diferido, menos peso en la imagen del hero, dimensiones explícitas para imágenes y caché/compresión HTTP para recursos estáticos.
- Reducido el favicon SVG de 823 KB a un envoltorio ligero que reutiliza el PNG pequeño existente.
- El servidor rechaza cuentas de cliente en el acceso administrativo sin crear una sesión; el login de tienda sigue aceptándolas.
- Eliminada la reautenticación incrustada en el editor de catálogo; ahora usa la pantalla de login dedicada.
- El catálogo público pagina y filtra en SQL en vez de cargar todos los productos y recortarlos en PHP.
- La tienda ya no descarga en segundo plano todas las páginas tras mostrar los primeros productos; carga el resto cuando se necesita.
- El Schema.org de producto solo publica ofertas con precio positivo y ya no declara inventario disponible sin datos reales.
- Corregida la ruta de catálogo que omitía el total y descargaba todos los productos; ahora responde con filtros y paginación para que el editor muestre las filas.
- Alineado el encabezado y el contenido del editor con el ancho del sidebar, corregida la jerarquía de capas de navegación/modales y centrado el control de colapso.
- El sidebar administrativo ahora usa el logo configurado para el sitio público.
- Bloqueada la repeticion consecutiva del mismo estado de pedido en la interfaz y en el endpoint administrativo.
- El gráfico del dashboard ahora presenta conteos reales por cada estado existente, sin alternar a una serie diaria con etiquetas de estado incorrectas.
- Evitado que listeners de formularios de otras vistas detengan la inicializacion del dashboard cuando esos elementos no existen en `admin.html`.
- Unificado el rol efectivo del panel con `users_rows.role`, refrescado desde la base en cada petición; se quitaron privilegios por allowlist de correo y los módulos distinguen 401 de 403 sin cerrar sesiones válidas.
- Reparadas métricas del dashboard y pedidos con conteos desde la base, ingresos solo de pagos confirmados, carga independiente/reintentable y serie diaria cuando hay datos.
- El dashboard combina `order_items` con artículos JSON antiguos sin duplicar pedidos, y diferencia inventario sin configurar de stock agotado.
- El catálogo público excluye credenciales SMTP y claves secretas; el artefacto ya no publica `package.json` y Apache bloquea handlers PHP internos.
- Añadidas pruebas de integración de autenticación, permisos, revocación, endpoints y métricas en MariaDB aislada.
- El modal de detalle de producto ahora queda por encima del menú, la navegación y el drawer del carrito.
- Corregidos objetivos de clic, estados accesibles y apertura/cierre del menú móvil del panel administrativo.
- Ajustes de desbordamiento horizontal en pedidos y otras vistas administrativas estrechas.
- Mantenidas en `main` las correcciones recientes de autenticación por rol, rutas PHP, catálogo y resolución del `.env` fuera de `public_html`.

### Notes
- La compilación no ejecuta migraciones ni altera la base de datos.
- El workflow de GitHub valida código, artefactos y migraciones en una base temporal; el despliegue sigue a cargo de Hostinger.
- `docs/007_expandir_base_segura.sql` documenta expansión aditiva manual por fases y queda fuera del runner automático; requiere backfill controlado y adaptación del backend antes de convertirse en fuente de escritura.
