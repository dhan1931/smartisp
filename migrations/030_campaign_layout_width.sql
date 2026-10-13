-- Formato de visualizacion del banner: "contained" (dentro del ancho de contenido, con
-- margenes y bordes redondeados, como Nosotros/Servicios hoy) o "full" (ancho completo de
-- pantalla, como el hero principal de la tienda). Se guarda por campana/zona, no por slide,
-- para que el carrusel no cambie de ancho/alto al rotar entre banners.
-- Default por zona: store-home/store-secondary ya se ven casi a ancho completo hoy -> "full".
-- El resto (nosotros, servicios, categorias, etc.) ya se ve contenido hoy -> "contained".
ALTER TABLE storefront_campaigns
  ADD COLUMN IF NOT EXISTS layout_width ENUM('contained','full') NOT NULL DEFAULT 'contained';

UPDATE storefront_campaigns
  SET layout_width = 'full'
  WHERE code IN ('store-home', 'store-secondary');
