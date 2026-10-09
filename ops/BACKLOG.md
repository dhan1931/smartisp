# Backlog

## Épica: Catálogo público y panel admin — calidad de datos, UX y despliegue listo

StellarCode no tiene todavía un comando de CLI para épicas/sprints como objetos propios
(`stellar-capabilities` reporta `phases: native` del lado del servidor, pero `devflow` aún no
expone un subcomando para crearlos — ver `devflow planificar --help`). Esta agrupación en Sprint 1
y Sprint 2 se documenta aquí mientras tanto; cada tarea ya existe como task real en StellarCode
(ver su `DEV-YYYYMMDD-NNN`).

### Sprint 1 — cerrado 2026-10-06

- [x] **DEV-20261006-001** `medium` `refactor` — Dividir admin.html en dashboard resumen y Configuración aparte
- [x] **DEV-20261006-002** `medium` `feature` — Fichas de producto, paginación real y filtros en la tienda pública (incluye: filtro category/subcategory/sort en SQL, fix O(n²) + debounce, producto.php respeta visible=0, checklist de deploy)
- [x] **DEV-20261006-003** `medium` `bug` — Sidebar de categorías: agrupación prolija en vez de 57 categorías planas
- [x] **DEV-20261006-004** `low` `bug` — Botón "Ver tienda" del navbar ilegible (texto blanco sobre fondo casi blanco)
- [x] **DEV-20261006-005** `high` `devops` — Archivo único de migración manual consolidada + guía paso a paso para Hostinger

### Sprint 2 — por hacer

- [x] **DEV-20261006-006** `medium` `refactor` — Rediseñar el inicio del panel admin: métricas reales, quitar lo redundante
- [x] **DEV-20261006-008** `medium` `feature` — Pantalla dedicada de Macro-Categorías (sacarla del inicio del admin)
- [ ] **DEV-20261006-009** `low` `data` — Calidad de datos: solo 7 de 57 categorías tienen subcategoría real

## Épica: Dashboard tipo SaaS (sidebar, métricas operativas, salud de catálogo)

A partir de un mockup de referencia (captura + propuesta escrita del usuario, 2026-10-06): el
"Inicio" del panel debe responder *"¿cómo está mi tienda, qué pasó hoy, qué necesita mi atención?"*
en vez de ser mayormente enlaces. Se arranca por lo más chico y seguro (navegación), y cada bloque
de datos reales es su propia tarea porque varios requieren backend nuevo, no solo maquetado.

### Sprint 3 — navegación (empezando ahora)

- [ ] **DEV-20261006-010** `high` `refactor` — Sidebar de navegación (reemplazar el navbar horizontal actual)
- [ ] **DEV-20261006-015** `low` `feature` — Búsqueda global (Ctrl+K) de pedidos/productos/clientes

### Sprint 4 — ventas y pedidos reales (requiere backend nuevo)

- [ ] **DEV-20261006-011** `medium` `feature` — Bloque "Necesita tu atención" (alertas clickeables)
- [ ] **DEV-20261006-012** `medium` `feature` — Ventas: gráfica 30 días, ventas hoy/7d/30d/mes, ticket promedio
- [ ] **DEV-20261006-013** `low` `feature` — Pedidos recientes en el dashboard (tabla en vivo)

### Sprint 5 — salud de catálogo

- [ ] **DEV-20261006-014** `medium` `feature` — Salud del catálogo: % completitud, sin imagen/precio/categoría/descripción (se relaciona con DEV-20261006-009)

### Sprint 6 — módulos nuevos sin backend hoy (alcance propio, sin estimar todavía)

- [ ] **DEV-20261006-016** `low` `feature` — Clientes, Clasificación IA, Inventario/Stock, Analítica/Reportes: el mockup los muestra, pero ninguno existe en el backend; cada uno necesita su propio diseño de alcance antes de empezar.

## Épica: Tienda pública y campañas — referencia visual 2026-10-09

Prioridad inmediata: cerrar la vista pública de productos, categorías y campañas. La referencia nueva del
panel queda registrada para después: dashboard claro tipo SaaS, menú lateral por módulos, tarjetas y gráficas
con límites legibles; estadísticas y reportes deben mostrar datos reales y se construirán por separado.

- [ ] **DEV-20261009-043** `high` `feature` — Categorías públicas: imágenes solo en familias destacadas; dentro de una categoría permitir icono o miniatura por subcategoría
- [ ] **DEV-20261009-044** `high` `feature` — Fichas públicas de cuatro columnas con imagen completa, precio, disponibilidad y agregar al carrito; filtros de subcategoría, precio y stock
- [ ] **DEV-20261009-045** `high` `feature` — Editor de campañas: proporción texto/imagen ajustable, preview fiel, transparencia sin recortes y acordeones estables

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

- [x] **DEV-20261006-001** `medium` `refactor` — Dividir admin.html en dashboard resumen y Configuracion aparte

- [x] **DEV-20261006-002** `medium` `feature` — Mejorar fichas de producto, paginacion real y filtros en la tienda publica

- [x] **DEV-20261006-003** `medium` `bug` — Sidebar de categorias: usar agrupacion prolija en vez de 57 categorias planas

- [x] **DEV-20261006-004** `low` `bug` — Boton Ver tienda del navbar: texto blanco sobre fondo casi blanco, ilegible

- [x] **DEV-20261006-005** `high` `devops` — Archivo unico de migracion manual consolidada + guia paso a paso para Hostinger

- [x] **DEV-20261006-006** `medium` `refactor` — Rediseñar el inicio del panel admin: metricas reales, quitar lo redundante

- [x] **DEV-20261006-008** `medium` `feature` — Pantalla dedicada de Macro-Categorias (sacarla del inicio del admin)

- [ ] **DEV-20261006-009** `low` `data` — Calidad de datos: solo 7 de 57 categorias tienen subcategoria real

- [ ] **DEV-20261006-010** `high` `refactor` — Sidebar de navegacion (reemplazar navbar horizontal) para el panel admin

- [ ] **DEV-20261006-011** `medium` `feature` — Bloque 'Necesita tu atencion' en el dashboard

- [ ] **DEV-20261006-012** `medium` `feature` — Ventas: grafica 30 dias, ventas hoy/7d/30d/mes, ticket promedio

- [ ] **DEV-20261006-013** `low` `feature` — Pedidos recientes en el dashboard (tabla en vivo)

- [ ] **DEV-20261006-014** `medium` `feature` — Salud del catalogo: % completitud, productos sin imagen/precio/categoria/descripcion

- [ ] **DEV-20261006-015** `low` `feature` — Busqueda global (Ctrl+K) de pedidos/productos/clientes

- [ ] **DEV-20261006-016** `low` `feature` — Modulos nuevos del mockup sin backend real: Clientes, Clasificacion IA, Inventario/Stock, Analitica/Reportes
