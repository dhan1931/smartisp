# smartisp — Deuda de organización y plan de mejora del stack

Estado: propuesta para aprobación · Basado en la auditoría del 2026-10-05 (análisis estático; sin mediciones en producción).
Marcado `[Inferencia]` donde la conclusión no está confirmada con datos reales.

## 1. Postura sobre el stack

El stack **no está condenado**: PHP + MariaDB en Hostinger, JavaScript sin framework en el front y Node/Express como segundo backend. Stack Fit es 87.8 y la recomendación es `keep`. El problema no es la tecnología sino **cómo está organizada**: todo el ruteo y la lógica viven en pocos archivos gigantes, hay dos backends que duplican la API y el front mezcla HTML, CSS y JS en el mismo archivo.

La mejora propuesta es **incremental y sin reescritura**: se ordena lo existente, se elimina la duplicación y se mantiene la misma URL pública para que producción no se rompa en ningún paso.

## 2. Deuda de organización (con evidencia)

| # | Deuda | Evidencia | Costo concreto |
|---|---|---|---|
| O1 | **Ruteo por cadena de `if` en un solo archivo** | `api/router.php` (2129 líneas) enruta con ~40 bloques `if ($action === '...')`; cada bloque mezcla autenticación, validación, SQL y respuesta. | Imposible probar un endpoint aislado; cualquier cambio obliga a leer 2000 líneas; los bugs de auth se repiten (ver SEC-001 a 003). |
| O2 | **Dos backends con la misma API** | `server.js` (2292 líneas, Express, 34 rutas) replica casi 1:1 las rutas de `router.php`; uno usa Supabase/Postgres y el otro MySQL. | Todo cambio se hace dos veces o queda desincronizado. 21 de los últimos 30 commits son `fix`. [Inferencia] El PHP es el que está en producción (Hostinger, `.htaccess`, commits sobre LiteSpeed). |
| O3 | **Dos convenciones de URL** | El front llama `/api/router` (12 veces, directo a `router.php?action=`) y `/api/auth/<accion>` (resto). | Rutas inconsistentes, difíciles de documentar y de cachear. |
| O4 | **Alias duplicados por endpoint** | `landing-content`/`site-content`, `content`/`items`, `search-product-image`/`search-images`, `product-image`/`proxy-image`, `categories-reset` frente a `categories/reset`. | Superficie de API más grande de lo necesario; cada alias es una ruta más que proteger. |
| O5 | **Endpoints de diagnóstico públicos** | `test-db`, `test-products`, `test-supabase`, `test-email` expuestos con la API de producción. | Información de infraestructura accesible; ruido en el router. |
| O6 | **Autorización dispersa** | `requireAdminAuth()` se llama a mano dentro de cada bloque (≈15 veces); la lista de admins está escrita en `router.php` y repetida 11 veces en el front. | Un endpoint nuevo sin esa llamada queda abierto sin que nada lo advierta. |
| O7 | **Esquema adivinado en cada petición** | `DESCRIBE` y `SHOW TABLES` en `router.php`/`db.php`; columnas como `COL 2` o `col_3`. | Frágil ante cambios de tabla (ya causó un 500 en login) y agrega consultas a cada request. |
| O8 | **Front monolítico** | `admin.html` 3516 líneas, `editor-catalogo.html` 3674, `editor-landing.html` 3930, `tienda.html` 3549, `index.html` 2357; JS y CSS inline. `app.js`/`styles.css` casi no se usan. | No se pueden cachear scripts ni reutilizar código entre páginas; revisar un cambio es leer miles de líneas. |
| O9 | **Raíz del proyecto = raíz web** | Favicons, manifest, robots y sitemaps duplicados entre la raíz y `public/`; `server.js`, `package.json`, `ops/` conviven con lo que se sirve. | Archivos internos potencialmente descargables (SEC-011); no hay una sola forma de desplegar. |
| O10 | **Configuración mezclada con código** | Credenciales, claves de firma y correos admin como literales en `config.php`, `router.php`, `mysqlClient.js`, `server.js`. | Ver SEC-002 a 005 y 014. |
| O11 | **Sin red de seguridad** | 0 archivos de prueba. | Cada refactor es un riesgo; por eso esta propuesta empieza por pruebas de contrato. |

## 3. Estructura objetivo

Sin framework nuevo: PHP plano con autoload de Composer (PSR-4) y un front controller. La misma URL pública se mantiene.

```text
smartisp/
├─ public/                      ← única carpeta que sirve el servidor web
│  ├─ index.php                 ← front controller (único punto de entrada de /api)
│  ├─ *.html                    ← páginas (solo marcado)
│  ├─ assets/{js,css,img}/      ← JS/CSS extraídos de los HTML
│  └─ favicons, manifest, robots, sitemaps (una sola copia)
├─ api/
│  ├─ routes.php                ← tabla de rutas: método + ruta → handler + nivel de acceso
│  ├─ src/
│  │  ├─ Http/                  Router, Request, Response, Cors
│  │  ├─ Auth/                  Session, Token, Password, Guard (public|customer|admin)
│  │  ├─ Handlers/              Catalog, Products, Content, Categories, Orders, Auth, Images, Mail
│  │  └─ Repositories/          Products, Users, Orders, Settings (todo el SQL aquí)
│  └─ config/
│     ├─ config.example.php     ← versionado, sin datos reales
│     └─ config.local.php       ← fuera de git (.gitignore)
├─ tests/                       ← pruebas de contrato por endpoint
├─ docs/  ops/  scripts/  data/
└─ legacy/server.js             ← solo si se decide conservarlo (ver decisión D1)
```

