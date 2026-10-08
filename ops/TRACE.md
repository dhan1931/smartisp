# Project Trace

## Snapshot

- Last sync: 2026-10-06T23:12:23-05:00
- Revision: 9000a80
- Overall status: working-tree-dirty

## Pending

## Blocked

## Recently completed

## Validation

## Risks

## Next actions

### DEV-20261005-001 — Rotar credenciales de MySQL y eliminar fallbacks con secretos (SEC-004, SEC-014)

- Status: `planned`
- Type: `security`
- Priority: `critical`
- Started: 2026-10-05T14:51:34-05:00
- Updated: 2026-10-05T14:51:34-05:00

#### Goal

La contraseña real de MySQL está como valor por defecto en api/config.php y src/lib/mysqlClient.js y queda en el historial de git. Rotarla en Hostinger, leerla solo de variables de entorno o config local fuera de git y dejar config.example.php.

StellarCode task id: 74

#### Acceptance criteria

- Contraseña rotada en Hostinger; sin credenciales literales en el repo; devflow secretos y devflow auditar sin hallazgos de secret_env_fallback

#### Evidence

- Pending

### DEV-20261005-002 — Migrar admin y editores a sesión sin cabecera X-Admin-Email (previo a SEC-001)

- Status: `planned`
- Type: `security`
- Priority: `critical`
- Started: 2026-10-05T14:52:32-05:00
- Updated: 2026-10-05T14:52:32-05:00

#### Goal

El front admin.html, editor-catalogo.html y tienda.html envía X-Admin-Email en cada petición y admin.html la rellena con el admin por defecto aunque no haya sesión. Hay que quitar esa dependencia antes de cerrar el bypass del backend, o los administradores quedan fuera del panel.

StellarCode task id: 75

#### Acceptance criteria

- Ninguna página envía cabeceras de identidad; el panel funciona solo con la sesión; devflow auditar sin front_sends_identity_header

#### Evidence

- Pending

### DEV-20261005-003 — Eliminar el bypass de admin por X-Admin-Email del backend (SEC-001)

- Status: `planned`
- Type: `security`
- Priority: `critical`
- Started: 2026-10-05T14:52:37-05:00
- Updated: 2026-10-05T14:52:37-05:00

#### Goal

api/router.php:133-171 concede sesión de administrador a quien envíe X-Admin-Email o ?admin_email con un email de la lista admin, sin credenciales. Depende de la tarea de migración del front.

StellarCode task id: 76

#### Acceptance criteria

- Una petición con solo X-Admin-Email recibe 403; devflow auditar sin auth_from_client_header

#### Evidence

- Pending

### DEV-20261005-004 — Eliminar la contraseña universal de administrador (SEC-002)

- Status: `planned`
- Type: `security`
- Priority: `critical`
- Started: 2026-10-05T14:52:41-05:00
- Updated: 2026-10-05T14:52:41-05:00

#### Goal

api/router.php:1650 y 2083 aceptan una contraseña fija para los emails admin en login y change-password.

StellarCode task id: 77

#### Acceptance criteria

- Sin comparaciones contra literales de contraseña; devflow auditar sin hardcoded_credential_check

#### Evidence

- Pending

### DEV-20261005-005 — Mover la clave de firma de tokens a configuración y rotarla (SEC-003)

- Status: `planned`
- Type: `security`
- Priority: `critical`
- Started: 2026-10-05T14:52:45-05:00
- Updated: 2026-10-05T14:52:45-05:00

#### Goal

La clave HMAC de los tokens admin está escrita en api/router.php:65 y :109; permite forjar tokens de 30 días. Moverla a config local fuera de git, rotarla y reducir la vigencia del token.

StellarCode task id: 78

#### Acceptance criteria

- Clave leída de configuración local; tokens anteriores invalidados; devflow auditar sin hardcoded_signing_key

#### Evidence

- Pending

### DEV-20261005-006 — Migrar contraseñas a password_hash y retirar texto plano, MD5 y SHA1 (SEC-012)

- Status: `planned`
- Type: `security`
- Priority: `critical`
- Started: 2026-10-05T14:52:50-05:00
- Updated: 2026-10-05T14:52:50-05:00

#### Goal

El login en api/router.php:1676-1680 acepta contraseña en texto plano, MD5 y SHA1: las contraseñas de usuarios probablemente están sin hash seguro. Rehash en el siguiente login exitoso y migración por lotes.

StellarCode task id: 79

#### Acceptance criteria

- Solo password_verify; ninguna comparación directa; devflow auditar sin plaintext_password_compare ni weak_password_hash

#### Evidence

- Pending

### DEV-20261005-007 — Confirmar y cerrar la exposición de claves SMTP/Resend en /api/auth/catalog (SEC-010)

- Status: `planned`
- Type: `security`
- Priority: `critical`
- Started: 2026-10-05T14:52:53-05:00
- Updated: 2026-10-05T14:52:53-05:00

#### Goal

El endpoint público catalog devuelve filas de settings_rows sin excluir smtp_pass, smtp_user ni resend_api_key (landing-content sí las filtra). Confirmar con una petición de solo lectura, corregir con lista de claves permitidas, y rotar las credenciales de correo si estuvieron expuestas.

