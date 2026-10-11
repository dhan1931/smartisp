-- Tamano de fuente por slide para etiqueta/titulo/subtitulo (escala relativa sobre el tamano
-- responsivo que ya aplica cada contexto -- hero, tarjeta completa, tarjeta lateral -- en vez
-- de un px fijo que rompa el ajuste en movil).
ALTER TABLE storefront_campaign_slides
  ADD COLUMN IF NOT EXISTS eyebrow_size VARCHAR(10) NOT NULL DEFAULT 'md',
  ADD COLUMN IF NOT EXISTS title_size VARCHAR(10) NOT NULL DEFAULT 'md',
  ADD COLUMN IF NOT EXISTS subtitle_size VARCHAR(10) NOT NULL DEFAULT 'md';
