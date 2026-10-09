-- Presentaciones y accesos promocionales editables para la portada.
ALTER TABLE storefront_campaigns
  ADD COLUMN display_mode VARCHAR(16) NOT NULL DEFAULT 'carousel';

ALTER TABLE storefront_campaign_slides
  ADD COLUMN image_fit VARCHAR(12) NOT NULL DEFAULT 'cover';

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
