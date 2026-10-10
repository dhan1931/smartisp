-- Las "familias" del sidebar de tienda.html (Computacion y Equipos, Componentes y
-- Almacenamiento, etc.) vivian hardcodeadas en un array de JS dentro de tienda.html, con una
-- etiqueta "subcategories" que en realidad listaba nombres de categorias reales, no
-- subcategorias -- una deuda tecnica que hacia que el sidebar no coincidiera con las
-- categorias reales de admin/categoria.php/categorias-destacadas.php. Esta migracion las
-- convierte en datos reales, editables desde admin.
CREATE TABLE IF NOT EXISTS category_macro_groups (
  `id` VARCHAR(100) NOT NULL PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `icon` VARCHAR(64) NOT NULL DEFAULT 'package',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE categories_rows
  ADD COLUMN IF NOT EXISTS macro_group_id VARCHAR(100) NULL;

-- Las mismas 11 familias que ya existian hardcodeadas, ahora como filas reales.
INSERT INTO category_macro_groups (id, name, icon, sort_order) VALUES
  ('computacion', 'Computación y Equipos', 'laptop', 1),
  ('componentes', 'Componentes y Almacenamiento', 'hard-drive', 2),
  ('redes', 'Redes y Conectividad', 'network', 3),
  ('monitores', 'Monitores y Pantallas', 'monitor', 4),
  ('perifericos', 'Periféricos y Accesorios', 'mouse', 5),
  ('impresion', 'Impresión y Puntos de Venta', 'printer', 6),
  ('seguridad', 'Seguridad y Vigilancia', 'shield-check', 7),
  ('energia', 'Energía y Climatización', 'zap', 8),
  ('telefonia', 'Telefonía y Móviles', 'smartphone', 9),
  ('gaming', 'Gaming y Hogar', 'gamepad-2', 10),
  ('software', 'Software y Licencias', 'file-code', 11),
  ('otros', 'Otras Soluciones IT', 'package', 99)
ON DUPLICATE KEY UPDATE name = VALUES(name), icon = VALUES(icon), sort_order = VALUES(sort_order);

-- Asignacion inicial: las categorias reales que coincidian EXACTO con las listas hardcodeadas
-- de antes. Lo que no calce aqui (categorias reales que antes solo se bucketeaban por
-- coincidencia de palabras clave en JS) queda sin asignar -- scripts/backfill-macro-groups.php
-- lo completa una sola vez replicando esa misma logica, y desde ahi admin lo controla a mano.
UPDATE categories_rows SET macro_group_id = 'computacion' WHERE name IN ('Computadores Portátiles','Computadores de Escritorio','Tabletas Digitales');
UPDATE categories_rows SET macro_group_id = 'componentes' WHERE name IN ('Componentes Informáticos','Memorias RAM','Almacenamiento');
UPDATE categories_rows SET macro_group_id = 'redes' WHERE name IN ('Redes y Comunicaciones','Cables y Adaptadores');
UPDATE categories_rows SET macro_group_id = 'monitores' WHERE name IN ('Monitores','Televisores y Pantallas');
UPDATE categories_rows SET macro_group_id = 'perifericos' WHERE name IN ('Periféricos de Entrada','Audio y Auriculares','Accesorios Generales','Mochilas y Soportes');
UPDATE categories_rows SET macro_group_id = 'impresion' WHERE name IN ('Impresoras y Multifuncionales','Consumibles de Impresión','Puntos de Venta');
UPDATE categories_rows SET macro_group_id = 'seguridad' WHERE name IN ('Seguridad y Vigilancia');
UPDATE categories_rows SET macro_group_id = 'energia' WHERE name IN ('Protección de Energía','Climatización y Ventilación');
UPDATE categories_rows SET macro_group_id = 'telefonia' WHERE name IN ('Celulares y Smartphones','Dispositivos Vestibles');
UPDATE categories_rows SET macro_group_id = 'gaming' WHERE name IN ('Gaming y Videojuegos','Consolas de Videojuegos','Sillas Gaming','Audio y Sonido para Hogar','Electrodomésticos y Línea Blanca');
UPDATE categories_rows SET macro_group_id = 'software' WHERE name IN ('Software y Licencias');
