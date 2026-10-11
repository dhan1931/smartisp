-- Permite achicar el alto del banner principal (hero) sin recortar contenido: en vez de
-- forzar una proporcion fija, el admin elige que tan alto se ve (60-100% del alto base) y
-- el frontend ajusta el aspect-ratio del contenedor completo. 100 = alto actual.
ALTER TABLE storefront_campaigns
  ADD COLUMN IF NOT EXISTS hero_height_pct SMALLINT UNSIGNED NOT NULL DEFAULT 100;
