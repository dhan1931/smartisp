-- Permite controlar cuanto ancho del banner ocupa la imagen, por banner.
ALTER TABLE storefront_campaign_slides
  ADD COLUMN image_width_pct TINYINT UNSIGNED NOT NULL DEFAULT 55;
