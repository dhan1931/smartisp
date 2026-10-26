-- Costo de compra del producto, para margen bruto y valor del inventario a costo en el panel.
-- NULL = sin costo cargado (no cuenta para el margen). Nunca se expone en la tienda pública.
ALTER TABLE products_rows ADD COLUMN IF NOT EXISTS cost DECIMAL(10,2) NULL;
