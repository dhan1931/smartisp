-- Idempotencia del checkout: el navegador manda un UUID por intento de compra (Idempotency-Key).
-- Si el mismo cliente reintenta con esa clave en 24 h, la API devuelve el pedido ya creado en
-- vez de crear otro. NULL en pedidos anteriores o sin clave; el índice único admite varios NULL.
-- Sin esta columna la API ignora la clave y todo sigue como antes.
ALTER TABLE orders_rows ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(64) NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_idempotency ON orders_rows (idempotency_key);
