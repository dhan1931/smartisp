-- Ordena el catálogo: los dos proveedores traían nombres distintos para lo mismo
-- («Protección de Poder» / «Protección de Energía», «Memorias» / «Memorias RAM»…) y en
-- «Celulares» había solo accesorios mientras los teléfonos estaban en «Celulares y Smartphones».
-- Idempotente: se puede volver a correr. Los nombres viejos quedan como palabras clave de la
-- categoría destino (el importador los reconoce) y como redirección de /categoria/<slug-viejo>.

CREATE TABLE IF NOT EXISTS category_redirects (
  old_slug VARCHAR(191) NOT NULL PRIMARY KEY,
  old_name VARCHAR(255) NOT NULL,
  target_name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE categories_rows ADD COLUMN IF NOT EXISTS keywords TEXT NULL;
ALTER TABLE categories_rows ADD COLUMN IF NOT EXISTS macro_group_id VARCHAR(100) NULL;
ALTER TABLE categories_rows ADD COLUMN IF NOT EXISTS parent_category_id VARCHAR(100) NULL;

-- 1. Celulares: los accesorios salen a su propia categoría y los teléfonos quedan en «Celulares».
UPDATE products_rows SET category = 'Accesorios para Celulares' WHERE category = 'Celulares' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM products_rows WHERE category = 'Celulares y Smartphones') AS t) > 0;
UPDATE categories_rows SET name = 'Accesorios para Celulares' WHERE name = 'Celulares' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Celulares y Smartphones') AS t) > 0 AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Accesorios para Celulares') AS t) = 0;
UPDATE products_rows SET category = 'Celulares' WHERE category = 'Celulares y Smartphones';
UPDATE categories_rows SET name = 'Celulares' WHERE name = 'Celulares y Smartphones' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Celulares') AS t) = 0;
UPDATE products_rows SET category = 'Accesorios para Celulares' WHERE category = 'Celulares' AND name LIKE 'ACCESORIO %';

