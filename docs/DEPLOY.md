# Checklist de despliegue a producción (Hostinger)

Este documento deja todo listo para cuando decidas desplegar. **Nada de esto se ejecuta automáticamente ni toca la base de datos real** — es la lista de pasos a seguir, en orden, con el porqué de cada uno.

Estado al momento de escribir esto (rama `dev-session-20261006`): código committeado y empujado a GitHub, 6 migraciones probadas contra una copia local de los datos reales, pendiente aplicar a producción.

## 1. Antes de tocar nada en producción

- [ ] **Respaldo completo de la base real de Hostinger** (`mysqldump`, fuera del repo). Sin esto no se sigue.
- [ ] Confirmar que nadie más está desplegando/editando el sitio en ese momento.
- [ ] Revisar `git log main..dev-session-20261006` para saber exactamente qué va a cambiar (o el diff de la PR si ya la abriste: https://github.com/dhan1931/smartisp/pull/new/dev-session-20261006).

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

## 3. Variables de entorno en el servidor

No se agregó ninguna variable nueva obligatoria esta sesión (mail_settings ahora vive en su propia
tabla, no en variables de entorno adicionales). Confirmar que ya existen en Hostinger:

- [ ] `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`
- [ ] `ADMIN_EMAIL` y la configuración SMTP correspondiente (ver `.env.example`) — o, si ya se
      configuró desde el panel de Configuración, confirmar que `mail_settings` tiene la fila real
      tras la migración 006 (no debería hacer falta nada adicional aquí).

## 4. Subir el código

Según `docs/ARQUITECTURA-Y-DEUDA.md`, Hostinger sirve la raíz del repo directamente (sin build de
Vite en producción hoy). El método de subida (git pull en el servidor, FTP, panel de Hostinger)
depende de cómo lo tengas configurado ahí — no se documenta aquí porque no se verificó esta sesión.

- [ ] Fusionar `dev-session-20261006` a `main` (o desplegar directo desde esa rama, según prefieras).
- [ ] Subir los archivos al hosting.

## 5. Después de desplegar: verificación mínima

- [ ] `/` (portada) carga.
- [ ] `/tienda.html` carga el catálogo, un filtro de subcategoría y una búsqueda funcionan.
- [ ] `/producto/<slug>` de un producto real carga bien.
- [ ] Un producto **oculto** (`visible=0`) da 404 en su URL directa (fix de esta sesión).
- [ ] Login de admin funciona; `/admin.html`, `/pedidos.html`, `/configuracion.html` cargan y
      muestran datos reales (no solo el shell).
- [ ] Crear un pedido de prueba real y confirmar que llega el correo (usa la config de
      `mail_settings`, ya migrada).
- [ ] Revisar el log de errores de PHP en Hostinger un rato después del despliegue.

## Pendiente conocido, no bloqueante

- `api/handlers/products.php` todavía llama `ensureProductTableColumns()` en cada petición aunque
  las migraciones ya fijan el esquema — es una verificación redundante, no rompe nada, queda para
  una limpieza aparte (no se toca aquí para no mezclar con el despliegue).
- `server.js` (backend Node/Express paralelo) sigue sin usarse en producción según la misma
  investigación; no se toca ni se despliega.
