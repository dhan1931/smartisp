-- Inventario por pedido (pantalla Inventario del panel). Cada pedido recuerda qué hizo con el
-- stock de sus artículos controlados: reserved (apartado), consumed (ya salió) o released (no
-- ocupa: cotización, cancelado). Ver api/shop.php. NULL = pedido anterior a esto, sin stock
-- apartado. Sin esta columna la API sigue validando disponibilidad pero no aparta stock.
ALTER TABLE orders_rows ADD COLUMN IF NOT EXISTS stock_state VARCHAR(20) NULL;