StellarCode task id: 80

#### Acceptance criteria

- catalog solo devuelve claves en lista blanca; credenciales de correo rotadas si hubo exposición

#### Evidence

- Pending

### DEV-20261005-008 — Verificar TLS en correo, Postgres y script de importación (SEC-013)

- Status: `planned`
- Type: `security`
- Priority: `high`
- Started: 2026-10-05T14:52:57-05:00
- Updated: 2026-10-05T14:52:57-05:00

#### Goal

CURLOPT_SSL_VERIFYPEER false en api/mailer.php:270 y rejectUnauthorized:false en server.js:85 y scripts/import-intcomex-products.js:95.

StellarCode task id: 81

#### Acceptance criteria

- Verificación de certificado activa; devflow auditar sin tls_verification_disabled

#### Evidence

- Pending

### DEV-20261005-009 — Eliminar el SESSION_SECRET por defecto (SEC-005)

- Status: `planned`
- Type: `security`
- Priority: `high`
- Started: 2026-10-05T14:53:00-05:00
- Updated: 2026-10-05T14:53:00-05:00

#### Goal

server.js:157 usa un valor por defecto público si falta la variable de entorno. Debe fallar al arrancar si no está configurada.

StellarCode task id: 82

#### Acceptance criteria

- El servidor no arranca sin SESSION_SECRET

#### Evidence

- Pending

### DEV-20261005-010 — Proteger archivos internos expuestos desde la raíz web (SEC-011)

- Status: `planned`
- Type: `security`
- Priority: `medium`
- Started: 2026-10-05T14:53:04-05:00
- Updated: 2026-10-05T14:53:04-05:00

#### Goal

El .htaccess solo bloquea .env y api/config.php; server.js, package.json, .devflow.yml, ops/ y docs/ serían descargables si el sitio se sirve desde la raíz. Confirmar en el servidor y bloquear o mover fuera de la raíz.

StellarCode task id: 83

#### Acceptance criteria

- Esos archivos devuelven 403/404 en producción

#### Evidence

- Pending

### DEV-20261005-011 — Deshabilitar o proteger los endpoints de diagnóstico públicos

- Status: `planned`
- Type: `security`
- Priority: `medium`
- Started: 2026-10-05T14:53:08-05:00
- Updated: 2026-10-05T14:53:08-05:00

#### Goal

test-db, test-products, test-supabase y test-email están expuestos con la API de producción.

StellarCode task id: 84

#### Acceptance criteria

- Los endpoints test-* requieren admin o no existen en producción

#### Evidence

- Pending

### DEV-20261005-012 — Separar el login del panel admin y unificar la sesión

- Status: `planned`
- Type: `architecture`
- Priority: `high`
- Started: 2026-10-05T14:53:11-05:00
- Updated: 2026-10-05T14:53:11-05:00

#### Goal

admin.html (3516 líneas) incluye su propio login, la pantalla de acceso denegado y el panel; tienda.html tiene otro login. Crear login.html único, session.js contra /api/auth/me, cookie HttpOnly y rol decidido por el backend. Detalle en docs/ARQUITECTURA-Y-DEUDA.md sección 4b.

StellarCode task id: 85

#### Acceptance criteria

- Un solo login; sin emails admin en los HTML; sin token en localStorage; monolithic_page de admin.html resuelto

#### Evidence

- Pending

### DEV-20261005-013 — Pruebas de contrato de la API (catálogo, auth, productos admin, pedidos, contenido)

- Status: `planned`
- Type: `testing`
- Priority: `high`
- Started: 2026-10-05T14:53:15-05:00
- Updated: 2026-10-05T14:53:15-05:00

#### Goal

No hay ningún archivo de prueba. Fijar el comportamiento actual de cada endpoint, incluyendo que los endpoints admin devuelvan 403 sin credenciales, antes de refactorizar.

StellarCode task id: 86

#### Acceptance criteria

- Una prueba por endpoint público y por endpoint admin; ejecutable con un comando

#### Evidence

- Pending

### DEV-20261005-014 — Decidir el backend canónico (PHP o Node) y congelar el otro

- Status: `planned`
- Type: `architecture`
- Priority: `high`
- Started: 2026-10-05T14:53:19-05:00
- Updated: 2026-10-05T14:53:19-05:00

#### Goal

server.js (Express, 34 rutas, Supabase/Postgres) y api/router.php (MySQL) implementan casi la misma API. Recomendación: PHP, que es lo que sirve Hostinger. Mover server.js a legacy/ o eliminarlo y retirar dependencias no usadas.

StellarCode task id: 87

#### Acceptance criteria

- Cada ruta tiene una sola implementación

#### Evidence

- Pending

### DEV-20261005-015 — Front controller y tabla de rutas con nivel de acceso por ruta

- Status: `planned`
- Type: `architecture`
- Priority: `high`
- Started: 2026-10-05T14:53:24-05:00
- Updated: 2026-10-05T14:53:24-05:00

#### Goal

