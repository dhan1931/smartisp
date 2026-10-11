-- La imagen de la tarjeta de categoria es independiente del banner horizontal.
ALTER TABLE categories_rows ADD COLUMN IF NOT EXISTS icon_image_url VARCHAR(500) NULL;
