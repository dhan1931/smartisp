<?php
// Conexión y gestión de base de datos MySQL (Hostinger / phpMyAdmin)

function getDbConnection() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $config = require __DIR__ . '/config.php';

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['database'],
        $config['charset']
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $config['username'], $config['password'], $options);
        ensureAuxiliaryTables($pdo);
        return $pdo;
    } catch (PDOException $e) {
        error_log('Error de conexión a MySQL: ' . $e->getMessage());
        return null;
    }
}

// Repara tablas que fueron importadas desde Excel/CSV con nombres genéricos COL 1, COL 2...
function fixImportedTableColumns(PDO $pdo, string $tableName) {
    try {
        $cStmt = $pdo->query("DESCRIBE `$tableName`");
        $cols = $cStmt ? $cStmt->fetchAll(PDO::FETCH_COLUMN) : [];

        // Si la primera columna no comienza por 'COL', no requiere corrección
        if (empty($cols) || stripos($cols[0], 'col') !== 0) {
            return;
        }

        // Obtener la primera fila que contiene los nombres reales de las columnas
        $firstRowStmt = $pdo->query("SELECT * FROM `$tableName` LIMIT 1");
        $firstRow = $firstRowStmt ? $firstRowStmt->fetch(PDO::FETCH_ASSOC) : null;
        if (!$firstRow) return;

        $changes = [];
        $headerCheckCol = null;
        $headerCheckVal = null;
        $seen = [];

        foreach ($cols as $col) {
            $rawHeader = trim((string)($firstRow[$col] ?? ''));
            if ($rawHeader === '') continue;

            $cleanName = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($rawHeader));
            if ($cleanName === '' || is_numeric($cleanName[0])) {
                $cleanName = 'col_' . $cleanName;
            }

            if (isset($seen[$cleanName])) {
                $cleanName .= '_' . count($seen);
            }
            $seen[$cleanName] = true;

            if ($headerCheckCol === null) {
                $headerCheckCol = $cleanName;
                $headerCheckVal = $firstRow[$col];
            }

            // Tipo de columna compatible
            $type = 'TEXT NULL';
            if ($cleanName === 'id' || $cleanName === 'email') {
                $type = 'VARCHAR(191) NULL';
            } elseif ($cleanName === 'price' || $cleanName === 'precio') {
                $type = 'DECIMAL(10,2) NOT NULL DEFAULT 0.00';
            }

            $changes[] = "CHANGE `$col` `$cleanName` $type";
        }

        if (!empty($changes)) {
            $alterSql = "ALTER TABLE `$tableName` " . implode(', ', $changes);
            $pdo->exec($alterSql);

            // Eliminar la fila que sirvió de encabezado
            if ($headerCheckCol && $headerCheckVal) {
                $delStmt = $pdo->prepare("DELETE FROM `$tableName` WHERE `$headerCheckCol` = :val LIMIT 1");
                $delStmt->execute([':val' => $headerCheckVal]);
            }
        }
    } catch (Throwable $e) {
        error_log("Aviso fixImportedTableColumns ($tableName): " . $e->getMessage());
    }
}

