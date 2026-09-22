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

    $stmt = $pdo->query("SHOW TABLES LIKE 'products'");
    if ($stmt && $stmt->fetch()) {
        $tbl = 'products';
        return $tbl;
    }

    $tbl = 'products_rows';
    return $tbl;
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

    return [
        'id'          => $id,
        'name'        => $name,
        'description' => $desc,
        'price'       => $price,
        'category'    => $cat,
        'subcategory' => $subcat,
        'imageUrl'    => $img,
        'externalUrl' => $ext,
        'sku'         => $sku,
        'visible'     => $visible
    ];
}