Reemplazar los ~40 bloques if ($action ===) de router.php por public/index.php, un Router y routes.php donde cada ruta declara public, customer o admin. Migrar bloque a bloque empezando por catalog y categories.

StellarCode task id: 88

#### Acceptance criteria

- router.php eliminado; ninguna ruta sin nivel declarado; ningún archivo de más de ~400 líneas

#### Evidence

- Pending

### DEV-20261005-016 — Unificar la convención de URL a /api/<recurso> y retirar alias

- Status: `planned`
- Type: `architecture`
- Priority: `medium`
- Started: 2026-10-05T14:53:28-05:00
- Updated: 2026-10-05T14:53:28-05:00

#### Goal

El front usa /api/router?action= (12 veces) y /api/auth/<accion>; hay alias duplicados (content/items, landing-content/site-content, product-image/proxy-image). Los recursos de negocio no deben colgar de /api/auth, y las imágenes no deben servirse desde un endpoint auth.

StellarCode task id: 89

#### Acceptance criteria

- Una convención; alias antiguos redirigen y luego se retiran; devflow auditar sin endpoint_namespace_misuse

#### Evidence

- Pending

### DEV-20261005-017 — Límite de intentos y bloqueo temporal en el login (SEC-008)

- Status: `planned`
- Type: `security`
- Priority: `medium`
- Started: 2026-10-05T14:53:32-05:00
- Updated: 2026-10-05T14:53:32-05:00

#### Goal

El login no limita intentos y es vulnerable a fuerza bruta.

StellarCode task id: 90

#### Acceptance criteria

- Bloqueo temporal tras N intentos fallidos; devflow auditar sin login_without_rate_limit

#### Evidence

- Pending

### DEV-20261005-018 — Lista de dominios permitidos en el proxy de imágenes (SEC-009)

- Status: `planned`
- Type: `security`
- Priority: `medium`
- Started: 2026-10-05T14:53:36-05:00
- Updated: 2026-10-05T14:53:36-05:00

#### Goal

El proxy product-image/proxy-image descarga cualquier URL recibida por token o url (SSRF).

StellarCode task id: 91

#### Acceptance criteria

- Solo dominios permitidos; rangos privados bloqueados; devflow auditar sin ssrf_user_url

#### Evidence

- Pending

### DEV-20261005-019 — Centro de pedidos en el panel admin

- Status: `planned`
- Type: `feature`
- Priority: `high`
- Started: 2026-10-05T14:53:40-05:00
- Updated: 2026-10-05T14:53:40-05:00

#### Goal

Hoy no existe pantalla de pedidos: el administrador se entera por correo. Lista con filtros, detalle, cambio de estado con correo al cliente, notas internas e historial en order_events. Flujo: cotización, pedido, pagado, preparando, enviado o retiro, entregado, cancelado. Ver docs/PROPUESTAS-DE-VALOR.md P1.

StellarCode task id: 92

#### Acceptance criteria

- Un administrador ve, filtra y cambia el estado de cualquier pedido; cada cambio queda registrado

#### Evidence

- Pending

### DEV-20261005-020 — Panel de ventas (ventas por período, ticket promedio, más vendidos, conversión)

- Status: `planned`
- Type: `feature`
- Priority: `high`
- Started: 2026-10-05T14:53:44-05:00
- Updated: 2026-10-05T14:53:44-05:00

#### Goal

Responde cuánto se vende: ventas del día, semana y mes con comparación, pedidos, ticket promedio, cotizaciones y su conversión, productos y categorías más vendidos, ventas por ciudad, tiempo hasta la entrega. Depende del centro de pedidos.

StellarCode task id: 93

#### Acceptance criteria

- El panel muestra esos indicadores a partir de orders_rows y order_events

#### Evidence

- Pending

### DEV-20261005-021 — Cotizaciones que se convierten en pedido

- Status: `planned`
- Type: `feature`
- Priority: `medium`
- Started: 2026-10-05T14:53:47-05:00
- Updated: 2026-10-05T14:53:47-05:00

#### Goal

El sistema distingue pedido de cotización pero no hay forma de responderla. El administrador cotiza con precio y vigencia; el cliente acepta con un enlace y pasa a pedido. Medir conversión.

StellarCode task id: 94

#### Acceptance criteria

- Una cotización respondida y aceptada se convierte en pedido sin intervención manual en la base

#### Evidence

- Pending

### DEV-20261005-022 — Optimizar el catálogo: filtrar y paginar en SQL, caché y menos columnas

- Status: `planned`
- Type: `performance`
- Priority: `medium`
- Started: 2026-10-05T14:53:51-05:00
- Updated: 2026-10-05T14:53:51-05:00

#### Goal

/api/auth/catalog hace SELECT * de toda la tabla y filtra y pagina en PHP; toda la API responde no-store. Filtrar visible y paginar con LIMIT/OFFSET, pedir columnas necesarias, agregar Cache-Control corto en lecturas públicas y revisar índices.

StellarCode task id: 95

#### Acceptance criteria

- El catálogo no carga la tabla completa; tiempos medidos antes y después en staging

#### Evidence

- Pending