-- 2. Fusiones de categorías equivalentes.
UPDATE products_rows SET category = 'Electrodomésticos' WHERE category = 'Electrodomésticos y Línea Blanca';
UPDATE products_rows SET subcategory = 'Electrodomésticos' WHERE subcategory = 'Electrodomésticos y Línea Blanca';
UPDATE categories_rows SET name = 'Electrodomésticos' WHERE name = 'Electrodomésticos y Línea Blanca' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Electrodomésticos') AS t) = 0;
UPDATE products_rows SET category = 'Protección de Energía' WHERE category = 'Protección de Poder';
UPDATE products_rows SET subcategory = 'Protección de Energía' WHERE subcategory = 'Protección de Poder';
UPDATE categories_rows SET name = 'Protección de Energía' WHERE name = 'Protección de Poder' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Protección de Energía') AS t) = 0;
UPDATE products_rows SET category = 'Software' WHERE category = 'Software y Licencias';
UPDATE products_rows SET subcategory = 'Software' WHERE subcategory = 'Software y Licencias';
UPDATE categories_rows SET name = 'Software' WHERE name = 'Software y Licencias' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Software') AS t) = 0;
UPDATE products_rows SET category = 'Consolas y Videojuegos' WHERE category = 'Consolas de Videojuegos';
UPDATE products_rows SET subcategory = 'Consolas y Videojuegos' WHERE subcategory = 'Consolas de Videojuegos';
UPDATE categories_rows SET name = 'Consolas y Videojuegos' WHERE name = 'Consolas de Videojuegos' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Consolas y Videojuegos') AS t) = 0;
UPDATE products_rows SET category = 'Consolas y Videojuegos' WHERE category = 'Consolas';
UPDATE products_rows SET subcategory = 'Consolas y Videojuegos' WHERE subcategory = 'Consolas';
UPDATE categories_rows SET name = 'Consolas y Videojuegos' WHERE name = 'Consolas' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Consolas y Videojuegos') AS t) = 0;
UPDATE products_rows SET category = 'Consolas y Videojuegos' WHERE category = 'Videojuegos';
UPDATE products_rows SET subcategory = 'Consolas y Videojuegos' WHERE subcategory = 'Videojuegos';
UPDATE categories_rows SET name = 'Consolas y Videojuegos' WHERE name = 'Videojuegos' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Consolas y Videojuegos') AS t) = 0;
UPDATE products_rows SET category = 'Hogar y Oficina' WHERE category = 'Sillas Gaming';
UPDATE products_rows SET subcategory = 'Hogar y Oficina' WHERE subcategory = 'Sillas Gaming';
UPDATE categories_rows SET name = 'Hogar y Oficina' WHERE name = 'Sillas Gaming' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Hogar y Oficina') AS t) = 0;
UPDATE products_rows SET category = 'Hogar y Oficina' WHERE category = 'Muebles';
UPDATE products_rows SET subcategory = 'Hogar y Oficina' WHERE subcategory = 'Muebles';
UPDATE categories_rows SET name = 'Hogar y Oficina' WHERE name = 'Muebles' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Hogar y Oficina') AS t) = 0;
UPDATE products_rows SET category = 'Memorias' WHERE category = 'Memorias RAM';
UPDATE products_rows SET subcategory = 'Memorias' WHERE subcategory = 'Memorias RAM';
UPDATE categories_rows SET name = 'Memorias' WHERE name = 'Memorias RAM' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Memorias') AS t) = 0;
UPDATE products_rows SET category = 'Consumibles de Impresión' WHERE category = 'Consumibles y Media';
UPDATE products_rows SET subcategory = 'Consumibles de Impresión' WHERE subcategory = 'Consumibles y Media';
UPDATE categories_rows SET name = 'Consumibles de Impresión' WHERE name = 'Consumibles y Media' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Consumibles de Impresión') AS t) = 0;
UPDATE products_rows SET category = 'Impresoras y Multifuncionales' WHERE category = 'Impresoras y Escáneres';
UPDATE products_rows SET subcategory = 'Impresoras y Multifuncionales' WHERE subcategory = 'Impresoras y Escáneres';
UPDATE categories_rows SET name = 'Impresoras y Multifuncionales' WHERE name = 'Impresoras y Escáneres' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Impresoras y Multifuncionales') AS t) = 0;
UPDATE products_rows SET category = 'Cables y Adaptadores' WHERE category = 'Accesorios';
UPDATE products_rows SET subcategory = 'Cables y Adaptadores' WHERE subcategory = 'Accesorios';
UPDATE categories_rows SET name = 'Cables y Adaptadores' WHERE name = 'Accesorios' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Cables y Adaptadores') AS t) = 0;
UPDATE products_rows SET category = 'Audio y Video' WHERE category = 'Audio y Auriculares';
UPDATE products_rows SET subcategory = 'Audio y Video' WHERE subcategory = 'Audio y Auriculares';
UPDATE categories_rows SET name = 'Audio y Video' WHERE name = 'Audio y Auriculares' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Audio y Video') AS t) = 0;
UPDATE products_rows SET category = 'Audio y Video' WHERE category = 'Audio y Sonido para Hogar';
UPDATE products_rows SET subcategory = 'Audio y Video' WHERE subcategory = 'Audio y Sonido para Hogar';
UPDATE categories_rows SET name = 'Audio y Video' WHERE name = 'Audio y Sonido para Hogar' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Audio y Video') AS t) = 0;
UPDATE products_rows SET category = 'Dispositivos Vestibles' WHERE category = 'Tecnología Portátil';
UPDATE products_rows SET subcategory = 'Dispositivos Vestibles' WHERE subcategory = 'Tecnología Portátil';
UPDATE categories_rows SET name = 'Dispositivos Vestibles' WHERE name = 'Tecnología Portátil' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Dispositivos Vestibles') AS t) = 0;
UPDATE products_rows SET category = 'Redes y Conectividad' WHERE category = 'Redes';
UPDATE products_rows SET subcategory = 'Redes y Conectividad' WHERE subcategory = 'Redes';
UPDATE categories_rows SET name = 'Redes y Conectividad' WHERE name = 'Redes' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Redes y Conectividad') AS t) = 0;
UPDATE products_rows SET category = 'Periféricos de Entrada' WHERE category = 'Periféricos y Accesorios';
UPDATE products_rows SET subcategory = 'Periféricos de Entrada' WHERE subcategory = 'Periféricos y Accesorios';
UPDATE categories_rows SET name = 'Periféricos de Entrada' WHERE name = 'Periféricos y Accesorios' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Periféricos de Entrada') AS t) = 0;
UPDATE products_rows SET category = 'Maletines y Mochilas' WHERE category = 'Maletines';
UPDATE products_rows SET subcategory = 'Maletines y Mochilas' WHERE subcategory = 'Maletines';
UPDATE categories_rows SET name = 'Maletines y Mochilas' WHERE name = 'Maletines' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Maletines y Mochilas') AS t) = 0;
UPDATE products_rows SET category = 'Maletines y Mochilas' WHERE category = 'Mochilas y Soportes';
UPDATE products_rows SET subcategory = 'Maletines y Mochilas' WHERE subcategory = 'Mochilas y Soportes';
UPDATE categories_rows SET name = 'Maletines y Mochilas' WHERE name = 'Mochilas y Soportes' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Maletines y Mochilas') AS t) = 0;
UPDATE products_rows SET category = 'Monitores' WHERE category = 'Monitores y Pantallas';
UPDATE products_rows SET subcategory = 'Monitores' WHERE subcategory = 'Monitores y Pantallas';
UPDATE categories_rows SET name = 'Monitores' WHERE name = 'Monitores y Pantallas' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Monitores') AS t) = 0;
UPDATE products_rows SET category = 'Televisores' WHERE category = 'Televisores y Pantallas';
UPDATE products_rows SET subcategory = 'Televisores' WHERE subcategory = 'Televisores y Pantallas';
UPDATE categories_rows SET name = 'Televisores' WHERE name = 'Televisores y Pantallas' AND (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Televisores') AS t) = 0;

-- 3. Productos que el emparejamiento automático dejó en otra categoría.
UPDATE products_rows SET category = 'Generadores Eléctricos', subcategory = '' WHERE name LIKE '%GENERADOR%' AND category <> 'Generadores Eléctricos';
UPDATE products_rows SET category = 'Consumibles de Impresión' WHERE category = 'Impresoras y Multifuncionales' AND (name LIKE 'CONSUMIBLE%' OR name LIKE 'BOTELLA%' OR name LIKE 'TONER%' OR name LIKE 'TINTA%' OR name LIKE 'CINTA%' OR name LIKE 'CARTUCHO%' OR name LIKE 'RIBBON%');
UPDATE products_rows SET category = 'Computadores' WHERE name LIKE 'COMPUTADOR%' AND category IN ('Software', 'Almacenamiento');
UPDATE products_rows SET category = 'Componentes Informáticos' WHERE name LIKE 'CASE %' AND category IN ('Memorias', 'Gaming y Videojuegos');
UPDATE products_rows SET category = 'Almacenamiento' WHERE name LIKE 'SSD %' AND category = 'Gaming y Videojuegos';

-- 4. Filas de categoría que faltan y su familia.
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'celulares', 'Celulares', '[]', 'telefonia' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Celulares') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'telefonia' WHERE name = 'Celulares';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'accesorios-para-celulares', 'Accesorios para Celulares', '[]', 'telefonia' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Accesorios para Celulares') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'telefonia' WHERE name = 'Accesorios para Celulares';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'dispositivos-vestibles', 'Dispositivos Vestibles', '[]', 'telefonia' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Dispositivos Vestibles') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'telefonia' WHERE name = 'Dispositivos Vestibles';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'electrodomesticos', 'Electrodomésticos', '[]', 'gaming' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Electrodomésticos') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'gaming' WHERE name = 'Electrodomésticos';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'consolas-y-videojuegos', 'Consolas y Videojuegos', '[]', 'gaming' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Consolas y Videojuegos') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'gaming' WHERE name = 'Consolas y Videojuegos';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'hogar-y-oficina', 'Hogar y Oficina', '[]', 'gaming' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Hogar y Oficina') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'gaming' WHERE name = 'Hogar y Oficina';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'proteccion-de-energia', 'Protección de Energía', '[]', 'energia' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Protección de Energía') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'energia' WHERE name = 'Protección de Energía';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'generadores-electricos', 'Generadores Eléctricos', '[]', 'energia' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Generadores Eléctricos') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'energia' WHERE name = 'Generadores Eléctricos';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'software', 'Software', '[]', 'software' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Software') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'software' WHERE name = 'Software';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'memorias', 'Memorias', '[]', 'componentes' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Memorias') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'componentes' WHERE name = 'Memorias';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'consumibles-de-impresion', 'Consumibles de Impresión', '[]', 'impresion' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Consumibles de Impresión') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'impresion' WHERE name = 'Consumibles de Impresión';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'impresoras-y-multifuncionales', 'Impresoras y Multifuncionales', '[]', 'impresion' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Impresoras y Multifuncionales') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'impresion' WHERE name = 'Impresoras y Multifuncionales';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'cables-y-adaptadores', 'Cables y Adaptadores', '[]', 'redes' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Cables y Adaptadores') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'redes' WHERE name = 'Cables y Adaptadores';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'redes-y-conectividad', 'Redes y Conectividad', '[]', 'redes' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Redes y Conectividad') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'redes' WHERE name = 'Redes y Conectividad';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'audio-y-video', 'Audio y Video', '[]', 'perifericos' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Audio y Video') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'perifericos' WHERE name = 'Audio y Video';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'perifericos-de-entrada', 'Periféricos de Entrada', '[]', 'perifericos' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Periféricos de Entrada') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'perifericos' WHERE name = 'Periféricos de Entrada';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'maletines-y-mochilas', 'Maletines y Mochilas', '[]', 'perifericos' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Maletines y Mochilas') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'perifericos' WHERE name = 'Maletines y Mochilas';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'monitores', 'Monitores', '[]', 'monitores' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Monitores') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'monitores' WHERE name = 'Monitores';
INSERT IGNORE INTO categories_rows (id, name, subcategories, macro_group_id) SELECT 'televisores', 'Televisores', '[]', 'monitores' FROM DUAL WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM categories_rows WHERE name = 'Televisores') AS t) = 0;
UPDATE categories_rows SET macro_group_id = 'monitores' WHERE name = 'Televisores';

