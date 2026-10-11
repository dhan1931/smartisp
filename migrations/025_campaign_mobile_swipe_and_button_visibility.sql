-- Deslizar en telefono (independiente del layout de escritorio) para los modos de banners en
-- paralelo (dos/tres/cuadricula), y opcion de ocultar el boton del banner por slide.
ALTER TABLE storefront_campaigns
  ADD COLUMN IF NOT EXISTS mobile_swipe TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE storefront_campaign_slides
  ADD COLUMN IF NOT EXISTS button_visible TINYINT(1) NOT NULL DEFAULT 1;
