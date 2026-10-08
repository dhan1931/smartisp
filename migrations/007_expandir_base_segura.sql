-- SmartISP: expansion aditiva y gradual de la base.
-- Compatible con MariaDB 11.x / MySQL 8. Ejecutar por fases, no todo de una vez.
-- No elimina ni transforma datos existentes. Hacer backup y probar restauracion antes.
-- La aplicacion actual sigue usando products_rows, categories_rows, orders_rows.items
-- y orders_rows.shipping; las tablas nuevas requieren cambios coordinados en el backend.

-- ============================================================================
-- FASE 0: DIAGNOSTICO. Solo lectura; ejecutar primero y guardar resultados.
-- ============================================================================
SELECT VERSION() AS server_version, DATABASE() AS current_database;

SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('products_rows', 'orders_rows', 'users_rows', 'order_events')
GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
ORDER BY TABLE_NAME, INDEX_NAME;

SELECT 'products_rows' AS table_name, COUNT(*) AS row_count FROM products_rows
UNION ALL SELECT 'orders_rows', COUNT(*) FROM orders_rows
UNION ALL SELECT 'users_rows', COUNT(*) FROM users_rows
UNION ALL SELECT 'categories_rows', COUNT(*) FROM categories_rows;

-- Verifica que las columnas usadas por las nuevas FK tengan charset/collation compatibles.
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND ((TABLE_NAME = 'products_rows' AND COLUMN_NAME = 'id')
    OR (TABLE_NAME = 'orders_rows' AND COLUMN_NAME = 'id')
    OR (TABLE_NAME = 'users_rows' AND COLUMN_NAME = 'id'))
ORDER BY TABLE_NAME;

-- Inspeccion manual antes de normalizar articulos, envio o categorias.
SELECT id, LEFT(items, 600) AS items_sample, LEFT(shipping, 400) AS shipping_sample
FROM orders_rows
ORDER BY created_at DESC
LIMIT 10;

-- ============================================================================
-- FASE 1: INDICES PARA CONSULTAS ACTUALES.
-- Ejecutar solo despues de revisar la salida de indices de FASE 0.
-- MariaDB 11 permite IF NOT EXISTS; estos indices no imponen unicidad.
-- ============================================================================
CREATE INDEX IF NOT EXISTS idx_orders_user_created
  ON orders_rows (user_id, created_at);

-- category y subcategory son TEXT: se indexan prefijos. No acelera busquedas LIKE '%texto%'.
CREATE INDEX IF NOT EXISTS idx_products_visible_category_subcategory
  ON products_rows (visible, category(100), subcategory(100));

