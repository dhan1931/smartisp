-- Color de fondo independiente para el panel de texto de cada banner.
ALTER TABLE storefront_campaign_slides
  ADD COLUMN IF NOT EXISTS copy_background_color CHAR(7) NOT NULL DEFAULT '#ffffff';
