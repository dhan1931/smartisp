-- Proveedor de cada producto (Intcomex, Siglo21, Artecma…), solo para el panel: saber a quién pedirle la lista
-- de precios y filtrar por proveedor. NULL = sin asignar. Nunca se muestra en la tienda. Idempotente.
ALTER TABLE products_rows ADD COLUMN IF NOT EXISTS supplier VARCHAR(60) NULL;
CREATE INDEX IF NOT EXISTS idx_products_supplier ON products_rows (supplier);

-- Asignación inicial por el formato del código, solo en los que no tienen proveedor:
-- Intcomex: 2 letras, 3 dígitos, 3 caracteres y 2 dígitos (NW001UBQ79, con sufijo opcional -B1) o 1000 + 5 dígitos.
-- Siglo21: sus códigos de promoción empiezan con PP o PRO y terminan con la fecha (PPLOGCON920-01305420261008155818).
UPDATE products_rows SET supplier = 'Intcomex'
 WHERE (supplier IS NULL OR supplier = '')
   AND (UPPER(TRIM(sku)) REGEXP '^[A-Z]{2}[0-9]{3}[A-Z0-9]{3}[0-9]{2}(-[A-Z0-9]{1,3})?$' OR TRIM(sku) REGEXP '^1000[0-9]{5}$');
UPDATE products_rows SET supplier = 'Siglo21'
 WHERE (supplier IS NULL OR supplier = '')
   AND UPPER(TRIM(sku)) REGEXP '^(PP|PRO)[A-Z0-9]{6}.+[0-9]{14}$';
UPDATE products_rows SET supplier = 'Artecma'
 WHERE (supplier IS NULL OR supplier = '') AND UPPER(TRIM(sku)) LIKE 'ARTECMA-%';