-- ============================================================================
-- FASE 2: CATEGORIAS NORMALIZADAS Y RELACION PRODUCTO-CATEGORIA.
-- No copiar categorias automaticamente: primero definir el mapeo desde categories_rows.
-- Collation utf8mb4_unicode_ci para coincidir con products_rows.id.
-- ============================================================================
CREATE TABLE IF NOT EXISTS categories_v2 (
  id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  name VARCHAR(255) NOT NULL,
  parent_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  slug VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_v2_slug (slug),
  KEY idx_categories_v2_parent (parent_id),
  CONSTRAINT fk_categories_v2_parent FOREIGN KEY (parent_id)
    REFERENCES categories_v2 (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_categories (
  product_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  category_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (product_id, category_id),
  KEY idx_product_categories_category (category_id),
  CONSTRAINT fk_pc_product FOREIGN KEY (product_id) REFERENCES products_rows (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_pc_category FOREIGN KEY (category_id) REFERENCES categories_v2 (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- FASE 3: GALERIA E INVENTARIO.
-- No cargar inventario con cero: cero significaria sin stock, no stock desconocido.
-- Activar reservas solo cuando el backend use transacciones y bloqueo de filas.
-- ============================================================================
CREATE TABLE IF NOT EXISTS product_images (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  image_url TEXT NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_product_images_product_order (product_id, sort_order),
  CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products_rows (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_inventory (
  product_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  quantity_available INT UNSIGNED NOT NULL DEFAULT 0,
  quantity_reserved INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (product_id),
  CONSTRAINT fk_product_inventory_product FOREIGN KEY (product_id) REFERENCES products_rows (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- FASE 4: ARTICULOS Y DIRECCIONES DE PEDIDOS.
-- orders_rows.items/shipping permanecen intactos como fuente durante la migracion.
-- order_events ya contiene el historial; no crear order_status_history duplicada.
-- ============================================================================
CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  product_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  product_name VARCHAR(255) NOT NULL,
  sku VARCHAR(191) NULL,
  quantity INT UNSIGNED NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order_items_order (order_id),
  KEY idx_order_items_product (product_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders_rows (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products_rows (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_shipping_addresses (
  order_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  recipient_name VARCHAR(255) NULL,
  phone VARCHAR(40) NULL,
  country VARCHAR(100) NULL,
  province VARCHAR(120) NULL,
  city VARCHAR(120) NULL,
  address_line1 VARCHAR(255) NULL,
  address_line2 VARCHAR(255) NULL,
  postal_code VARCHAR(30) NULL,
  notes TEXT NULL,
  PRIMARY KEY (order_id),
  CONSTRAINT fk_order_shipping_order FOREIGN KEY (order_id) REFERENCES orders_rows (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- FASE 5: PAGOS.
-- No almacenar numeros de tarjeta, CVV, claves ni datos sensibles de pago.
-- Crear esta tabla solo si el backend va a registrar intentos/transacciones multiples.
-- ============================================================================
CREATE TABLE IF NOT EXISTS order_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  provider VARCHAR(60) NOT NULL,
  provider_reference VARCHAR(191) NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  status VARCHAR(40) NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_payment_provider_reference (provider, provider_reference),
  KEY idx_order_payments_order_created (order_id, created_at),
  CONSTRAINT fk_order_payments_order FOREIGN KEY (order_id) REFERENCES orders_rows (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- FASE 6: ROLES Y PERMISOS GRANULARES.
-- La aplicacion actual autoriza con users_rows.role. No rellenar ni cambiar el guard
-- de admin hasta desplegar y probar el backend RBAC; estas tablas solas no dan permisos.
-- ============================================================================
CREATE TABLE IF NOT EXISTS auth_roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL,
  description VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_auth_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_auth_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_user_roles (
  user_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  KEY idx_auth_user_roles_role (role_id),
  CONSTRAINT fk_auth_user_roles_user FOREIGN KEY (user_id) REFERENCES users_rows (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_auth_user_roles_role FOREIGN KEY (role_id) REFERENCES auth_roles (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  KEY idx_auth_role_permissions_permission (permission_id),
  CONSTRAINT fk_auth_role_permissions_role FOREIGN KEY (role_id) REFERENCES auth_roles (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_auth_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES auth_permissions (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- FASE 7: VERIFICACION. Ejecutar despues de cada fase DDL aplicada.
-- ============================================================================
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'categories_v2', 'product_categories', 'product_images', 'product_inventory',
    'order_items', 'order_shipping_addresses', 'order_payments',
    'auth_roles', 'auth_permissions', 'auth_user_roles', 'auth_role_permissions'
  )
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('products_rows', 'orders_rows', 'order_items', 'product_images')
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME, INDEX_NAME;

-- No hay backfill incluido a proposito. Despues de crear y verificar tablas:
-- 1) adaptar backend para escribir en el esquema nuevo;
-- 2) migrar por lotes desde items/shipping/categories_rows con conteos de control;
-- 3) comparar totales y muestras;
-- 4) leer el esquema nuevo desde la app;
-- 5) dejar los campos antiguos hasta confirmar varios despliegues estables.
-- No ejecutar DROP TABLE, DROP COLUMN, TRUNCATE ni los cambios de migrations/005
-- como parte de esta expansion.
