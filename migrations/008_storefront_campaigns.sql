-- Campanas editoriales del storefront: diapositivas y productos destacados.
-- Aditiva e independiente de settings_rows; no modifica catalogo ni contenido existente.

CREATE TABLE IF NOT EXISTS storefront_campaigns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(150) NOT NULL,
  placement VARCHAR(40) NOT NULL DEFAULT 'home',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  rotation_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_storefront_campaigns_code (code),
  KEY idx_storefront_campaigns_active_dates (placement, is_active, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storefront_campaign_slides (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  eyebrow VARCHAR(100) NULL,
  title VARCHAR(180) NOT NULL,
  subtitle VARCHAR(500) NULL,
  button_text VARCHAR(60) NOT NULL DEFAULT 'Explorar',
  target_url VARCHAR(500) NOT NULL DEFAULT '/tienda.html#catalogo',
  image_url VARCHAR(500) NOT NULL,
  image_alt VARCHAR(200) NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_storefront_campaign_slides_order (campaign_id, is_active, sort_order),
  CONSTRAINT fk_storefront_campaign_slides_campaign FOREIGN KEY (campaign_id)
    REFERENCES storefront_campaigns (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storefront_campaign_products (
  campaign_id BIGINT UNSIGNED NOT NULL,
  product_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (campaign_id, product_id),
  KEY idx_storefront_campaign_products_order (campaign_id, sort_order),
  KEY idx_storefront_campaign_products_product (product_id),
  CONSTRAINT fk_storefront_campaign_products_campaign FOREIGN KEY (campaign_id)
    REFERENCES storefront_campaigns (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_storefront_campaign_products_product FOREIGN KEY (product_id)
    REFERENCES products_rows (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO storefront_campaigns (code, name, placement, is_active, rotation_seconds)
VALUES ('store-home', 'Portada de la tienda', 'home', 1, 7);
