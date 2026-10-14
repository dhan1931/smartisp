-- Permite una segunda linea de titulo con color propio (como el mock: "Tecnologia que impulsa"
-- en un color y "tus proyectos" en otro), una segunda linea de eyebrow/etiqueta (el textito
-- "SOBRE NOSOTROS" arriba del titulo), y un color propio para esa etiqueta -- hasta ahora
-- siempre una sola linea con el azul fijo del CSS, sin poder cambiarse por banner.
ALTER TABLE storefront_campaign_slides
  ADD COLUMN IF NOT EXISTS title_line2 VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS title_line2_color VARCHAR(7) NULL,
  ADD COLUMN IF NOT EXISTS eyebrow_line2 VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS eyebrow_color VARCHAR(7) NULL;
