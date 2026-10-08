-- Tabla nueva: historial de cambios de estado de un pedido (DEV-20261005-019, centro de
-- pedidos). Sin esto, hoy un pedido solo tiene su estado actual en orders_rows.status, sin
-- rastro de quién lo cambió, cuándo, ni desde qué estado.

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
