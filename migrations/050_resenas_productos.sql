-- Reseñas de productos: una por cliente y producto, de 1 a 5 estrellas. Las de compra verificada se
-- publican solas; las demás esperan revisión en el panel. El promedio y la cantidad quedan en el
-- producto para mostrarlos en el catálogo sin consultas extra. Idempotente.
CREATE TABLE IF NOT EXISTS product_reviews (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id VARCHAR(191) NOT NULL,
  user_id VARCHAR(191) NOT NULL,
  author_name VARCHAR(120) NOT NULL DEFAULT '',
  rating TINYINT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  verified TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  reply TEXT NULL,
  replied_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_reviews_user (product_id, user_id),
  KEY idx_product_reviews_product (product_id, status, created_at),
  KEY idx_product_reviews_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE products_rows ADD COLUMN IF NOT EXISTS rating_avg DECIMAL(3,2) NOT NULL DEFAULT 0;
ALTER TABLE products_rows ADD COLUMN IF NOT EXISTS rating_count INT UNSIGNED NOT NULL DEFAULT 0;
