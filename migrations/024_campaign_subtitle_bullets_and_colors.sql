-- Subtitulo del banner como lista con vinetas (check/circulo/cuadrado, color elegido) en vez de
-- un parrafo corrido, y color de texto propio para titulo/subtitulo cuando el default no sirve
-- (ej. fondo de banner claro con card_layout=side).
ALTER TABLE storefront_campaign_slides
  ADD COLUMN IF NOT EXISTS bullet_style VARCHAR(10) NOT NULL DEFAULT 'none',
  ADD COLUMN IF NOT EXISTS bullet_color VARCHAR(7) NULL,
  ADD COLUMN IF NOT EXISTS title_color VARCHAR(7) NULL,
  ADD COLUMN IF NOT EXISTS subtitle_color VARCHAR(7) NULL;
