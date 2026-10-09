-- Metadatos editables de taxonomia usados por el editor y el storefront.
-- Aditiva: mantiene intactas todas las categorias y sus banners actuales.
ALTER TABLE categories_rows
  ADD COLUMN icon VARCHAR(64) NOT NULL DEFAULT 'package';

ALTER TABLE categories_rows
  ADD COLUMN keywords LONGTEXT NULL;
