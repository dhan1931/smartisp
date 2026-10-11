-- Dos cosas nuevas pedidas juntas para la portada de la tienda:
-- 1) Las mini-categorias (agrupadas por "familia"/macro-grupo) necesitan una imagen real en
--    vez de solo un icono generico, y el admin necesita poder ocultar una familia y reordenarlas
--    a mano en vez de depender solo del conteo automatico de productos.
-- 2) "Nuestras Marcas": destacador de marcas real (nombre + logo + activo + orden), nunca
--    existia infraestructura para esto -- antes se habia descartado explicitamente fabricar
--    datos de marcas sin respaldo real.
ALTER TABLE category_macro_groups
  ADD COLUMN IF NOT EXISTS image_url VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS visible TINYINT(1) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS storefront_brands (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  logo_url VARCHAR(500) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_storefront_brands_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
