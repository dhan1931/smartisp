# Checklist de despliegue a producción (Hostinger)

Esta guía cubre la publicación del código en Hostinger y la aplicación deliberada de cambios de esquema. El build no ejecuta migraciones ni modifica la base de datos. Verifica en hPanel el último despliegue y el estado real de las migraciones antes de operar producción.

GitHub Actions valida sintaxis PHP/JavaScript, el build y las migraciones sobre una MariaDB temporal
con credenciales de prueba. No tiene acceso a Hostinger ni modifica la base de producción. Hostinger
mantiene su publicación independiente desde `main`.

## 1. Antes de tocar nada en producción

- [ ] **Respaldo completo de la base real de Hostinger** (`mysqldump`, fuera del repo). Sin esto no se sigue.
- [ ] Confirmar que nadie más está desplegando/editando el sitio en ese momento.
- [ ] Revisar el commit que se publicará en `main` y confirmar que el build anterior terminó correctamente.

## 2. Migraciones de base de datos (6, ya probadas en local)

```
migrations/001_products_rows_tipos_reales.sql   -- tipos reales + PK en products_rows
migrations/002_orders_rows_tipos_reales.sql     -- tipos reales + PK en orders_rows
migrations/003_order_events.sql                 -- tabla nueva: historial de estado de pedidos
migrations/004_users_rows_llaves.sql            -- tipos reales + PK/UNIQUE en users_rows
migrations/005_quitar_password_resets_rows.sql  -- borra una tabla muerta
migrations/006_mail_settings.sql                -- separa SMTP/correo de settings_rows a tabla propia
```

Runner: `scripts/migrate.php` (soporta `--dry-run` y `--status`; no usa transacciones reales porque
MySQL/MariaDB hace commit implícito en cada DDL — trata como "ya aplicado" los errores que
significan exactamente eso, para poder reintentar sin editar nada a mano).

Pasos:

```bash
# 1. Apuntar las variables de entorno a la base REAL de Hostinger (no a la copia local)
export MYSQL_HOST=...
export MYSQL_PORT=3306
export MYSQL_DATABASE=...
export MYSQL_USER=...
export MYSQL_PASSWORD=...

# 2. Ver qué falta aplicar, sin tocar nada
php scripts/migrate.php --status

# 3. Aplicar de verdad
php scripts/migrate.php
```

- [ ] `--status` revisado antes de aplicar.
- [ ] Migraciones aplicadas.
- [ ] `--status` otra vez después, las 6 en `[aplicada]`.

### Alternativa: un solo archivo, aplicado a mano

Si prefieres pegar el SQL directo en phpMyAdmin (o con el cliente `mysql`) en vez de correr
`scripts/migrate.php` desde una terminal con acceso a Hostinger, existe el mismo cambio consolidado
en un único archivo: **`docs/MIGRACION-MANUAL-PRODUCCION.sql`**. Mismo contenido que las 6
migraciones, en el mismo orden, con una sección de verificación al final (conteos esperados: 2789
productos, 12 pedidos, 4 usuarios — los del dump original `u606699314_smart_isp.sql`).

Probado dos veces contra una copia local limpia del dump original (`CREATE DATABASE`
nueva + importar el dump + correr este archivo): corre de punta a punta sin errores, deja las 6
migraciones registradas en `schema_migrations` (para que un futuro `--status` las reconozca como
aplicadas), y los conteos de verificación coinciden exactamente.

- [ ] Backup antes (ver paso 1, es el mismo sin importar qué método uses).
- [ ] Aplicar `docs/MIGRACION-MANUAL-PRODUCCION.sql` completo, de una vez, en orden.
- [ ] Correr las consultas de verificación al final del archivo y comparar contra lo esperado.

## 3. Variables de entorno en el servidor

Hostinger debe tener estos valores en **Environment variables**. No subirlos a GitHub, no ponerlos
en `.env.example` y no pegarlos en commits:

Este deploy sincroniza `dist/` sobre `public_html`; por eso un `.env` guardado dentro de
`public_html` puede desaparecer en cada publicación. Para usar archivo, guarda `.env` en la carpeta
que contiene `public_html` (un nivel arriba). `api/config.php` busca automáticamente allí y también
acepta `SMARTISP_ENV_FILE` como ruta absoluta. En desarrollo sigue leyendo el `.env` de la raíz del
repo. No copies secretos a `dist/` ni los añadas al plugin de Vite.

Las variables del panel de compilación deben estar disponibles para PHP en tiempo de ejecución
para reemplazar al archivo; que el log diga que se cargaron durante el build no lo demuestra por sí
solo. Puedes validar la conexión sin mostrar claves: la petición de catálogo debe devolver HTTP 200,
productos y un `total` mayor que cero.

| Clave | Uso | Requerida |
| --- | --- | --- |
| `MYSQL_HOST` | Host MySQL/MariaDB de Hostinger | Si |
| `MYSQL_PORT` | Puerto MySQL, normalmente `3306` | Si |
| `MYSQL_DATABASE` | Nombre de la base de datos | Si |
| `MYSQL_USER` | Usuario de la base de datos | Si |
| `MYSQL_PASSWORD` | Password de la base de datos | Si |
| `ADMIN_EMAIL` | Correo que recibe avisos y pruebas | Si |
| `SMTP_PROVIDER` | `hostinger`, `gmail`, `custom` o `resend` | Si |
| `SMTP_HOST` | Servidor SMTP | Si para SMTP |
| `SMTP_PORT` | Puerto SMTP, normalmente `465` o `587` | Si para SMTP |
| `SMTP_SECURE` | `true` para SSL/465, `false` para STARTTLS/587 | Si para SMTP |
| `SMTP_USER` | Usuario/cuenta SMTP | Si para SMTP |
| `SMTP_PASS` | Password o app password SMTP | Si para SMTP |
| `SMTP_FROM` | Remitente, por ejemplo `SmartISP <ventas@dominio>` | Recomendado |
| `EMAIL_FROM` | Remitente usado por Resend si aplica | Solo Resend |
| `RESEND_API_KEY` | API key de Resend | Solo Resend |

