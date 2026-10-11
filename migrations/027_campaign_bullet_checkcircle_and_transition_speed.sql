-- bullet_style ahora admite 'check-circle' (circulo relleno con check blanco adentro), mas largo
-- que 'check'/'circle'/'square' -- hay que ensanchar la columna para que no se trunque.
ALTER TABLE storefront_campaign_slides
  MODIFY COLUMN bullet_style VARCHAR(20) NOT NULL DEFAULT 'none';
-- Duracion total del fundido al rotar entre las imagenes de la galeria de un banner,
-- configurable por banner -- antes era un valor fijo (450ms) que varios banners sintieron
-- demasiado rapido.
ALTER TABLE storefront_campaign_slides
  ADD COLUMN IF NOT EXISTS image_transition_ms INT NOT NULL DEFAULT 1000;