-- 5. Los nombres viejos quedan como palabras clave del destino (el importador los reconoce).
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Electrodomésticos y Línea Blanca') WHERE name = 'Electrodomésticos' AND (keywords IS NULL OR keywords NOT LIKE '%"Electrodomésticos y Línea Blanca"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Protección de Poder') WHERE name = 'Protección de Energía' AND (keywords IS NULL OR keywords NOT LIKE '%"Protección de Poder"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Software y Licencias') WHERE name = 'Software' AND (keywords IS NULL OR keywords NOT LIKE '%"Software y Licencias"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Consolas de Videojuegos') WHERE name = 'Consolas y Videojuegos' AND (keywords IS NULL OR keywords NOT LIKE '%"Consolas de Videojuegos"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Consolas') WHERE name = 'Consolas y Videojuegos' AND (keywords IS NULL OR keywords NOT LIKE '%"Consolas"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Videojuegos') WHERE name = 'Consolas y Videojuegos' AND (keywords IS NULL OR keywords NOT LIKE '%"Videojuegos"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Sillas Gaming') WHERE name = 'Hogar y Oficina' AND (keywords IS NULL OR keywords NOT LIKE '%"Sillas Gaming"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Muebles') WHERE name = 'Hogar y Oficina' AND (keywords IS NULL OR keywords NOT LIKE '%"Muebles"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Memorias RAM') WHERE name = 'Memorias' AND (keywords IS NULL OR keywords NOT LIKE '%"Memorias RAM"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Consumibles y Media') WHERE name = 'Consumibles de Impresión' AND (keywords IS NULL OR keywords NOT LIKE '%"Consumibles y Media"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Impresoras y Escáneres') WHERE name = 'Impresoras y Multifuncionales' AND (keywords IS NULL OR keywords NOT LIKE '%"Impresoras y Escáneres"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Accesorios') WHERE name = 'Cables y Adaptadores' AND (keywords IS NULL OR keywords NOT LIKE '%"Accesorios"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Audio y Auriculares') WHERE name = 'Audio y Video' AND (keywords IS NULL OR keywords NOT LIKE '%"Audio y Auriculares"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Audio y Sonido para Hogar') WHERE name = 'Audio y Video' AND (keywords IS NULL OR keywords NOT LIKE '%"Audio y Sonido para Hogar"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Tecnología Portátil') WHERE name = 'Dispositivos Vestibles' AND (keywords IS NULL OR keywords NOT LIKE '%"Tecnología Portátil"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Redes') WHERE name = 'Redes y Conectividad' AND (keywords IS NULL OR keywords NOT LIKE '%"Redes"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Periféricos y Accesorios') WHERE name = 'Periféricos de Entrada' AND (keywords IS NULL OR keywords NOT LIKE '%"Periféricos y Accesorios"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Maletines') WHERE name = 'Maletines y Mochilas' AND (keywords IS NULL OR keywords NOT LIKE '%"Maletines"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Mochilas y Soportes') WHERE name = 'Maletines y Mochilas' AND (keywords IS NULL OR keywords NOT LIKE '%"Mochilas y Soportes"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Monitores y Pantallas') WHERE name = 'Monitores' AND (keywords IS NULL OR keywords NOT LIKE '%"Monitores y Pantallas"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Televisores y Pantallas') WHERE name = 'Televisores' AND (keywords IS NULL OR keywords NOT LIKE '%"Televisores y Pantallas"%');
UPDATE categories_rows SET keywords = JSON_ARRAY_APPEND(IF(JSON_VALID(keywords) AND JSON_TYPE(keywords) = 'ARRAY', keywords, '[]'), '$', 'Celulares y Smartphones') WHERE name = 'Celulares' AND (keywords IS NULL OR keywords NOT LIKE '%"Celulares y Smartphones"%');

