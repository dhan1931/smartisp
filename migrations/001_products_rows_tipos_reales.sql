-- DEV-20261005-023: products_rows tenía casi todas las columnas como TEXT (incluida `visible`
-- e `id`, sin PRIMARY KEY, sin ningún índice). Eso bloqueaba indexar el catálogo (el intento de
-- la fase anterior falló con "Specified key was too long" porque no se puede indexar TEXT sin
-- prefijo) y dejaba `created_at`/`updated_at` como texto libre, siempre NULL en los datos reales.
--
-- Verificado antes de escribir esto: los 2789 id son únicos y ninguno es NULL/vacío (seguro
-- agregar PRIMARY KEY); visible solo tiene los valores '0' y '1' (seguro convertir a TINYINT).

ALTER TABLE products_rows
  MODIFY COLUMN id VARCHAR(191) NOT NULL,
  MODIFY COLUMN visible TINYINT(1) NOT NULL DEFAULT 1,
  MODIFY COLUMN created_at TIMESTAMP NULL DEFAULT NULL,
  MODIFY COLUMN updated_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE products_rows ADD PRIMARY KEY (id);

ALTER TABLE products_rows ADD INDEX idx_visible_created (visible, created_at);