### DEV-20261005-023 — Fijar el esquema con migraciones numeradas y retirar DESCRIBE en cada petición

- Status: `planned`
- Type: `architecture`
- Priority: `medium`
- Started: 2026-10-05T14:53:55-05:00
- Updated: 2026-10-05T14:53:55-05:00

#### Goal

router.php y db.php descubren columnas con DESCRIBE/SHOW TABLES y nombres como COL 2 o col_3; ya causó un error 500 en login. No existe el script de la base real en el repo.

StellarCode task id: 96

#### Acceptance criteria

- Migraciones versionadas; sin DESCRIBE en tiempo de petición; esquema reproducible

#### Evidence

- Pending

### DEV-20261005-024 — Dividir el front: extraer JS y CSS inline y crear un cliente de API compartido

- Status: `planned`
- Type: `architecture`
- Priority: `medium`
- Started: 2026-10-05T14:53:58-05:00
- Updated: 2026-10-05T14:53:58-05:00

#### Goal

admin.html 3516, editor-catalogo.html 3674, editor-landing.html 3930 y tienda.html 3549 líneas con JS y CSS inline y tres clientes de API distintos.

StellarCode task id: 97

#### Acceptance criteria

- Ningún HTML supera ~800 líneas; un api.js compartido; devflow auditar sin monolithic_page ni duplicated_api_client

#### Evidence

- Pending

### DEV-20261005-025 — Catálogo: stock, calidad de datos y registro de importaciones

- Status: `planned`
- Type: `feature`
- Priority: `medium`
- Started: 2026-10-05T15:12:05-05:00
- Updated: 2026-10-05T15:12:05-05:00

#### Goal

Stock con alerta y reserva, costo, marca, descuento con vigencia, IVA, estado del producto, pantalla de importaciones con errores, edición masiva y lista de productos sin imagen, sin precio o con SKU duplicado.

#### Acceptance criteria

- No se vende lo que no hay; cada importación queda registrada y revisable

#### Evidence

- Pending

### DEV-20261005-026 — Flujo de entrega: método, costo por zona, estado y seguimiento

- Status: `planned`
- Type: `feature`
- Priority: `medium`
- Started: 2026-10-05T15:12:17-05:00
- Updated: 2026-10-05T15:12:17-05:00

#### Goal

Retiro, mensajería local o transporte nacional; costo de envío por ciudad en el checkout; estado visible para el cliente y número de guía; correo en cada cambio.

StellarCode task id: 100

#### Acceptance criteria

- El cliente ve el costo antes de confirmar y el estado de su entrega

#### Evidence

- Pending

### DEV-20261005-027 — Cobro en línea: confirmación de pago manual y pasarela local con webhook firmado

- Status: `planned`
- Type: `feature`
- Priority: `medium`
- Started: 2026-10-05T15:12:29-05:00
- Updated: 2026-10-05T15:12:29-05:00

#### Goal

Primero confirmar pagos desde el panel con comprobante; después una pasarela local a definir con el negocio, confirmada por webhook firmado y registrada en una tabla payments. Requiere seguridad al día.

StellarCode task id: 101

#### Acceptance criteria

- Un pago queda conciliado con su pedido sin intervención en la base

#### Evidence

- Pending

### DEV-20261005-028 — Facturación electrónica a través de un proveedor autorizado

- Status: `planned`
- Type: `feature`
- Priority: `medium`
- Started: 2026-10-05T15:12:40-05:00
- Updated: 2026-10-05T15:12:40-05:00

#### Goal

Datos fiscales en el checkout, emisión al pagar y guardado de número, clave de acceso y PDF. Requiere definir emisor y proveedor con el negocio.

StellarCode task id: 102

#### Acceptance criteria

- Cada pedido pagado genera su factura y el cliente la recibe

#### Evidence

- Pending

### DEV-20261005-029 — Roles de usuario, registro de actividad y confirmación reforzada de acciones destructivas

- Status: `planned`
- Type: `feature`
- Priority: `low`
- Started: 2026-10-05T15:12:52-05:00
- Updated: 2026-10-05T15:12:52-05:00

#### Goal

Administrador, ventas y catálogo con permisos por sección; auditoría de quién cambió qué; respaldo exportable antes de Eliminar todos los productos.

StellarCode task id: 103

#### Acceptance criteria

- Permisos por rol y registro de cambios activos

#### Evidence

- Pending

### DEV-20261005-030 — Definir comandos build, test y lint en .devflow.yml y agregar CI

- Status: `planned`
- Type: `devops`
- Priority: `low`
- Started: 2026-10-05T15:13:03-05:00
- Updated: 2026-10-05T15:13:03-05:00

#### Goal

.devflow.yml no tiene comandos, así que DevFlow solo lee. Agregar build, test, lint y security_scan, más un flujo de CI y respaldos documentados.

StellarCode task id: 104

#### Acceptance criteria

- devflow ejecuta los comandos y la confianza de evidencia sube

#### Evidence

- Pending

### DEV-20261005-031 — Mi pedido para el cliente: estados, seguimiento y recuperación de carritos

