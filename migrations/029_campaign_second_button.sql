-- Segundo boton opcional por banner (ej. uno relleno + uno de borde transparente), disponible
-- en todas las zonas (portada, secundarios, Nosotros, Servicios) via el mismo editor de banners.
ALTER TABLE storefront_campaign_slides
  ADD COLUMN IF NOT EXISTS button2_text VARCHAR(60) NULL,
  ADD COLUMN IF NOT EXISTS button2_url VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS button2_visible TINYINT(1) NOT NULL DEFAULT 0;
