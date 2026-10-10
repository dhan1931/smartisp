-- Encuadre independiente por imagen dentro de la galeria de cada banner.
ALTER TABLE storefront_campaign_slide_images
  ADD COLUMN focal_x TINYINT UNSIGNED NOT NULL DEFAULT 50,
  ADD COLUMN focal_y TINYINT UNSIGNED NOT NULL DEFAULT 50,
  ADD COLUMN zoom_pct SMALLINT UNSIGNED NOT NULL DEFAULT 100;
