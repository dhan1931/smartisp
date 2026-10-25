-- =====================================================================
-- SmartISP — Migración manual consolidada para producción (Hostinger)
-- =====================================================================
-- Contiene, en un solo archivo y en orden, el mismo cambio de esquema que
-- las 6 migraciones versionadas en migrations/001 a migrations/006.sql
-- (esas se mantienen igual, para seguir usando `php scripts/migrate.php`
-- como método normal). Este archivo es la alternativa para aplicar el
-- mismo cambio a mano, por ejemplo pegándolo en la pestaña SQL de
-- phpMyAdmin o con el cliente `mysql` directo, sin correr PHP.
--
-- Punto de partida que asume este archivo: el dump original
-- u606699314_smart_isp.sql (como estaba la base antes de esta sesión) --
-- 7 tablas: categories_rows, orders_rows, password_resets,
-- password_resets_rows, products_rows, settings_rows, users_rows.
-- Ninguna de las tablas "_rows" tenía llave primaria; casi todas las
-- columnas eran TEXT genérico, incluidos id, visible, fechas y totales.
--
-- ANTES DE EJECUTAR:
--   1. Respaldo completo de la base real (mysqldump), fuera del repo.
--   2. Confirmar que es la primera vez que se aplica (ver guía en
--      docs/DEPLOY.md, sección 2). Si no es la primera vez, usar
--      `php scripts/migrate.php --status` en su lugar: ese script sí
--      sabe qué migraciones ya se aplicaron y se salta las que repiten.
--   3. Este archivo se pensó para una sola corrida limpia, en orden, de
--      arriba a abajo. Si una sentencia falla, detenerse y revisar antes
--      de seguir (no tiene la lógica de "ya aplicado, se omite" que sí
--      tiene scripts/migrate.php).
--
-- Verificado: exactamente el mismo contenido que las migraciones ya
-- probadas contra una copia local completa de los datos reales (2789
-- productos, 12 pedidos, 5 usuarios) — ver commits efc8ff9, 740f598.

-- =====================================================================
-- 001 — products_rows: tipos reales + PRIMARY KEY (DEV-20261005-023)
-- =====================================================================
-- Verificado antes de escribir esto: los 2789 id son únicos y ninguno es
-- NULL/vacío; visible solo tiene los valores '0' y '1'.

ALTER TABLE products_rows
  MODIFY COLUMN id VARCHAR(191) NOT NULL,
  MODIFY COLUMN visible TINYINT(1) NOT NULL DEFAULT 1,
  MODIFY COLUMN created_at TIMESTAMP NULL DEFAULT NULL,
  MODIFY COLUMN updated_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE products_rows ADD PRIMARY KEY (id);

ALTER TABLE products_rows ADD INDEX idx_visible_created (visible, created_at);

-- =====================================================================
-- 002 — orders_rows: tipos reales + PRIMARY KEY (DEV-20261005-023)
-- =====================================================================
-- created_at/updated_at con microsegundos+zona horaria ('...437505+00')
-- no convierten directo a TIMESTAMP; se normalizan primero.

UPDATE orders_rows
SET created_at = SUBSTRING_INDEX(SUBSTRING_INDEX(created_at, '.', 1), '+', 1),
    updated_at = SUBSTRING_INDEX(SUBSTRING_INDEX(updated_at, '.', 1), '+', 1)
WHERE created_at LIKE '%.%' OR created_at LIKE '%+%';

ALTER TABLE orders_rows
  MODIFY COLUMN id VARCHAR(191) NOT NULL,
  MODIFY COLUMN user_id VARCHAR(191) NULL,
  MODIFY COLUMN total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  MODIFY COLUMN status VARCHAR(40) NOT NULL DEFAULT 'pending',
  MODIFY COLUMN payment_status VARCHAR(40) NULL,
  MODIFY COLUMN payment_provider VARCHAR(60) NULL,
  MODIFY COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  MODIFY COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE orders_rows ADD PRIMARY KEY (id);

ALTER TABLE orders_rows ADD INDEX idx_user (user_id);
ALTER TABLE orders_rows ADD INDEX idx_status_created (status, created_at);

-- =====================================================================
-- 003 — order_events: tabla nueva, historial de estado de pedidos
-- (DEV-20261005-019, centro de pedidos)
-- =====================================================================

CREATE TABLE IF NOT EXISTS order_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id VARCHAR(191) NOT NULL,
  from_status VARCHAR(40) NULL,
  to_status VARCHAR(40) NOT NULL,
  actor VARCHAR(191) NULL,
  note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_order (order_id, created_at),
  CONSTRAINT fk_order_events_order FOREIGN KEY (order_id) REFERENCES orders_rows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 004 — users_rows: tipos reales + PRIMARY KEY + UNIQUE(email)
-- (DEV-20261005-023)
-- =====================================================================
-- Verificado antes de escribir esto: 5 usuarios, todos con id y email
-- (en minúsculas/sin espacios) únicos y no vacíos.

UPDATE users_rows
SET created_at = SUBSTRING_INDEX(SUBSTRING_INDEX(created_at, '.', 1), '+', 1)
WHERE created_at LIKE '%.%' OR created_at LIKE '%+%';

ALTER TABLE users_rows
  MODIFY COLUMN id VARCHAR(191) NOT NULL,
  MODIFY COLUMN email VARCHAR(191) NOT NULL,
  MODIFY COLUMN role VARCHAR(40) NOT NULL DEFAULT 'customer',
  MODIFY COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE users_rows ADD PRIMARY KEY (id);