// Crea las tablas complementarias si no existen todavía
function ensureAuxiliaryTables(PDO $pdo) {
    try {
        // Corregir tablas importadas con COL 1, COL 2...
        fixImportedTableColumns($pdo, 'users_rows');
        fixImportedTableColumns($pdo, 'products_rows');
        fixImportedTableColumns($pdo, 'orders_rows');

        // 1. Tabla de configuraciones del panel de control y personalizaciones visuales
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings_rows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(191) NOT NULL UNIQUE,
            setting_value LONGTEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 2. Tabla de categorías y subcategorías
        $pdo->exec("CREATE TABLE IF NOT EXISTS categories_rows (
            id VARCHAR(191) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            subcategories TEXT NULL,
            banner_image_url VARCHAR(500) NULL,
            banner_alt VARCHAR(200) NULL,
            description VARCHAR(500) NULL,
            icon VARCHAR(64) NOT NULL DEFAULT 'package',
            keywords LONGTEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 3. Asegurar tabla orders_rows si no existe
        $pdo->exec("CREATE TABLE IF NOT EXISTS orders_rows (
            id VARCHAR(191) PRIMARY KEY,
            user_id VARCHAR(191) NULL,
            customer_name VARCHAR(255) NULL,
            customer_email VARCHAR(255) NULL,
            customer_phone VARCHAR(50) NULL,
            items LONGTEXT NULL,
            total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(50) NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 4. Tabla de restablecimiento y recuperación de contraseñas
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(191) NOT NULL,
            token VARCHAR(191) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $e) {
        error_log('ensureAuxiliaryTables warning: ' . $e->getMessage());
    }
}

// Detecta si la tabla se llama products_rows o products
function getProductsTableName(PDO $pdo) {
    static $tbl = null;
    if ($tbl !== null) return $tbl;

    $stmt = $pdo->query("SHOW TABLES LIKE 'products_rows'");
    if ($stmt && $stmt->fetch()) {
        $tbl = 'products_rows';
        return $tbl;
    }

    $stmt = $pdo->query("SHOW TABLES LIKE 'product_rows'");
    if ($stmt && $stmt->fetch()) {
        $tbl = 'product_rows';
        return $tbl;
    }

    $stmt = $pdo->query("SHOW TABLES LIKE 'products'");
    if ($stmt && $stmt->fetch()) {
        $tbl = 'products';
        return $tbl;
    }

    $tbl = 'products_rows';
    return $tbl;
}

function ensureProductTableColumns(PDO $pdo, string $tableName): void {
    static $ensured = [];
    if (!empty($ensured[$tableName])) return;
    try {
        $stmt = $pdo->query("DESCRIBE `$tableName`");
        $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        if (empty($cols)) return;

        $needed = [
            'id'           => 'VARCHAR(191) NOT NULL PRIMARY KEY',
            'name'         => 'VARCHAR(255) NOT NULL',
            'description'  => 'TEXT NULL',
            'price'        => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
            'category'     => 'VARCHAR(100) NULL DEFAULT "General"',
            'subcategory'  => 'VARCHAR(100) NULL DEFAULT ""',
            'image_url'    => 'TEXT NULL',
            'external_url' => 'TEXT NULL',
            'sku'          => 'VARCHAR(100) NULL DEFAULT ""',
            'visible'      => 'TINYINT(1) NOT NULL DEFAULT 1',
            'created_at'   => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at'   => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ];

        foreach ($needed as $col => $def) {
            if (!in_array($col, $cols, true)) {
                $cleanDef = str_ireplace('PRIMARY KEY', '', $def);
                $pdo->exec("ALTER TABLE `$tableName` ADD COLUMN `$col` $cleanDef");
            }
        }
        // Índice para que el catálogo filtre/pagine en el servidor de BD en vez de en PHP (DEV-20261005-022).
        try {
            $pdo->exec("ALTER TABLE `$tableName` ADD INDEX IF NOT EXISTS idx_visible_created (visible, created_at)");
        } catch (Throwable $e) {
            error_log('ensureProductTableColumns (indice) warning: ' . $e->getMessage());
        }

        $ensured[$tableName] = true;
    } catch (Throwable $e) {
        error_log('ensureProductTableColumns warning: ' . $e->getMessage());
    }
}

// Detecta si la tabla de usuarios se llama users_rows o users
function getUsersTableName(PDO $pdo) {
    static $tbl = null;
    if ($tbl !== null) return $tbl;

    $stmt = $pdo->query("SHOW TABLES LIKE 'users_rows'");
    if ($stmt && $stmt->fetch()) {
        $tbl = 'users_rows';
        return $tbl;
    }

    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt && $stmt->fetch()) {
        $tbl = 'users';
        return $tbl;
    }

    $tbl = 'users_rows';
    return $tbl;
}

// Verifica si una URL proviene de distribuidores externos (Intcomex, Siglo 21, 1worldsync, etc.)
function isExternalSupplierImageUrl(?string $url): bool {
    if (empty($url)) return false;
    $url = trim($url);
    if (strpos($url, '/api/auth/') === 0 || strpos($url, 'api/auth/') === 0 || strpos($url, 'data:') === 0) {
        return false;
    }
    // Detección directa de mayoristas y sus redes de distribución
    if (stripos($url, 'intcomex') !== false) return true;
    if (stripos($url, '1worldsync.com') !== false) return true;
    if (stripos($url, 'siglo21.net') !== false) return true;
    if (stripos($url, 'wp-content/uploads') !== false) return true;

    // Cualquier URL absoluta http/https externa que no sea del propio dominio de SmartISP
    if (preg_match('/^https?:\/\//i', $url)) {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === 'smart-isp.com.ec' || $host === 'www.smart-isp.com.ec' || $host === 'images.unsplash.com') {
            return false;
        }
        return true;
    }
    return false;
}

function buildProductProxyImageUrl(string $id, string $rawUrl): string {
    $path = (string)parse_url($rawUrl, PHP_URL_PATH);
    $basename = basename($path);
    if (empty($basename) || strpos($basename, '.') === false) {
        $basename = 'producto.jpg';
    }
    $basename = explode('?', $basename)[0];
    $token = rtrim(strtr(base64_encode($rawUrl), '+/', '-_'), '=');
    return '/api/auth/product-image?id=' . rawurlencode($id) . '&t=' . $token . '&f=' . rawurlencode($basename) . '&w=640';
}

// Normaliza los nombres de columnas de productos de forma flexible
function normalizeProductRow(array $row): array {
    $id = (string)($row['id'] ?? ($row['product_id'] ?? ($row['codigo'] ?? ($row['COL 1'] ?? uniqid()))));
    $name = (string)($row['name'] ?? ($row['nombre'] ?? ($row['title'] ?? ($row['titulo'] ?? ($row['product_name'] ?? ($row['item'] ?? ($row['articulo'] ?? ($row['COL 2'] ?? ''))))))));
    $desc = (string)($row['description'] ?? ($row['descripcion'] ?? ($row['detalles'] ?? ($row['detail'] ?? ($row['COL 3'] ?? '')))));
    $price = (float)($row['price'] ?? ($row['precio'] ?? ($row['pvp'] ?? ($row['costo'] ?? ($row['COL 4'] ?? 0)))));
    $cat = (string)($row['category'] ?? ($row['categoria'] ?? ($row['tipo'] ?? ($row['linea'] ?? ($row['COL 5'] ?? 'General')))));
    $subcat = (string)($row['subcategory'] ?? ($row['subcategoria'] ?? ($row['COL 6'] ?? '')));
    $img = (string)($row['imageUrl'] ?? ($row['image_url'] ?? ($row['imagen'] ?? ($row['foto'] ?? ($row['url_imagen'] ?? ($row['COL 7'] ?? ''))))));
    $ext = (string)($row['externalUrl'] ?? ($row['external_url'] ?? ($row['enlace'] ?? ($row['COL 8'] ?? ''))));
    $sku = (string)($row['sku'] ?? ($row['codigo'] ?? ($row['part_number'] ?? ($row['COL 9'] ?? ''))));
    $visVal = $row['visible'] ?? ($row['COL 10'] ?? null);
    $visible = $visVal === null || $visVal == 1 || $visVal === true || $visVal === 'true' || $visVal === '1';

    // MÁSCARA Y PROXY DE IMÁGENES DE PROVEEDORES (Intcomex, Siglo 21, 1worldsync o cualquier distribuidor)
    // Oculta completamente el dominio y rutas del distribuidor para que el cliente final no lo vea
    $maskedImg = $img;
    if (!empty($img) && isExternalSupplierImageUrl($img)) {
        $maskedImg = buildProductProxyImageUrl($id, $img);
    }

    return [
        'id'          => $id,
        'name'        => $name,
        'description' => $desc,
        'price'       => $price,
        'category'    => $cat,
        'subcategory' => $subcat,
        'imageUrl'    => $maskedImg,
        'rawImageUrl' => $img,
        'externalUrl' => $ext,
        'sku'         => $sku,
        'visible'     => $visible,
        'specs'       => is_array($decodedSpecs = json_decode((string)($row['specs_json'] ?? ''), true)) ? $decodedSpecs : [],
        'contentText' => (string)($row['content_text'] ?? ''),
        'warrantyText' => (string)($row['warranty_text'] ?? '')
    ];
}

// Directorio persistente para archivos subidos en tiempo real (banners, fotos de producto
// subidas a mano). Vive FUERA de public_html a propósito: en Hostinger cada deploy reconstruye
// dist/ desde cero y reemplaza la publicación anterior entera -- cualquier archivo que no haya
// salido de ese build (como estos) no sobrevive. Un nivel arriba de DOCUMENT_ROOT es el mismo
// lugar donde ya vive el .env (ver api/config.php), que sí sobrevive a cada deploy.
function getSmartispUploadsDir(string $category): string {
    static $roots = [];
    if (isset($roots[$category])) return $roots[$category];
    $configured = trim((string)(getenv('SMARTISP_UPLOADS_DIR') ?: ''));
    if ($configured !== '') {
        $base = rtrim($configured, '/\\');
    } else {
        $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
        $base = $documentRoot !== ''
            ? dirname($documentRoot) . '/smartisp-uploads'
            : dirname(__DIR__) . '/uploads';
    }
    $dir = $base . '/' . $category;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $roots[$category] = $dir;
    return $dir;
}

// Familias visuales del sidebar de tienda (antes un array hardcodeado en tienda.html,
// ver migracion 017). Se usa tanto desde getDynamicCategoriesList() (para anotar cada
// categoria con su familia) como desde el admin (para gestionarlas directamente).
function getCategoryMacroGroups(PDO $pdo): array {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `category_macro_groups` (
            `id` VARCHAR(100) NOT NULL PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `icon` VARCHAR(64) NOT NULL DEFAULT 'package',
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
    $stmt = $pdo->query("SELECT id, name, icon FROM category_macro_groups ORDER BY sort_order, name");
    $groups = [];
    foreach (($stmt ? $stmt->fetchAll() : []) as $g) {
        $groups[] = ['id' => (string)$g['id'], 'name' => (string)$g['name'], 'icon' => (string)($g['icon'] ?: 'package')];
    }
    return $groups;
}

// getDynamicCategoriesList() unifica datos reales de products_rows con lo curado en
// categories_rows (banners/iconos/keywords). Vive aqui (no en router.php) porque tanto
// router.php (accion 'categories' del admin) como categoria.php (pagina publica) la
// necesitan, y categoria.php no puede requerir router.php completo.
function getDynamicCategoriesList(PDO $pdo, bool $visibleOnly = false): array {
    $pTable = getProductsTableName($pdo);
    // 1. Obtener todas las macrocategorías, subcategorías y conteo real de productos
    $visibilityFilter = $visibleOnly ? 'visible = 1 AND ' : '';
    $prodCatsStmt = $pdo->query("SELECT category, subcategory, COUNT(*) as p_count FROM `$pTable` WHERE $visibilityFilter category IS NOT NULL AND TRIM(category) != '' GROUP BY category, subcategory");
    $prodCatRows = $prodCatsStmt ? $prodCatsStmt->fetchAll() : [];

    $catMap = [];
    foreach ($prodCatRows as $r) {
        $cName = trim((string)$r['category']);
        if (!$cName) continue;
        if (!isset($catMap[$cName])) {
            $catMap[$cName] = [
                'count'         => 0,
                'subcategories' => []
            ];
        }
        $catMap[$cName]['count'] += (int)($r['p_count'] ?? 1);
        $sName = trim((string)($r['subcategory'] ?? ''));
        if ($sName && !in_array($sName, $catMap[$cName]['subcategories'], true)) {
            $catMap[$cName]['subcategories'][] = $sName;
        }
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `categories_rows` (
            `id` VARCHAR(100) NOT NULL PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `subcategories` LONGTEXT NULL,
            `banner_image_url` VARCHAR(500) NULL,
            `banner_alt` VARCHAR(200) NULL,
            `description` VARCHAR(500) NULL,
            `icon` VARCHAR(64) NOT NULL DEFAULT 'package',
            `keywords` LONGTEXT NULL,
            `macro_group_id` VARCHAR(100) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    $macroGroups = [];
    foreach (getCategoryMacroGroups($pdo) as $g) {
        $macroGroups[$g['id']] = $g;
    }
    $resolveMacroGroup = function (?string $groupId) use ($macroGroups): array {
        $groupId = (string)($groupId ?? '');
        return $macroGroups[$groupId] ?? ['id' => '', 'name' => '', 'icon' => ''];
    };

    $stmt = $pdo->query("SELECT * FROM categories_rows");
    $dbCats = $stmt ? $stmt->fetchAll() : [];
    $existingNames = [];

    $cats = [];
    foreach ($dbCats as $row) {
        $name = trim((string)$row['name']);
        if (!$name) continue;
        $existingNames[strtolower($name)] = true;
        $subs = [];
        if (!empty($row['subcategories'])) {
            $decoded = json_decode($row['subcategories'], true);
            if (is_array($decoded)) {
                $subs = $decoded;
            } else {
                $subs = array_filter(array_map('trim', explode(',', $row['subcategories'])));
            }
        }
        if (isset($catMap[$name])) {
            foreach ($catMap[$name]['subcategories'] as $ps) {
                if (!in_array($ps, $subs, true)) {
                    $subs[] = $ps;
                }
            }
        }
        $macro = $resolveMacroGroup($row['macro_group_id'] ?? null);
        $cats[] = [
            'id'            => (string)($row['id'] ?? uniqid('cat_')),
            'name'          => $name,
            'subcategories' => array_values($subs),
            'bannerImageUrl' => (string)($row['banner_image_url'] ?? ''),
            'bannerAlt' => (string)($row['banner_alt'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'icon' => (string)($row['icon'] ?? 'package'),
            'keywords' => is_array($decodedKeywords = json_decode((string)($row['keywords'] ?? ''), true))
                ? $decodedKeywords
                : array_values(array_filter(array_map('trim', explode(',', (string)($row['keywords'] ?? ''))))),
            'productCount'  => $catMap[$name]['count'] ?? 0,
            'macroGroupId'   => $macro['id'],
            'macroGroupName' => $macro['name'],
            'macroGroupIcon' => $macro['icon'],
            'updated_at'    => $row['updated_at'] ?? null
        ];
    }

    $toInsert = [];
    foreach ($catMap as $cName => $info) {
        if (!isset($existingNames[strtolower($cName)])) {
            $cId = preg_replace('/[^a-z0-9_-]/', '-', strtolower($cName));
            $cId = trim(preg_replace('/-+/', '-', $cId), '-');
            if (!$cId) $cId = uniqid('cat_');
            $newCat = [
                'id'            => $cId,
                'name'          => $cName,
                'subcategories' => array_values($info['subcategories']),
                'bannerImageUrl' => '',
                'bannerAlt' => '',
                'description' => '',
                'icon' => 'package',
                'keywords' => [],
                'productCount'  => $info['count'],
                'macroGroupId'   => '',
                'macroGroupName' => '',
                'macroGroupIcon' => '',
                'updated_at'    => date('Y-m-d H:i:s')
            ];
            $cats[] = $newCat;
            $toInsert[] = $newCat;
        }
    }

    if (!empty($toInsert)) {
        try {
            $ins = $pdo->prepare("INSERT INTO categories_rows (id, name, subcategories, banner_image_url, banner_alt, description) VALUES (:id, :name, :sub, :banner, :alt, :description) ON DUPLICATE KEY UPDATE name = VALUES(name), subcategories = VALUES(subcategories)");
            foreach ($toInsert as $tc) {
                $ins->execute([
                    ':id'   => $tc['id'],
                    ':name' => $tc['name'],
                    ':sub'  => json_encode($tc['subcategories'], JSON_UNESCAPED_UNICODE),
                    ':banner' => '',
                    ':alt' => '',
                    ':description' => ''
                ]);
            }
        } catch (Throwable $e) {}
    }

    usort($cats, function($a, $b) {
        return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
    });

    return $cats;
}
