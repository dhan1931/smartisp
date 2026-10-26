-- Las consultas por rango de fechas (dashboard, estadísticas, reportes) filtran por created_at.
CREATE INDEX IF NOT EXISTS idx_orders_created_at ON orders_rows (created_at);
