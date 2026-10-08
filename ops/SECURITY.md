# Security Findings

## Revisión 2026-10-04 (estática, solo código; sin pruebas runtime)

| ID | Sev | Hallazgo | Ubicación |
|---|---|---|---|
| SEC-001 | CRITICAL | Bypass de admin: la cabecera `X-Admin-Email` (o `admin_email` en la URL) con un email de la lista admin crea sesión admin sin credenciales. | api/router.php:133-171 |
| SEC-002 | CRITICAL | Contraseña universal hardcodeada para emails admin en login y change-password. | api/router.php:1650, 2083 |
| SEC-003 | CRITICAL | Clave HMAC de tokens admin hardcodeada en el repo; permite forjar tokens (30 días). | api/router.php:65, 109 |
| SEC-004 | HIGH | Credenciales MySQL de producción como fallback en api/config.php, trackeado en git (queda en el historial). | api/config.php:8 |
| SEC-005 | HIGH | `SESSION_SECRET` con valor por defecto público. | server.js:157 |
| SEC-006 | MEDIUM | CORS `*` en toda la API PHP y en server.js:2280. | api/router.php:3, 1519, 1582 |
| SEC-007 | MEDIUM | Token admin aceptado por query/body (`token`, `admin_token`): queda en logs. | api/router.php:109 |
| SEC-008 | LOW | Sin tests; sin rate limiting en login (fuerza bruta). | — |
| SEC-009 | MEDIUM | Proxy de imágenes (`product-image`/`proxy-image`) hace fetch de cualquier URL recibida por token/url: posible SSRF; falta lista de dominios permitidos. | api/router.php:1449 |
| SEC-010 | CRITICAL | El endpoint público `catalog` devuelve filas de `settings_rows` excluyendo solo algunos prefijos; `smtp_pass`, `smtp_user` y `resend_api_key` no están excluidos (sí lo están en `landing-content`). Si esas claves existen en la tabla, se exponen sin autenticación. [Por confirmar contra datos reales] | api/router.php:436-470 |
| SEC-011 | MEDIUM | `.htaccess` solo bloquea `.env` y `api/config.php`; si el sitio se sirve desde la raíz, quedan descargables `server.js`, `db.js`, `package.json`, `.devflow.yml`, `ops/`, `docs/`, `data/` y `vite.config.js`. [Por confirmar contra el servidor] | .htaccess |
| SEC-012 | CRITICAL | El login acepta la contraseña comparada en texto plano, con MD5 y con SHA1 contra el valor guardado: las contraseñas de usuarios están (o pueden estar) almacenadas sin hash seguro. Detectado por el CLI (security.plaintext_password_compare). | api/router.php:1676-1680 |
| SEC-013 | HIGH | Verificación TLS desactivada (`CURLOPT_SSL_VERIFYPEER false`, `rejectUnauthorized: false`) en correo, conexión Postgres y script de importación. | api/mailer.php:270, server.js:85, scripts/import-intcomex-products.js:95 |
| SEC-014 | HIGH | La misma contraseña de MySQL aparece también como fallback en src/lib/mysqlClient.js (segundo punto a limpiar y rotar). | src/lib/mysqlClient.js:12 |
| SEC-015 | HIGH | `server.js` servía toda la carpeta del proyecto con `express.static(__dirname)` y escuchaba en todas las interfaces: `/api/config.php`, `server.js`, `package.json`, `ops/` y cualquier archivo suelto (incluido un token local) eran descargables. Corregido: lista de bloqueo de archivos internos y `HOST=127.0.0.1` opcional para desarrollo. Pendiente: mover los estáticos a una carpeta `public/` dedicada (DEV-032). | server.js |

Sin hallazgo: `npm audit --omit=dev` = 0 vulnerabilidades; consultas SQL con valores usan prepared statements (nombres de tabla/columna vienen de DESCRIBE, no del usuario).
Limitaciones: no se revisó uploads, server.js completo, headers del servidor ni configuración de Hostinger.

## Revisión 2026-10-06 (copia local de datos reales)

| ID | Sev | Hallazgo | Ubicación |
|---|---|---|---|
| SEC-016 | CRITICAL | El desempaquetado del `payload` en base64 (bypass de WAF) mezclaba sus claves en `$body` pero nunca borraba la clave `payload` original. Cualquier handler que guarda "cada clave del body como un ajuste" (admin-content POST, y potencialmente otros) terminaba guardando una fila `settings_rows` llamada literalmente `payload` con el blob completo, **incluida la contraseña SMTP real sin enmascarar**. Confirmado en los datos reales: una fila `payload` con fecha 2026-10-04 (anterior a esta sesión) traía el `smtp_pass` real en texto plano al decodificar. Corregido con `unset($body['payload'])` tras desempaquetar. **Acción pendiente en producción:** borrar la fila `settings_rows` con `setting_key='payload'` y rotar la contraseña SMTP, ya estaba expuesta. | api/router.php:168-176 |

## Revisión 2026-10-08 (código y pruebas locales disponibles)

- **SEC-010 mitigado en código:** ambos caminos del catálogo público omiten claves SMTP conocidas y toda clave que contenga `pass`, `secret` o `api_key`. Se añadió un caso de integración que inyecta un secreto temporal y verifica que no aparezca en la respuesta. La prueba de runtime aún debe ejecutarse en CI; el PHP CLI no está instalado en el equipo local.
- **SEC-011 mitigado parcialmente en el artefacto:** `package.json` ya no se copia a `dist`; `.htaccess` bloquea ese archivo, archivos de servidor/configuración nombrados y ejecución/acceso directo a PHP interno bajo `api/`, salvo `router.php`. Las carpetas internas del repositorio no forman parte de la lista de copia de Vite. No se verificó el comportamiento de rewrite en el Apache real de Hostinger.
- Se añadieron pruebas MariaDB aisladas para roles, endpoints admin, revocación de permisos, métricas e integridad de secretos. `npm run lint`, `npm test` y `node --check tests/admin-auth.integration.mjs` pasaron localmente; no había PHP CLI ni base local `_ci`, así que las pruebas PHP/MariaDB quedan pendientes de CI.
- **Autorización:** el rol efectivo se obtiene de `users_rows.role`; no se hizo ni se recomienda promoción automática. Producción debe verificarse con una consulta read-only a la fila de la cuenta. Una etiqueta “Administrador” en una sesión local antigua no demuestra el rol actual guardado en la base.
- **Pendiente:** confirmar en CI el nuevo test integrado y, después del despliegue, revisar la configuración Apache efectiva, errores PHP y que el secreto expuesto histórico (`settings_rows.setting_key='payload'`) haya sido eliminado y la contraseña SMTP rotada.