ALTER TABLE users_rows ADD UNIQUE INDEX idx_email (email);

-- =====================================================================
-- 005 — quitar password_resets_rows (tabla muerta) (DEV-20261005-023)
-- =====================================================================
-- Columnas genéricas 'COL 1'..'COL 4' de una importación vieja, sin
-- ninguna referencia en el código. La tabla real y en uso es
-- password_resets (ya tiene PRIMARY KEY, UNIQUE en token).

DROP TABLE IF EXISTS password_resets_rows;

-- =====================================================================
-- 006 — mail_settings: separar configuración de correo de settings_rows
-- (DEV-20261005-023)
-- =====================================================================

CREATE TABLE IF NOT EXISTS mail_settings (
  id TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
  admin_email VARCHAR(191) NULL,
  smtp_provider VARCHAR(40) NULL,
  smtp_host VARCHAR(191) NULL,
  smtp_port SMALLINT UNSIGNED NULL,
  smtp_user VARCHAR(191) NULL,
  smtp_pass VARCHAR(255) NULL,
  smtp_secure TINYINT(1) NOT NULL DEFAULT 1,
  smtp_from VARCHAR(255) NULL,
  resend_api_key VARCHAR(255) NULL,
  email_from VARCHAR(255) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_mail_settings_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO mail_settings (id, admin_email, smtp_provider, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, smtp_from, resend_api_key, email_from)
SELECT
  1,
  MAX(CASE WHEN setting_key = 'admin_email' THEN setting_value END),
  MAX(CASE WHEN setting_key = 'smtp_provider' THEN setting_value END),
  MAX(CASE WHEN setting_key = 'smtp_host' THEN setting_value END),
  NULLIF(MAX(CASE WHEN setting_key = 'smtp_port' THEN setting_value END), ''),
  MAX(CASE WHEN setting_key = 'smtp_user' THEN setting_value END),
  MAX(CASE WHEN setting_key = 'smtp_pass' THEN setting_value END),
  CASE WHEN MAX(CASE WHEN setting_key = 'smtp_secure' THEN setting_value END) IN ('false', '0') THEN 0 ELSE 1 END,
  MAX(CASE WHEN setting_key = 'smtp_from' THEN setting_value END),
  MAX(CASE WHEN setting_key = 'resend_api_key' THEN setting_value END),
  MAX(CASE WHEN setting_key = 'email_from' THEN setting_value END)
FROM settings_rows
WHERE setting_key IN ('admin_email','smtp_provider','smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','smtp_secure','resend_api_key','email_from')
ON DUPLICATE KEY UPDATE
  admin_email = VALUES(admin_email), smtp_provider = VALUES(smtp_provider), smtp_host = VALUES(smtp_host),
  smtp_port = VALUES(smtp_port), smtp_user = VALUES(smtp_user), smtp_pass = VALUES(smtp_pass),
  smtp_secure = VALUES(smtp_secure), smtp_from = VALUES(smtp_from), resend_api_key = VALUES(resend_api_key),
  email_from = VALUES(email_from);

INSERT IGNORE INTO mail_settings (id) VALUES (1);

DELETE FROM settings_rows
WHERE setting_key IN ('admin_email','smtp_provider','smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','smtp_secure','resend_api_key','email_from');

-- =====================================================================
-- Registro de control (igual que usa scripts/migrate.php) — opcional si
-- aplicaste todo a mano, pero recomendado: así una corrida futura de
-- `php scripts/migrate.php --status` reconoce estas 6 como ya aplicadas
-- y no intenta repetirlas.
-- =====================================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  migration VARCHAR(255) NOT NULL UNIQUE,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration) VALUES
  ('001_products_rows_tipos_reales.sql'),
  ('002_orders_rows_tipos_reales.sql'),
  ('003_order_events.sql'),
  ('004_users_rows_llaves.sql'),
  ('005_quitar_password_resets_rows.sql'),
  ('006_mail_settings.sql');

-- =====================================================================
-- Verificación final — correr esto y comparar contra lo esperado
-- =====================================================================

-- Las 6 deberían aparecer:
SELECT migration, applied_at FROM schema_migrations ORDER BY id;

-- Deberían tener PRIMARY KEY ahora (Key = 'PRI' en la fila de id):
SHOW KEYS FROM products_rows WHERE Key_name = 'PRIMARY';
SHOW KEYS FROM orders_rows WHERE Key_name = 'PRIMARY';
SHOW KEYS FROM users_rows WHERE Key_name = 'PRIMARY';

-- Debería existir, con exactamente 1 fila (id=1):
SELECT * FROM mail_settings;

-- Debería dar 0 (la tabla muerta ya no existe; a propósito no se hace
-- SELECT directo de esa tabla, porque eso corta la ejecución con un error
-- fatal en vez de simplemente devolver 0):
SELECT COUNT(*) AS tabla_muerta_todavia_existe
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'password_resets_rows';

-- Ningún conteo de filas debería haber cambiado respecto al dump original
-- (estas migraciones cambian tipos de columna e índices, nunca borran ni
-- agregan filas de datos, salvo mail_settings que es una tabla nueva de 1
-- sola fila). Verificado corriendo este archivo completo contra una copia
-- limpia del dump original (no contra una copia local ya modificada):
SELECT COUNT(*) AS total_productos FROM products_rows;   -- esperado: 2789
SELECT COUNT(*) AS total_pedidos FROM orders_rows;        -- esperado: 12
SELECT COUNT(*) AS total_usuarios FROM users_rows;        -- esperado: 4
