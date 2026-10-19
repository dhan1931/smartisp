-- Icono principal opcional por banner (ej. "server", "wifi") para tarjetas tipo
-- Servicios Especializados, al estilo del bloque estatico de Soluciones en editor-landing.html.
ALTER TABLE storefront_campaign_slides ADD COLUMN IF NOT EXISTS icon VARCHAR(20) NULL;