-- 6. Redirecciones de las URLs viejas /categoria/<slug>.
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('electrodomesticos-y-linea-blanca', 'Electrodomésticos y Línea Blanca', 'Electrodomésticos');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('proteccion-de-poder', 'Protección de Poder', 'Protección de Energía');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('software-y-licencias', 'Software y Licencias', 'Software');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('consolas-de-videojuegos', 'Consolas de Videojuegos', 'Consolas y Videojuegos');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('consolas', 'Consolas', 'Consolas y Videojuegos');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('videojuegos', 'Videojuegos', 'Consolas y Videojuegos');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('sillas-gaming', 'Sillas Gaming', 'Hogar y Oficina');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('muebles', 'Muebles', 'Hogar y Oficina');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('memorias-ram', 'Memorias RAM', 'Memorias');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('consumibles-y-media', 'Consumibles y Media', 'Consumibles de Impresión');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('impresoras-y-escaneres', 'Impresoras y Escáneres', 'Impresoras y Multifuncionales');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('accesorios', 'Accesorios', 'Cables y Adaptadores');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('audio-y-auriculares', 'Audio y Auriculares', 'Audio y Video');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('audio-y-sonido-para-hogar', 'Audio y Sonido para Hogar', 'Audio y Video');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('tecnologia-portatil', 'Tecnología Portátil', 'Dispositivos Vestibles');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('redes', 'Redes', 'Redes y Conectividad');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('perifericos-y-accesorios', 'Periféricos y Accesorios', 'Periféricos de Entrada');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('maletines', 'Maletines', 'Maletines y Mochilas');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('mochilas-y-soportes', 'Mochilas y Soportes', 'Maletines y Mochilas');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('monitores-y-pantallas', 'Monitores y Pantallas', 'Monitores');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('televisores-y-pantallas', 'Televisores y Pantallas', 'Televisores');
INSERT IGNORE INTO category_redirects (old_slug, old_name, target_name) VALUES ('celulares-y-smartphones', 'Celulares y Smartphones', 'Celulares');

