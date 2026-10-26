-- Actividad interna de pedidos y seguimiento de cotizaciones de servicio.
-- order_activity: notas privadas del equipo, correos enviados y avisos del sistema (p. ej. "creado
-- desde la cotización X"). Va aparte de order_events porque ahí to_status es obligatorio y los
-- reportes de tiempos leen esa tabla como historial de estados. Nunca se expone al cliente.
CREATE TABLE IF NOT EXISTS order_activity (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id VARCHAR(191) NOT NULL,
  kind VARCHAR(20) NOT NULL,
  actor VARCHAR(191) NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_order_activity (order_id, created_at),
  CONSTRAINT fk_order_activity_order FOREIGN KEY (order_id) REFERENCES orders_rows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE service_quote_requests
  ADD COLUMN IF NOT EXISTS next_action VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS next_action_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS order_id VARCHAR(191) NULL;

CREATE TABLE IF NOT EXISTS service_quote_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quote_id VARCHAR(191) NOT NULL,
  actor VARCHAR(191) NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_quote_notes (quote_id, created_at),
  CONSTRAINT fk_quote_notes_quote FOREIGN KEY (quote_id) REFERENCES service_quote_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
