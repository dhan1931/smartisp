-- Franja de confianza del banner (Productos/Clientes/Proyectos ejecutados/Soporte, como en el
-- mock). Se activa por banner (invocable), y cada valor puede sobreescribirse por banner; si no
-- se sobreescribe, usa el default global que se guarda en Configuracion (settings_rows, sin
-- columnas nuevas ahi -- reutiliza el mecanismo generico que ya existe). "Productos" no se
-- guarda nunca: siempre se calcula en vivo desde el catalogo real.
ALTER TABLE storefront_campaign_slides
  ADD COLUMN IF NOT EXISTS show_trust_stats TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS trust_stats_clients_override VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS trust_stats_projects_override VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS trust_stats_support_override VARCHAR(100) NULL;
