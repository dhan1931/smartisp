# smartisp — Diagnóstico: qué está mal

Fecha: 2026-10-05 · Método: análisis estático con `devflow auditar` (36 hallazgos: 4 críticos, 12 altos, 13 medios, 6 bajos, 1 de pruebas) más revisión manual del código.
Sin mediciones en producción: lo que depende del servidor real está marcado `[Inferencia]`.
Las propuestas de mejora están en [ARQUITECTURA-Y-DEUDA.md](ARQUITECTURA-Y-DEUDA.md); el detalle de seguridad, en [../ops/SECURITY.md](../ops/SECURITY.md).

Score actual: Project Health 49.0 (topado por críticos de seguridad), Stack Fit 87.8, Evidence Confidence 44.2.

## 1. Autenticación y sesión — lo más grave

| ID | Sev | Qué está mal | Dónde |
|---|---|---|---|
| SEC-001 | Crítico | Cualquiera obtiene sesión admin enviando la cabecera `X-Admin-Email` con un email de la lista admin. **El propio front depende de esto**: `admin.html` y `editor-catalogo.html` envían esa cabecera en cada petición, y `admin.html` la rellena con el admin por defecto aunque no haya sesión. | `api/router.php:133-171`, `admin.html:1471-1489`, `editor-catalogo.html:1614` |
| SEC-002 | Crítico | Contraseña universal escrita en el código para los emails admin (login y cambio de contraseña). | `api/router.php:1650, 2083` |
| SEC-003 | Crítico | Clave de firma de los tokens admin escrita en el código; permite forjar tokens de 30 días. | `api/router.php:65, 109` |
| SEC-012 | Crítico | El login compara la contraseña en texto plano, con MD5 y con SHA1: las contraseñas de usuarios probablemente están sin hash seguro. | `api/router.php:1676-1680` |
| A-01 | Alto | Token admin guardado en `localStorage` (11 usos): cualquier XSS lo roba y dura 30 días. | `admin.html:1581`, `editor-catalogo.html:1716`, `tienda.html:3388` |
| A-02 | Medio | El rol admin se decide en el cliente con emails literales, repetidos en 11 lugares del front; es visible y manipulable. | `admin.html:1468, 2249`, `tienda.html:3256` |
| SEC-007 | Medio | Token aceptado por query string (`?token=`): queda en logs e historial. | `api/router.php:109` |
| SEC-008 | Medio | Login sin límite de intentos (fuerza bruta). | `api/router.php:1614` |
| A-03 | Medio | Dos formularios de login para el mismo `/api/auth/login`: uno en `tienda.html` (clientes) y otro dentro de `admin.html`, con almacenamiento y manejo de sesión distintos. | `tienda.html`, `admin.html:256-264` |

## 2. Secretos y configuración

| ID | Sev | Qué está mal |
|---|---|---|
| SEC-004 / 014 | Alto | Contraseña real de MySQL como fallback en `api/config.php` (en git e historial) y en `src/lib/mysqlClient.js`. Sin rotar. |
| SEC-005 | Alto | `SESSION_SECRET` con valor por defecto público en `server.js:157`. |
| SEC-010 | Crítico `[Inferencia]` | `/api/auth/catalog` devuelve filas de `settings_rows` sin excluir `smtp_pass`, `smtp_user` ni `resend_api_key`; el endpoint hermano `landing-content` sí los filtra. Falta confirmar con datos reales. |
| SEC-011 | Medio `[Inferencia]` | `.htaccess` solo protege `.env` y `config.php`; `server.js`, `package.json`, `.devflow.yml`, `ops/` y `docs/` serían descargables si el sitio se sirve desde la raíz. |
| SEC-013 | Alto | Verificación TLS desactivada en correo, conexión Postgres y script de importación. |

## 3. Superficie de la API