- Status: `planned`
- Type: `feature`
- Priority: `low`
- Started: 2026-10-05T15:13:15-05:00
- Updated: 2026-10-05T15:13:15-05:00

#### Goal

Historial de pedidos con estado y descarga de factura, correos transaccionales coherentes, recuperación de carritos abandonados y aviso de lista de deseos.

StellarCode task id: 105

#### Acceptance criteria

- El cliente ve el estado de sus pedidos sin contactar a un asesor

#### Evidence

- Pending

### DEV-20261005-032 — Un solo origen de archivos estáticos y despliegue desde public/

- Status: `planned`
- Type: `refactor`
- Priority: `medium`
- Started: 2026-10-05T17:02:58-05:00
- Updated: 2026-10-05T17:02:58-05:00

#### Goal

18 de 19 archivos están duplicados entre la raíz y public/ (favicons, manifest, robots, sitemaps, producto.php) y sitemap-main.xml difiere entre ambas copias. vite.config.js copia archivos a mano y Hostinger sirve la raíz. Dejar una sola copia en public/, resolver cuál sitemap-main.xml es el vigente, ajustar el build y documentar un único método de despliegue (raíz web = public/).

StellarCode task id: 107

#### Acceptance criteria

- Una sola copia de cada archivo estático; sitemap correcto; despliegue documentado y verificado: favicons, robots y sitemaps cargan en producción tras desplegar

#### Evidence

- Pending

### DEV-20261005-033 — Eliminar código muerto: app.js, styles.css, db.js y scripts sin uso

- Status: `planned`
- Type: `refactor`
- Priority: `low`
- Started: 2026-10-05T17:03:10-05:00
- Updated: 2026-10-05T17:03:10-05:00

#### Goal

app.js (663 líneas) y styles.css no los referencia ningún HTML; db.js solo se copia en el build y nada lo importa. Confirmar que no se usan, borrarlos y retirarlos de vite.config.js.

StellarCode task id: 108

#### Acceptance criteria

- Archivos eliminados; build y sitio sin cambios; devflow auditar sin los archivos en el árbol

#### Evidence

- Pending

### DEV-20261005-034 — Reorganizar imágenes: quitar logos duplicados, reducir peso y fijar convención de carpetas

- Status: `planned`
- Type: `refactor`
- Priority: `low`
- Started: 2026-10-05T17:03:21-05:00
- Updated: 2026-10-05T17:03:21-05:00

#### Goal

public/logo.png y public/images/smartisp-logo-master.png son idénticos, igual que logo-circle.png y smartisp-logo-circle.png (617 KB y 237 KB repetidos). Dejar una copia de cada logo, optimizar el peso (webp o svg donde aplique), definir assets/img y actualizar las referencias.

StellarCode task id: 109

#### Acceptance criteria

- Sin imágenes duplicadas; logos más livianos; todas las referencias actualizadas y verificadas en las páginas

#### Evidence

- Pending

### DEV-20261005-035 — Favicons y metadatos: un solo bloque, URLs relativas y conjunto reducido

- Status: `planned`
- Type: `refactor`
- Priority: `low`
- Started: 2026-10-05T17:03:33-05:00
- Updated: 2026-10-05T17:03:33-05:00

#### Goal

El bloque de favicons y meta (10 líneas) está repetido en 7 HTML con URLs absolutas a smart-isp.com.ec, lo que impide probar en local o staging. Unificarlo en un solo lugar, usar rutas relativas y reducir los tamaños al conjunto necesario.

StellarCode task id: 110

#### Acceptance criteria

- Un único bloque fuente; sin dominio de producción escrito en los HTML; favicons correctos en todas las páginas

#### Evidence

- Pending

### DEV-20261005-036 — Rutas de páginas limpias y redirecciones en .htaccess

- Status: `planned`
- Type: `refactor`
- Priority: `medium`
- Started: 2026-10-05T17:03:44-05:00
- Updated: 2026-10-05T17:03:44-05:00

#### Goal

Hoy las páginas se sirven como /tienda.html, /admin.html, /checkout.html. Definir URLs limpias (/tienda, /admin, /login, /checkout) con redirecciones 301 desde las antiguas, una página 404 propia y canónicas; documentar el mapa de rutas del sitio.

StellarCode task id: 111

#### Acceptance criteria

- Las URLs antiguas redirigen a las nuevas sin romper enlaces ni sitemaps; 404 propia; mapa de rutas documentado

#### Evidence

- Pending

### DEV-20261005-037 — Reestructurar el panel admin: shell común con navegación y una página por sección

- Status: `planned`
- Type: `refactor`
- Priority: `high`
- Started: 2026-10-05T17:03:57-05:00
- Updated: 2026-10-05T17:03:57-05:00

#### Goal

admin.html (3516 líneas) mezcla pantallas y secciones en un solo archivo. Crear un shell con navegación lateral y secciones independientes (Inicio, Catálogo, Categorías, Contenido, Pedidos, Correo y configuración), cada una en su archivo y con su módulo JS, sobre el login separado y el api.js de DEV-012. Estimado 16 h.

StellarCode task id: 112

#### Acceptance criteria