El código lee primero las variables de entorno de Hostinger y solo después cae a `mail_settings`.
Eso permite que credenciales sensibles como `MYSQL_PASSWORD` y `SMTP_PASS` no vivan en la base ni
en el repositorio.

## 4. Publicar el código

El despliegue de Hostinger conectado a este repositorio toma la rama `main`, instala dependencias
con npm, ejecuta `npm run build` y sincroniza `dist/` con `public_html`. El build multipágina de Vite
incluye las páginas HTML, assets, endpoints PHP y reglas/archivos SEO declarados en `vite.config.js`.
No edites `dist/` manualmente: se vuelve a generar en cada compilación.

- [ ] Confirmar que el commit que se publicará está en `main`.
- [ ] En los logs, comprobar `npm run build`, la lista de páginas esperadas en `dist/` y el resultado de publicación.
- [ ] Confirmar que los secretos PHP siguen configurados en hPanel o en `.env` fuera de `public_html`.
- [ ] Verificar el sitio y las peticiones de datos después del despliegue; una respuesta 200 de la página no confirma conexión a MySQL.

### Cómo debe quedar la raíz web en Hostinger tras un despliegue limpio

Esta sesión dejó la raíz del repo como única fuente de estáticos (`DEV-20261005-032`): si el
hosting todavía tiene restos de antes de ese cambio, un despliegue "limpio" significa que esos
restos **ya no deberían existir ahí**. Verificar y borrar del servidor si aparecen:

- [ ] **Carpeta `public/`** — ya no existe en el repo; si sigue en el hosting, son duplicados
      viejos de `producto.php`, `robots.txt`, `sitemap*.xml` y un mirror vacío de `uploads/products/`.
- [ ] **`app.js`, `styles.css`, `db.js`** en la raíz — catálogo de ejemplo hardcodeado y cliente de
      Supabase sin uso, de un prototipo anterior al catálogo real (`DEV-20261005-033`). No los
      referencia ningún `.html` actual.
- [ ] Favicons/manifests sueltos en la raíz (fuera de `assets/favicons/` y `assets/`) — movidos en
      `DEV-20261005-034/035`; si quedan copias viejas en la raíz del hosting, son redundantes.

Lo que **sí** debe estar en la raíz del hosting (output de `git ls-files`, sin `docs/`, `ops/`,
`scripts/`, `migrations/` — esas carpetas el propio `.htaccess`/`dev-router.php` ya las bloquea de
servirse por HTTP, pero tampoco hace falta subirlas al hosting de producción):

```
.htaccess                    -- reglas de reescritura/seguridad
index.html                   -- portada
tienda.html                  -- catálogo público
producto.php                 -- ficha de producto (/producto/<slug>)
checkout.html  login.html  reset-password.html
admin.html  pedidos.html  configuracion.html
editor-catalogo.html  editor-landing.html
robots.txt  sitemap.xml  sitemap-main.xml  sitemap-products.php
api/                          -- router.php, config.php, db.php, mailer.php, handlers/
assets/                       -- css/, js/, favicons/, img/, manifest.json, site.webmanifest
uploads/products/.gitkeep     -- carpeta real de subida de imágenes (ver nota abajo)
```

Presentes en el repo pero **inertes en producción** (backend Node/Express congelado, `DEV-20261005-014`
aún sin decisión formal): `server.js`, `package.json`, `vercel.json`, `src/lib/*.js`, `data/products-storage.json`.
No hace falta subirlos ni borrarlos — no los sirve ninguna ruta mientras PHP sea el backend real.

- [ ] `uploads/products/` y `uploads/cache/` deben existir como carpetas **escribibles** por PHP en
      el hosting (subida de imágenes de producto y caché del proxy de imágenes, `DEV-20261006-002`).
      Si no existen, el código las crea solo en el primer uso (`@mkdir(..., true)`), pero conviene
      confirmar los permisos de escritura del usuario de PHP en Hostinger.

## 5. Después de publicar: verificación mínima

- [ ] `/` (portada) carga.
- [ ] `/tienda.html` carga el catálogo, un filtro de subcategoría y una búsqueda funcionan.
- [ ] El sidebar de categorías muestra grupos prolijos (Computación, Componentes, Redes,
      Monitores, Periféricos...), no decenas de categorías sueltas sin agrupar.
- [ ] `/producto/<slug>` de un producto real carga bien.
- [ ] Un producto **oculto** (`visible=0`) da 404 en su URL directa.
- [ ] Login de admin funciona; `/admin.html`, `/pedidos.html`, `/configuracion.html` cargan y
      muestran datos reales (no solo el shell).
- [ ] En el navbar del panel admin, el botón "Ver tienda" se lee bien (texto visible, no blanco
      sobre blanco).
- [ ] Crear un pedido de prueba real y confirmar que llega el correo (usa la config de
      `mail_settings`, ya migrada).
- [ ] Revisar el log de errores de PHP en Hostinger un rato después del despliegue.

## Pendiente conocido, no bloqueante

- `api/handlers/products.php` todavía llama `ensureProductTableColumns()` en cada petición aunque
  las migraciones ya fijan el esquema — es una verificación redundante, no rompe nada, queda para
  una limpieza aparte (no se toca aquí para no mezclar con el despliegue).
- `server.js` (backend Node/Express paralelo) sigue sin usarse en producción según la misma
  investigación; no se toca ni se despliega.
