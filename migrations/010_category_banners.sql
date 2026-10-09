-- Contenido opcional para el encabezado visual de cada categoría.
-- Las filas actuales se conservan y reciben valores NULL para estos campos.

CREATE TABLE IF NOT EXISTS categories_rows (
  id VARCHAR(100) NOT NULL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  subcategories LONGTEXT NULL,
  banner_image_url VARCHAR(500) NULL,
  banner_alt VARCHAR(200) NULL,
  description VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE categories_rows ADD COLUMN banner_image_url VARCHAR(500) NULL;
ALTER TABLE categories_rows ADD COLUMN banner_alt VARCHAR(200) NULL;
ALTER TABLE categories_rows ADD COLUMN description VARCHAR(500) NULL;
