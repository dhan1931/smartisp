-- Favoritos de cada cliente en la base: antes vivían en la sesión PHP y se perdían al cerrar sesión
-- o cambiar de dispositivo. Idempotente.
CREATE TABLE IF NOT EXISTS customer_wishlist (
  user_id VARCHAR(191) NOT NULL,
  product_id VARCHAR(191) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, product_id),
  KEY idx_customer_wishlist_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