- El panel carga por secciones desde un shell común; ninguna sección supera ~800 líneas; navegación y permisos por sección; sin cabeceras ni estilos duplicados

#### Evidence

- Pending

### DEV-20261005-038 — Catálogo en el panel: unificar el editor de catálogo y la gestión de categorías, con mejoras de uso

- Status: `planned`
- Type: `feature`
- Priority: `high`
- Started: 2026-10-05T17:04:09-05:00
- Updated: 2026-10-05T17:04:09-05:00

#### Goal

editor-catalogo.html (3674 líneas) y la sección de macro-categorías de admin.html gestionan lo mismo por separado. Unificarlos en una sección Catálogo del panel: lista con búsqueda y filtros, edición rápida, edición masiva básica (precio, categoría y visibilidad con vista previa), gestión y reasignación de categorías y subcategorías y búsqueda de imágenes integrada. Estimado 14 h.

StellarCode task id: 113

#### Acceptance criteria

- Un solo lugar para gestionar productos y categorías; edición masiva con vista previa; sin código duplicado entre páginas

#### Evidence

- Pending

### DEV-20261005-039 — Contenido del sitio en el panel: convertir editor-landing en una sección del panel

- Status: `planned`
- Type: `refactor`
- Priority: `medium`
- Started: 2026-10-05T17:04:20-05:00
- Updated: 2026-10-05T17:04:20-05:00

#### Goal

editor-landing.html (3930 líneas) pasa a ser la sección Contenido del panel: apariencia, visibilidad de secciones y textos de la portada y la tienda, con vista previa. Estimado 8 h.

StellarCode task id: 114

#### Acceptance criteria

- El contenido se edita desde el panel sobre el shell común; vista previa funcional; editor-landing.html retirado

#### Evidence

- Pending

### DEV-20261005-040 — Layout y componentes compartidos para tienda, checkout, login y panel

- Status: `planned`
- Type: `refactor`
- Priority: `high`
- Started: 2026-10-05T17:04:31-05:00
- Updated: 2026-10-05T17:04:31-05:00

#### Goal

Cada página repite cabecera, navegación, pie, tokens de estilo, botones y modales. Crear assets/css (tokens y componentes) y assets/js (cabecera, modal, avisos, formularios) que usen todas las páginas, junto con el cliente de API y la sesión comunes. Estimado 10 h.

StellarCode task id: 115

#### Acceptance criteria

- Cabecera, pie y estilos base definidos en un solo lugar; cambiar un color o un enlace se hace en un único archivo

#### Evidence

- Pending

### DEV-20261005-041 — Mejorar SEO: robots.txt y sitemaps desactualizados, navegacion a productos

- Status: `planned`
- Type: `seo`
- Priority: `high`
- Started: 2026-10-05T22:16:39-05:00
- Updated: 2026-10-05T22:16:39-05:00

#### Goal

robots.txt referencia rutas de favicon que ya no existen (se movieron a assets/favicons/ en DEV-20261005-032/035); sin Disallow para admin/checkout/login/api; sitemap-main.xml con fechas fijas desactualizadas y checkout.html indexable (no deberia). Revisar tambien si los enlaces de producto en tienda.html son consistentes con el esquema real de URL (prod_<id>-<slug>) usado por producto.php y sitemap-products.php.

StellarCode task id: 117

#### Acceptance criteria

- robots.txt y sitemaps coherentes con las rutas reales; enlaces de producto verificados contra producto.php

#### Evidence

- Pending

### DEV-20261005-042 — Entorno local (PHP/MariaDB) y documentacion operativa de la sesion

- Status: `planned`
- Type: `devops`
- Priority: `low`
- Started: 2026-10-05T22:25:04-05:00
- Updated: 2026-10-05T22:25:04-05:00

#### Goal

Instalacion de PHP 8.3 y MariaDB 11.8 portatiles, importacion de la copia de datos reales, scripts dev-php.ps1/dev-router.php, y los documentos DIAGNOSTICO/ARQUITECTURA-Y-DEUDA/PLAN-DE-FASES/PROPUESTAS-DE-VALOR/FLUJO-DEVFLOW. Commits: 48f7d6e, 73a578d, 6829d39.

StellarCode task id: 118

#### Acceptance criteria

- Entorno local funcional y documentado

#### Evidence

- Pending

### DEV-20261006-001 — Dividir admin.html en dashboard resumen y Configuracion aparte

- Status: `planned`
- Type: `refactor`
- Priority: `medium`
- Started: 2026-10-06T05:52:15-05:00
- Updated: 2026-10-06T05:52:15-05:00

#### Goal

Dividir admin.html en dashboard resumen y Configuracion aparte

StellarCode task id: 119

#### Acceptance criteria

- TODO: definir criterios verificables

#### Evidence

- Pending

### DEV-20261006-002 — Mejorar fichas de producto, paginacion real y filtros en la tienda publica

- Status: `planned`
- Type: `feature`
- Priority: `medium`
- Started: 2026-10-06T22:27:22-05:00
- Updated: 2026-10-06T22:27:22-05:00

#### Goal

