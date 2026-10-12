-- Relacion real categoria->subcategoria (distinta de macro_group_id, que es categoria->familia).
-- El campo "subcategories" (texto libre) no crea entidades reales ni se puede filtrar/enlazar;
-- esto permite que una categoria sea hija de otra categoria real, con su propio id/imagen/productos.
ALTER TABLE categories_rows
  ADD COLUMN IF NOT EXISTS parent_category_id VARCHAR(100) NULL,
  ADD INDEX IF NOT EXISTS idx_categories_parent (parent_category_id);
