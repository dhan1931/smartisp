-- Historial de movimientos de stock (pantalla Inventario): ajustes manuales y lo que hace cada
-- pedido (reservar, liberar, despachar). Sin esta tabla el inventario funciona igual, sin historial.
CREATE TABLE IF NOT EXISTS inventory_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id VARCHAR(191) NOT NULL,
  delta_available INT NOT NULL DEFAULT 0,
  delta_reserved INT NOT NULL DEFAULT 0,
  reason VARCHAR(30) NOT NULL,
  order_id VARCHAR(191) NULL,
  user_id VARCHAR(191) NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inventory_movements_product (product_id, created_at),
  KEY idx_inventory_movements_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
