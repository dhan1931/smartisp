-- orders_rows tenía el mismo problema que products_rows: todo TEXT, id sin PRIMARY KEY.
-- Verificado antes de escribir esto: los 12 id son únicos y no nulos; total trae solo
-- valores numéricos; status solo trae 'received'/'pending' (cabe en VARCHAR corto).
-- created_at de una fila trae microsegundos+zona horaria ('...437505+00'); MariaDB no puede
-- convertir ese formato directo a TIMESTAMP (lo probé: "Incorrect datetime value"), así que se
-- normaliza antes a segundos, sin la zona horaria (que ya era '+00', UTC, igual que el resto).

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
