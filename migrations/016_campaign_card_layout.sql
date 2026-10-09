-- Permite elegir, por banner, entre imagen de fondo completa ("full") o imagen
-- lateral + panel de texto ("side"). Antes solo existia el estilo lateral, aplicado
-- sin distincion via CSS sin ambito que pisaba el estilo original de imagen completa.
ALTER TABLE storefront_campaign_slides
  ADD COLUMN IF NOT EXISTS card_layout VARCHAR(10) NOT NULL DEFAULT 'side';