| ID | Sev | Qué está mal |
|---|---|---|
| SEC-009 | Medio | Proxy de imágenes que descarga cualquier URL recibida, sin lista de dominios permitidos (SSRF). |
| SEC-006 | Medio | CORS `*` en toda la API PHP y en `server.js`. |
| O-04 | Medio | Endpoints de diagnóstico públicos: `test-db`, `test-products`, `test-supabase`, `test-email`. |
| O-05 | Bajo | Respuestas que devuelven el mensaje interno de las excepciones. |
| O-06 | Bajo | Alias duplicados por endpoint (`content`/`items`, `landing-content`/`site-content`, `product-image`/`proxy-image`, etc.) y dos convenciones de URL (`/api/router?action=` y `/api/auth/<accion>`). |
| SEC-XSS | Bajo | 4 páginas con ≥5 asignaciones a `innerHTML` con datos interpolados; existe helper de escape, hay que verificar que se use siempre. |

## 4. Organización del código

| ID | Sev | Qué está mal | Evidencia |
|---|---|---|---|
| O1 | Alto | Todo el ruteo es una cadena de ~40 `if ($action === ...)` en un solo archivo; cada bloque mezcla auth, validación, SQL y respuesta. | `router.php`, 2129 líneas |
| O2 | Alto | Dos backends con la misma API: `server.js` (Express, 34 rutas, Supabase/Postgres) y `router.php` (MySQL). Cada cambio se hace dos veces. `[Inferencia]` El PHP es el de producción. | 21 de los últimos 30 commits son `fix` |
| O6 | Medio | La autorización se llama a mano dentro de cada bloque (`requireAdminAuth`, ~15 veces); un endpoint nuevo sin esa llamada queda abierto sin aviso. | `router.php` |
| O7 | Medio | El esquema se adivina en cada petición con `DESCRIBE`/`SHOW TABLES` y columnas llamadas `COL 2`/`col_3`; ya causó un error 500 en login. | `router.php`, `db.php` |
| O9 | Medio | La raíz del proyecto es la raíz web: 7 archivos duplicados con `public/`, y código interno junto a lo que se sirve. | raíz, `public/` |

## 5. Front

| ID | Sev | Qué está mal | Evidencia |
|---|---|---|---|
| F1 | Medio | **Todo el admin está en un solo HTML** que además incluye su propio login y la pantalla de acceso denegado: 3516 líneas, 2162 de CSS y 2471 de JS inline. | `admin.html` |
| F2 | Medio | Los editores repiten el mismo patrón: `editor-catalogo.html` (3674 líneas, con su propia copia del cliente de API) y `editor-landing.html` (3930). | |
| F3 | Medio | `tienda.html` (3549 líneas) junta catálogo, carrito, registro, login y perfil. | |
| F4 | Bajo | No hay un cliente de API compartido: cada página reimplementa cabeceras, token y manejo de errores. | `admin.html:1460`, `editor-catalogo.html:1600`, `tienda.html:3470` |
| F5 | Bajo | El JS y CSS inline impide cachearlos por separado. | |

## 6. Calidad, datos y rendimiento

| ID | Sev | Qué está mal |
|---|---|---|
| T1 | Alto | 0 archivos de prueba para 16 archivos fuente. |
| P1 | Medio | `/api/auth/catalog` hace `SELECT *` de toda la tabla y filtra y pagina en PHP; el costo crece con el catálogo. `[Inferencia]` sin medir. |
| P2 | Medio | Toda la API responde `Cache-Control: no-store`, incluido el catálogo público. |
| P3 | Bajo | `unpkg.com/lucide@latest` sin versión fija ni `defer`. |
| D1 | Medio | El script de la base de datos real no está en el repo: no hay migraciones ni forma de recrear el esquema. |

## 7. Operación y herramientas

| ID | Sev | Qué está mal |
|---|---|---|
| OP1 | Medio | No hay CI, ni respaldos documentados, ni monitoreo (`devops` 45, `observability` 35). `[Inferencia]` pueden existir fuera del repo. |
| OP2 | Bajo | `.devflow.yml` sin comandos de `build`, `test`, `lint`: DevFlow no puede ejecutar nada, solo leer. |
| OP3 | Bajo | El detector de DevFlow da PostgreSQL porque el proyecto usa los dos motores (34 coincidencias frente a 22). |

## Orden de gravedad

1. Críticos de autenticación (SEC-001, 002, 003, 012) y la contraseña de MySQL por rotar.
2. Confirmar SEC-010 contra el servidor.
3. Todo lo marcado Alto.
4. Medios, empezando por la separación de login y admin, que además desbloquea el arreglo de SEC-001.
