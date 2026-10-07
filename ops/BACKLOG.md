# Backlog

## Now

- [ ] **DEV-20261005-001** `critical` `security` — Rotar credenciales de MySQL y eliminar fallbacks con secretos (SEC-004, SEC-014)
- [ ] **DEV-20261005-002** `critical` `security` — Migrar admin y editores a sesión sin cabecera X-Admin-Email (previo a SEC-001)
- [ ] **DEV-20261005-003** `critical` `security` — Eliminar el bypass de admin por X-Admin-Email del backend (SEC-001)
- [ ] **DEV-20261005-004** `critical` `security` — Eliminar la contraseña universal de administrador (SEC-002)
- [ ] **DEV-20261005-005** `critical` `security` — Mover la clave de firma de tokens a configuración y rotarla (SEC-003)
- [ ] **DEV-20261005-006** `critical` `security` — Migrar contraseñas a password_hash y retirar texto plano, MD5 y SHA1 (SEC-012)
- [ ] **DEV-20261005-007** `critical` `security` — Confirmar y cerrar la exposición de claves SMTP/Resend en /api/auth/catalog (SEC-010)
- [ ] **DEV-20261005-008** `high` `security` — Verificar TLS en correo, Postgres y script de importación (SEC-013)
- [ ] **DEV-20261005-009** `high` `security` — Eliminar el SESSION_SECRET por defecto (SEC-005)
- [ ] **DEV-20261005-010** `medium` `security` — Proteger archivos internos expuestos desde la raíz web (SEC-011)
- [ ] **DEV-20261005-011** `medium` `security` — Deshabilitar o proteger los endpoints de diagnóstico públicos

## Next

- [ ] **DEV-20261005-012** `high` `architecture` — Separar el login del panel admin y unificar la sesión
- [ ] **DEV-20261005-013** `high` `testing` — Pruebas de contrato de la API (catálogo, auth, productos admin, pedidos, contenido)
- [ ] **DEV-20261005-014** `high` `architecture` — Decidir el backend canónico (PHP o Node) y congelar el otro
- [ ] **DEV-20261005-015** `high` `architecture` — Front controller y tabla de rutas con nivel de acceso por ruta
- [ ] **DEV-20261005-016** `medium` `architecture` — Unificar la convención de URL a /api/<recurso> y retirar alias
- [ ] **DEV-20261005-017** `medium` `security` — Límite de intentos y bloqueo temporal en el login (SEC-008)
- [ ] **DEV-20261005-018** `medium` `security` — Lista de dominios permitidos en el proxy de imágenes (SEC-009)
- [ ] **DEV-20261005-019** `high` `feature` — Centro de pedidos en el panel admin
- [ ] **DEV-20261005-020** `high` `feature` — Panel de ventas (ventas por período, ticket promedio, más vendidos, conversión)
- [ ] **DEV-20261005-021** `medium` `feature` — Cotizaciones que se convierten en pedido
- [ ] **DEV-20261005-022** `medium` `performance` — Optimizar el catálogo: filtrar y paginar en SQL, caché y menos columnas
- [ ] **DEV-20261005-032** `medium` `refactor` — Un solo origen de archivos estáticos y despliegue desde public/
- [ ] **DEV-20261005-033** `low` `refactor` — Eliminar código muerto: app.js, styles.css, db.js y scripts sin uso
- [ ] **DEV-20261005-034** `low` `refactor` — Reorganizar imágenes: quitar logos duplicados, reducir peso y fijar convención de carpetas
- [ ] **DEV-20261005-035** `low` `refactor` — Favicons y metadatos: un solo bloque, URLs relativas y conjunto reducido
- [ ] **DEV-20261005-036** `medium` `refactor` — Rutas de páginas limpias y redirecciones en .htaccess
- [ ] **DEV-20261005-037** `high` `refactor` — Reestructurar el panel admin: shell común con navegación y una página por sección
- [ ] **DEV-20261005-038** `high` `feature` — Catálogo en el panel: unificar el editor de catálogo y la gestión de categorías, con mejoras de uso
- [ ] **DEV-20261005-039** `medium` `refactor` — Contenido del sitio en el panel: convertir editor-landing en una sección del panel
- [ ] **DEV-20261005-040** `high` `refactor` — Layout y componentes compartidos para tienda, checkout, login y panel

## Later

- [ ] **DEV-20261005-023** `medium` `architecture` — Fijar el esquema con migraciones numeradas y retirar DESCRIBE en cada petición
- [ ] **DEV-20261005-024** `medium` `architecture` — Dividir el front: extraer JS y CSS inline y crear un cliente de API compartido
- [ ] **DEV-20261005-025** `medium` `feature` — Catálogo: stock, calidad de datos y registro de importaciones
- [ ] **DEV-20261005-026** `medium` `feature` — Flujo de entrega: método, costo por zona, estado y seguimiento
- [ ] **DEV-20261005-027** `medium` `feature` — Cobro en línea: confirmación de pago manual y pasarela local con webhook firmado
- [ ] **DEV-20261005-028** `medium` `feature` — Facturación electrónica a través de un proveedor autorizado
- [ ] **DEV-20261005-029** `low` `feature` — Roles de usuario, registro de actividad y confirmación reforzada de acciones destructivas
- [ ] **DEV-20261005-030** `low` `devops` — Definir comandos build, test y lint en .devflow.yml y agregar CI
- [ ] **DEV-20261005-031** `low` `feature` — Mi pedido para el cliente: estados, seguimiento y recuperación de carritos

- [ ] **DEV-20261005-041** `high` `seo` — Mejorar SEO: robots.txt y sitemaps desactualizados, navegacion a productos

- [ ] **DEV-20261005-042** `low` `devops` — Entorno local (PHP/MariaDB) y documentacion operativa de la sesion

- [ ] **DEV-20261006-001** `medium` `refactor` — Dividir admin.html en dashboard resumen y Configuracion aparte

- [ ] **DEV-20261006-002** `medium` `feature` — Mejorar fichas de producto, paginacion real y filtros en la tienda publica