Mejorar fichas de producto, paginacion real y filtros en la tienda publica

StellarCode task id: 120

#### Acceptance criteria

- TODO: definir criterios verificables

#### Evidence

- Pending

### DEV-20261006-003 — Sidebar de categorias: usar agrupacion prolija en vez de 57 categorias planas

- Status: `planned`
- Type: `bug`
- Priority: `medium`
- Started: 2026-10-06T22:51:33-05:00
- Updated: 2026-10-06T22:51:33-05:00

#### Goal

hasCustomHierarchy confiaba en la jerarquia real con que UNA sola categoria tuviera subcategoria; con 7/57 (~12%) calificando, el sidebar mostraba 57 entradas sueltas en vez de los 5 grupos prolijos ya definidos como respaldo. Umbral subido a 30%.

StellarCode task id: 121

#### Acceptance criteria

- TODO: definir criterios verificables

#### Evidence

- Pending

### DEV-20261006-004 — Boton Ver tienda del navbar: texto blanco sobre fondo casi blanco, ilegible

- Status: `planned`
- Type: `bug`
- Priority: `low`
- Started: 2026-10-06T22:51:38-05:00
- Updated: 2026-10-06T22:51:38-05:00

#### Goal

Reutilizaba class=button secondary, que cada pagina redefine con background casi blanco. Se le dio clase propia (admin-nav-storelink) para que no dependa del CSS de la pagina host.

StellarCode task id: 122

#### Acceptance criteria

- TODO: definir criterios verificables

#### Evidence

- Pending

### DEV-20261006-005 — Archivo unico de migracion manual consolidada + guia paso a paso para Hostinger

- Status: `planned`
- Type: `devops`
- Priority: `high`
- Started: 2026-10-06T22:52:08-05:00
- Updated: 2026-10-06T22:52:08-05:00

#### Goal

Un solo .sql con todos los cambios de esquema desde el dump original (u606699314_smart_isp.sql) hasta hoy, para aplicar a mano si hace falta sin correr migrate.php; mas una guia paso a paso del flujo completo de despliegue. Las 6 migraciones versionadas (migrations/001-006) se mantienen igual, esto es un documento/archivo adicional, no un reemplazo.

StellarCode task id: 123

#### Acceptance criteria

- TODO: definir criterios verificables

#### Evidence

- Pending

### DEV-20261006-006 — Rediseñar el inicio del panel admin: metricas reales, quitar lo redundante

- Status: `planned`
- Type: `refactor`
- Priority: `medium`
- Started: 2026-10-06T22:52:22-05:00
- Updated: 2026-10-06T22:52:22-05:00

#### Goal

El dashboard de admin.html hoy es mayormente tarjetas de enlace sin informacion real. Quitar botones/descripciones que ya se sienten innecesarios (texto largo repetido en cada tarjeta), agregar metricas reales adicionales (mas alla de pedidos: catalogo, actividad reciente), y que el inicio 'diga algo' en vez de solo enlazar a otras pantallas. Parte del mismo trabajo: DEV-20261006-007 (pantalla dedicada de macro-categorias) libera espacio en este dashboard.

StellarCode task id: 124

#### Acceptance criteria

- TODO: definir criterios verificables

#### Evidence

- Pending

### DEV-20261006-008 — Pantalla dedicada de Macro-Categorias (sacarla del inicio del admin)

- Status: `planned`
- Type: `feature`
- Priority: `medium`
- Started: 2026-10-06T22:52:25-05:00
- Updated: 2026-10-06T22:52:25-05:00

#### Goal

El grid de Macro-Categorias Activas vive hoy dentro de admin.html (dashboard de inicio), pesado visualmente y mezclado con enlaces. Darle su propia pantalla/ruta en el panel (ej. categorias.html o seccion dedicada), navegable desde el navbar compartido, dejando el inicio mas liviano. Depende de / se relaciona con DEV-20261006-006 (rediseño del inicio).

StellarCode task id: 125

#### Acceptance criteria

- TODO: definir criterios verificables

#### Evidence

- Pending

### DEV-20261006-009 — Calidad de datos: solo 7 de 57 categorias tienen subcategoria real

- Status: `planned`
- Type: `data`
- Priority: `low`
- Started: 2026-10-06T22:52:29-05:00
- Updated: 2026-10-06T22:52:29-05:00

#### Goal

DEV-20261006-003 arreglo el sintoma en tienda.html (umbral de confianza en la jerarquia), pero la causa de fondo sigue: de 57 categorias reales en products_rows, solo 7 (~12%) tienen alguna subcategoria asignada -- probablemente productos importados del feed del proveedor sin categorizar a fondo. Revisar el proceso de importacion/categorizacion y decidir si vale la pena re-categorizar el catalogo existente.

StellarCode task id: 126

#### Acceptance criteria

- TODO: definir criterios verificables

#### Evidence

- Pending

### DEV-20261006-010 — Sidebar de navegacion (reemplazar navbar horizontal) para el panel admin

- Status: `planned`
- Type: `refactor`
- Priority: `high`
- Started: 2026-10-06T23:11:31-05:00
- Updated: 2026-10-06T23:11:31-05:00

