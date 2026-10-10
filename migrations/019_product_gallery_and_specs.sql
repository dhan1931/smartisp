-- product_images ya existia (migracion 007, nunca se conecto a nada) con la estructura minima
-- para una galeria real por producto -- solo le faltaba texto alternativo por imagen.
-- specs_json/content_text/warranty_text son datos estructurados opcionales por producto
-- (especificaciones, contenido del paquete, garantia), editables desde el admin para que la
-- ficha de producto no dependa solo de texto libre en la descripcion.
ALTER TABLE product_images
  ADD COLUMN IF NOT EXISTS image_alt VARCHAR(200) NULL AFTER image_url;

ALTER TABLE products_rows
  ADD COLUMN IF NOT EXISTS specs_json LONGTEXT NULL,
  ADD COLUMN IF NOT EXISTS content_text TEXT NULL,
  ADD COLUMN IF NOT EXISTS warranty_text TEXT NULL;