### Ruteo: de `if` encadenados a una tabla

```php
// api/routes.php — cada ruta declara su nivel de acceso; nada queda abierto por olvido
return [
  ['GET',    '/catalog',            [Catalog::class,  'list'],   'public'],
  ['GET',    '/products',           [Products::class, 'list'],   'admin'],
  ['POST',   '/products',           [Products::class, 'save'],   'admin'],
  ['DELETE', '/products',           [Products::class, 'clear'],  'admin'],
  ['POST',   '/auth/login',         [Auth::class,     'login'],  'public'],
  ['GET',    '/auth/me',            [Auth::class,     'me'],     'customer'],
  ['GET',    '/settings/public',    [Content::class,  'public'], 'public'],
  // ...
];
```

El router resuelve método + ruta, ejecuta el `Guard` según el nivel declarado (la autorización deja de estar dentro de cada bloque) y devuelve 404/405 uniformes. Una prueba recorre la tabla y falla si alguna ruta no declara nivel.

**Convención única:** `/api/<recurso>`. Los alias antiguos (`/api/auth/<accion>`, `/api/router?action=`) se mantienen como redirecciones internas durante la transición y se retiran cuando el front deje de usarlos.

## 4. Plan por fases

Cada fase se despliega sola, es reversible y no cambia las URLs públicas.

### Fase 0 — Cerrar riesgos activos (antes de reorganizar)
Corregir SEC-001 a 005, 010, 012 y 014 y rotar las credenciales expuestas. Reorganizar sobre un sistema abierto solo reparte la vulnerabilidad en más archivos.
- Hecho cuando: `devflow auditar` ya no reporta críticos de seguridad y la contraseña de MySQL fue rotada.

### Fase 1 — Red de seguridad
Pruebas de contrato (PHPUnit o scripts con curl contra una base local) que fijen el comportamiento actual de los endpoints principales: `catalog`, login/registro, productos admin, órdenes, content.
- Hecho cuando: hay una prueba por endpoint público y por cada endpoint admin (que verifique el 403 sin credenciales).

### Fase 2 — Un solo backend
Decidir D1 (abajo). Con el PHP como canónico, `server.js` se congela y se mueve a `legacy/` o se elimina; se retira de `package.json` lo que ya no se use (Supabase, `pg`).
- Hecho cuando: cada ruta de la API tiene una única implementación.

### Fase 3 — Front controller y tabla de rutas
Introducir `public/index.php`, `Router` y `routes.php`; mover los bloques de `router.php` a handlers **uno por uno**, empezando por los de menor riesgo (`catalog`, `categories`) y terminando por auth. `router.php` queda como delegador hasta vaciarse.
- Hecho cuando: `router.php` desaparece y ningún archivo supera ~400 líneas.

### Fase 4 — Datos y configuración
Sacar el SQL a repositorios, fijar el esquema con migraciones numeradas (adiós `DESCRIBE`/`COL 2`), mover correos admin y secretos a configuración local, y exponer el rol al front desde la sesión en vez de repetir la lista en los HTML.
- Hecho cuando: no queda ningún `DESCRIBE` en tiempo de petición y cambiar el admin se hace en un solo lugar.

### Fase 5 — Front
Extraer el JS y CSS inline a `public/assets/`, crear un cliente `api.js` compartido y dividir `admin.html` y los editores por secciones. Después, decidir una sola forma de despliegue (raíz web = `public/`) y borrar las copias duplicadas.
- Hecho cuando: ningún HTML supera ~800 líneas y la raíz del proyecto ya no se sirve entera.

### Fase 6 — Rendimiento y operación
Catálogo con filtrado y paginación en SQL, `Cache-Control` corto en lecturas públicas, índices revisados, límite de intentos en login, y cabeceras de seguridad. Con datos reales: Lighthouse y k6 contra staging para pasar de estático a medido.

## 4b. Separación de login y panel admin

Diagnóstico en [DIAGNOSTICO.md](DIAGNOSTICO.md) (A-01 a A-03, F1 a F4). Hoy `admin.html` contiene tres pantallas (acceso denegado, login y panel) más 2162 líneas de CSS y 2471 de JS, y `tienda.html` tiene otro login para clientes.

**Objetivo:** un solo sistema de sesión, con el login separado del panel y el panel dividido por vistas.

