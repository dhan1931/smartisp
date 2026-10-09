-- Galerias, formatos editoriales, etiquetas y calendario por banner.
-- Las imagenes actuales pasan a ser la imagen principal de su galeria.

ALTER TABLE storefront_campaigns
  MODIFY COLUMN display_mode VARCHAR(24) NOT NULL DEFAULT 'carousel';

ALTER TABLE storefront_campaign_slides
  ADD COLUMN starts_at DATETIME NULL,
  ADD COLUMN ends_at DATETIME NULL,
  ADD COLUMN badge_label VARCHAR(60) NULL,
  ADD COLUMN badge_tone VARCHAR(20) NOT NULL DEFAULT 'discount',
  ADD COLUMN image_opacity TINYINT UNSIGNED NOT NULL DEFAULT 100,
  ADD COLUMN overlay_opacity TINYINT UNSIGNED NOT NULL DEFAULT 18,
  ADD COLUMN image_interval_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 5;

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
