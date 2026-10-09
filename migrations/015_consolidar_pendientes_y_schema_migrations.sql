-- Migración consolidada: pone al día producción con lo que ya estaba escrito en
-- migrations/009, 011, 012, 013 y 014 pero nunca se aplicó ahí (verificado contra
-- un corte real de producción: solo 001-004, 006-008 y 010 estaban aplicadas), y además
-- crea/siembra schema_migrations para que scripts/migrate.php quede sincronizado desde ahora.
--
-- No incluye 005 (quitar password_resets_rows) a propósito: se decidió conservar esa tabla.
-- Ver migrations/005_quitar_password_resets_rows.sql, que ya no hace DROP.
--
-- Seguro de volver a correr: todo usa IF NOT EXISTS / ADD COLUMN IF NOT EXISTS / INSERT IGNORE.
-- Ejecutar de una sola vez, de arriba hacia abajo.

-- ============================================================================
-- 009: modo de presentación de campañas, encuadre de imagen por slide, chips promocionales.
-- ============================================================================
ALTER TABLE storefront_campaigns
  ADD COLUMN IF NOT EXISTS display_mode VARCHAR(16) NOT NULL DEFAULT 'carousel';

ALTER TABLE storefront_campaign_slides
  ADD COLUMN IF NOT EXISTS image_fit VARCHAR(12) NOT NULL DEFAULT 'cover';

CREATE TABLE IF NOT EXISTS storefront_promo_chips (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  label VARCHAR(60) NOT NULL,
  icon VARCHAR(40) NOT NULL DEFAULT 'tag',
  target_url VARCHAR(500) NOT NULL DEFAULT '/tienda.html#catalogo',
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_storefront_promo_chips_active (is_active, sort_order, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 011: metadatos editables de taxonomía (ícono y keywords por categoría).
-- ============================================================================
ALTER TABLE categories_rows
  ADD COLUMN IF NOT EXISTS icon VARCHAR(64) NOT NULL DEFAULT 'package';

ALTER TABLE categories_rows
  ADD COLUMN IF NOT EXISTS keywords LONGTEXT NULL;

-- ============================================================================
-- 012: galería de imágenes por banner, calendario y etiqueta (badge) por slide.
-- ============================================================================
ALTER TABLE storefront_campaigns
  MODIFY COLUMN display_mode VARCHAR(24) NOT NULL DEFAULT 'carousel';

ALTER TABLE storefront_campaign_slides
  ADD COLUMN IF NOT EXISTS starts_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS ends_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS badge_label VARCHAR(60) NULL,
  ADD COLUMN IF NOT EXISTS badge_tone VARCHAR(20) NOT NULL DEFAULT 'discount',
  ADD COLUMN IF NOT EXISTS image_opacity TINYINT UNSIGNED NOT NULL DEFAULT 100,
  ADD COLUMN IF NOT EXISTS overlay_opacity TINYINT UNSIGNED NOT NULL DEFAULT 18,
  ADD COLUMN IF NOT EXISTS image_interval_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 5;

CREATE TABLE IF NOT EXISTS storefront_campaign_slide_images (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slide_id BIGINT UNSIGNED NOT NULL,
  image_url VARCHAR(500) NOT NULL,
  image_alt VARCHAR(200) NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campaign_slide_image_url (slide_id, image_url),
  KEY idx_campaign_slide_images_order (slide_id, sort_order),
  CONSTRAINT fk_campaign_slide_images_slide FOREIGN KEY (slide_id)
    REFERENCES storefront_campaign_slides (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO storefront_campaign_slide_images
  (slide_id, image_url, image_alt, sort_order, is_primary)
SELECT id, image_url, image_alt, 0, 1
FROM storefront_campaign_slides
WHERE image_url IS NOT NULL AND image_url <> '';

-- ============================================================================
-- 013: accesos promocionales (chips) ligados a cada banner, no a una lista aparte.
-- ============================================================================
ALTER TABLE storefront_campaign_slides
  ADD COLUMN IF NOT EXISTS promo_chip_label VARCHAR(60) NULL,
  ADD COLUMN IF NOT EXISTS promo_chip_icon VARCHAR(40) NOT NULL DEFAULT 'tag',
  ADD COLUMN IF NOT EXISTS promo_chip_target_url VARCHAR(500) NULL;

-- ============================================================================
-- 014: ancho de imagen configurable por banner.
-- ============================================================================
ALTER TABLE storefront_campaign_slides
  ADD COLUMN IF NOT EXISTS image_width_pct TINYINT UNSIGNED NOT NULL DEFAULT 55;

-- ============================================================================
-- Registro de migraciones: crea schema_migrations (misma estructura que usa
-- scripts/migrate.php) y marca 001-014 como aplicadas, para que el runner no
-- vuelva a intentarlas. Este mismo archivo (015) lo marca el propio runner.
-- ============================================================================
CREATE TABLE IF NOT EXISTS schema_migrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  migration VARCHAR(191) NOT NULL UNIQUE,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- No incluye el propio '015_...sql': el runner (scripts/migrate.php) hace su propia
-- INSERT (sin IGNORE) para marcar este archivo como aplicado justo despues de correrlo;
-- insertarlo aqui tambien chocaba con esa llave unica (MySQL 1062) y rompia el runner
-- con un error sin detalle (ese INSERT vive fuera del try/catch por sentencia).
INSERT IGNORE INTO schema_migrations (migration) VALUES
  ('001_products_rows_tipos_reales.sql'),
  ('002_orders_rows_tipos_reales.sql'),
  ('003_order_events.sql'),
  ('004_users_rows_llaves.sql'),
  ('005_quitar_password_resets_rows.sql'),
  ('006_mail_settings.sql'),
  ('007_expandir_base_segura.sql'),
  ('008_storefront_campaigns.sql'),
  ('009_storefront_merchandising.sql'),
  ('010_category_banners.sql'),
  ('011_category_metadata.sql'),
  ('012_storefront_campaign_gallery.sql'),
  ('013_campaign_slide_promotions.sql'),
  ('014_campaign_image_layout.sql');

-- Sin SELECT de verificacion al final a proposito (ver nota en migrations/005): un SELECT
-- ejecutado por PDO::exec() bajo prepares nativos deja un resultado sin leer que rompe el
-- siguiente prepare()->execute() del runner (el INSERT que marca este archivo como
-- aplicado en schema_migrations). Para verificar, correr aparte:
--   SELECT COUNT(*) AS migraciones_registradas FROM schema_migrations;