```text
public/
├─ login.html                  ← único login (clientes y admin); redirige según el rol que devuelve el backend
├─ admin/
│  ├─ index.html               ← shell: navegación + contenedor (solo si hay sesión admin)
│  ├─ productos.html           ← catálogo (hoy editor-catalogo.html)
│  ├─ contenido.html           ← landing y apariencia (hoy editor-landing.html)
│  ├─ categorias.html
│  ├─ pedidos.html
│  └─ correo.html              ← SMTP / Resend
└─ assets/
   ├─ js/api.js                ← cliente único: credenciales, errores, redirección a login si 401
   ├─ js/session.js            ← lee /api/auth/me; expone user y rol (viene del backend)
   ├─ js/admin/*.js            ← un módulo por vista
   └─ css/{base,admin,tienda}.css
```

Reglas:
- **El rol lo decide el backend.** `/api/auth/me` devuelve `{user, role}`; el front solo lo muestra. Desaparecen las listas de emails de los HTML y la cabecera `X-Admin-Email`.
- **Sesión por cookie HttpOnly + SameSite**, no token en `localStorage`. El cierre de sesión invalida del lado del servidor.
- **Guardia del lado del servidor:** `/admin/*` solo se sirve si la sesión es admin; el HTML ya no se descarga completo para después ocultar paneles con `hidden`.
- **Un cliente de API compartido** (`api.js`) reemplaza las tres implementaciones distintas.
- **Se migra una vista por vez**, empezando por la más simple (categorías) y dejando el catálogo, que es la más grande, para el final.

### Orden obligatorio (si se invierte, se rompe el panel)

El front actual **depende** de `X-Admin-Email` para autenticarse. Por eso:

1. Crear `login.html` y `session.js` contra `/api/auth/me` y la cookie de sesión, con el flujo viejo todavía activo.
2. Migrar `admin.html` y los editores a `api.js`, que ya no envía la cabecera de identidad.
3. **Recién entonces** eliminar el bypass por `X-Admin-Email` del backend (SEC-001) y la contraseña universal (SEC-002).
4. Rotar la clave de firma y las credenciales (SEC-003, 004, 014).

Hacer el paso 3 antes del 2 deja a todos los administradores fuera del panel.

## 4c. Comprobaciones de DevFlow para vigilar esto

Agregadas en esta ronda a `devflow auditar` (`devflow/security_rules.py`, 75 tests pasando):

| Regla | Qué detecta | Resultado en smartisp |
|---|---|---|
| `front_sends_identity_header` | El front envía `X-Admin-Email` u otras cabeceras de identidad | `admin.html:1489`, `editor-catalogo.html:1614`, `tienda.html:3484` |
| `front_hardcoded_admin_identity` | El rol admin se decide en el cliente con emails literales | `admin.html`, `tienda.html` |
| `front_token_in_web_storage` | Token de sesión en `localStorage` | `admin.html`, `editor-catalogo.html`, `tienda.html` |
| `architecture.monolithic_page` | HTML de más de 1500 líneas; sube a medio si además incluye su propio login | `admin.html`, `editor-catalogo.html`, `tienda.html` (medio); `editor-landing.html`, `index.html` (bajo) |

Comprobaciones que conviene sumar a continuación:
- **Ruta sin nivel de acceso:** que `devflow` lea `routes.php` y falle si alguna ruta no declara `public`, `customer` o `admin` (se activa tras la Fase 3).
- **Cabecera de identidad aceptada por el backend:** ya existe (`auth_from_client_header`); debe pasar a 0 en el paso 3 de arriba.
- **Meta de tamaño:** `monolithic_page` debe bajar a 0 al terminar la Fase 5.
- **Comandos reales en `.devflow.yml`** (`build`, `test`, `lint`) para que el CLI ejecute pruebas en lugar de solo leer.

Criterio de cierre de cada fase: el hallazgo correspondiente aparece como `fixed` en el reporte versionado siguiente (`audit_vNN_...`).

## 5. Decisiones que necesito de ti

| ID | Decisión | Mi recomendación |
|---|---|---|
| D1 | ¿Cuál backend es el real: PHP o Node? | PHP (Hostinger ya lo sirve). Congelar `server.js`. |
| D2 | ¿Se aceptan las rutas nuevas `/api/<recurso>` con alias temporales? | Sí; los alias evitan romper el front. |
| D3 | ¿Existe un staging? | Si no, crear una copia en un subdominio antes de la Fase 3. |
| D4 | ¿Composer disponible en Hostinger? | Si no, un autoload manual de 20 líneas cubre PSR-4. |

## 6. Reglas para que la deuda no vuelva

- Toda ruta nueva se declara en `routes.php` con su nivel de acceso; sin nivel no pasa la prueba.
- Todo SQL vive en un repositorio; los handlers no escriben consultas.
- Ningún secreto en el repo: `devflow secretos` y `devflow auditar` corren antes de cada release.
- Un archivo que supere ~400 líneas de PHP o ~800 de HTML dispara una revisión de división.
- Los reportes de `devflow` se guardan versionados (`audit_vNN_AAMMDD-HHMM`) para comparar la deuda entre fases.

## 7. Qué no se propone

- Migrar a Laravel, Symfony, React o Angular: el costo supera el beneficio para el tamaño actual del proyecto.
- Microservicios, contenedores o Kubernetes.
- Reescribir el front desde cero: se divide por piezas.