#### Goal

Mockup de referencia: sidebar fijo a la izquierda, agrupado por secciones (Operacion, Catalogo, Tienda, Analisis, Sistema) en vez del navbar horizontal actual de 1 fila. Primer paso de la epica de rediseno de dashboard tipo SaaS. Solo enlaza a paginas que ya existen (Inicio, Pedidos, Catalogo, Categorias, Contenido, Configuracion); los modulos que no existen (Clientes, Clasificacion IA, Inventario, Estadisticas, Reportes) quedan como 'Proximamente' deshabilitados, no como links muertos.

StellarCode task id: 127

#### Acceptance criteria

- TODO: definir criterios verificables

#### Evidence

- Pending

### DEV-20261006-011 — Bloque 'Necesita tu atencion' en el dashboard

- Status: `planned`
- Type: `feature`
- Priority: `medium`
- Started: 2026-10-06T23:11:37-05:00
- Updated: 2026-10-06T23:11:37-05:00

#### Goal

Lista clickeable de alertas operativas: pedidos pendientes de pago (con monto), productos sin imagen, productos sin categoria, pedidos por despachar. Convierte el dashboard de 'te muestro numeros' a 'te digo que resolver'. Depende de datos que ya existen (admin-orders-stats, admin-products-stats) salvo 'stock bajo' que requiere DEV-20261005-025 (stock aun no existe como campo real).

StellarCode task id: 128

#### Acceptance criteria

- TODO: definir criterios verificables

#### Evidence

- Pending

### DEV-20261006-012 — Ventas: grafica 30 dias, ventas hoy/7d/30d/mes, ticket promedio

- Status: `planned`
- Type: `feature`
- Priority: `medium`
- Started: 2026-10-06T23:11:41-05:00
- Updated: 2026-10-06T23:11:41-05:00

#### Goal

Hoy no existe ningun reporte de ventas por fecha -- requiere una consulta nueva agregando orders_rows por dia/semana/mes (backend nuevo, no es solo UI). Incluye comparacion vs periodo anterior (variacion %) y selector Hoy/7 dias/30 dias/Este mes, como en el mockup de referencia.

StellarCode task id: 129

#### Acceptance criteria

- TODO: definir criterios verificables

#### Evidence

- Pending

### DEV-20261006-013 — Pedidos recientes en el dashboard (tabla en vivo)

- Status: `planned`
- Type: `feature`
- Priority: `low`
- Started: 2026-10-06T23:11:46-05:00
- Updated: 2026-10-06T23:11:46-05:00

#### Goal

Tabla de los ultimos N pedidos con cliente/estado/total/hora directamente en el inicio, para no tener que entrar a Pedidos solo para ver que paso. Usa el mismo admin-orders ya existente con limit bajo.

StellarCode task id: 130

#### Acceptance criteria

- TODO: definir criterios verificables

#### Evidence

- Pending

### DEV-20261006-014 — Salud del catalogo: % completitud, productos sin imagen/precio/categoria/descripcion

- Status: `planned`
- Type: `feature`
- Priority: `medium`
- Started: 2026-10-06T23:11:51-05:00
- Updated: 2026-10-06T23:11:51-05:00

#### Goal

Reemplaza los numeros sueltos de productos/categorias del dashboard actual por un bloque con barra de completitud y desglose real (cuantos sin imagen, sin precio, sin categoria, sin descripcion). Se relaciona con DEV-20261006-009 (por que solo 7 de 57 categorias tienen subcategoria real).

StellarCode task id: 131

#### Acceptance criteria

- TODO: definir criterios verificables

#### Evidence

- Pending

### DEV-20261006-015 — Busqueda global (Ctrl+K) de pedidos/productos/clientes

- Status: `planned`
- Type: `feature`
- Priority: `low`
- Started: 2026-10-06T23:11:55-05:00
- Updated: 2026-10-06T23:11:55-05:00

#### Goal

Input de busqueda en el header que encuentre un pedido por numero o un producto por nombre sin tener que ir a cada seccion por separado. Con 2789 productos deja de ser lujo rapido.

StellarCode task id: 132

#### Acceptance criteria

- TODO: definir criterios verificables

#### Evidence

- Pending

### DEV-20261006-016 — Modulos nuevos del mockup sin backend real: Clientes, Clasificacion IA, Inventario/Stock, Analitica/Reportes

- Status: `planned`
- Type: `feature`
- Priority: `low`
- Started: 2026-10-06T23:11:59-05:00
- Updated: 2026-10-06T23:11:59-05:00

#### Goal

El mockup de referencia muestra modulos que hoy no existen en el backend en absoluto (no son ajustes de UI, son features nuevas de alcance propio, cada una necesitaria su propio diseno): gestion de clientes, clasificacion automatica de categorias por IA, control de inventario/stock real, reportes/analitica. No se estiman ni se empiezan hasta decidir alcance de cada una por separado -- se documentan aqui para no perder la idea del mockup.

StellarCode task id: 133

#### Acceptance criteria

- TODO: definir criterios verificables

#### Evidence

- Pending