-- 7. Borra las filas que quedaron vacías (sin productos ni subcategorías hijas).
DELETE c FROM categories_rows c
  LEFT JOIN products_rows p ON (p.category COLLATE utf8mb4_unicode_ci = c.name OR p.subcategory COLLATE utf8mb4_unicode_ci = c.name)
  LEFT JOIN categories_rows ch ON ch.parent_category_id = c.id
  WHERE c.name IN ('Accesorios', 'Audio y Auriculares', 'Audio y Sonido para Hogar', 'Celulares y Smartphones', 'Componentes y Almacenamiento', 'Computación y Equipos', 'Computación y equipos', 'Consolas', 'Consolas de Videojuegos', 'Consumibles y Media', 'Electrodomésticos y Línea Blanca', 'Energía y Climatización', 'Gaming y Hogar', 'Impresión y Puntos de Venta', 'Impresoras y Escáneres', 'Maletines', 'Memorias RAM', 'Mochilas y Soportes', 'Monitores y Pantallas', 'Muebles', 'Otras Soluciones IT', 'Periféricos y Accesorios', 'Protección de Poder', 'Redes', 'Sillas Gaming', 'Software y Licencias', 'Tecnología Portátil', 'Telefonía y Móviles', 'Televisores y Pantallas', 'Test', 'Videojuegos')
    AND p.id IS NULL AND ch.id IS NULL;
