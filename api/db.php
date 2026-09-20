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

// Crea las tablas complementarias si no existen todavía
function ensureAuxiliaryTables(PDO $pdo) {
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
}

// Detecta si la tabla se llama products_rows o products
function getProductsTableName(PDO $pdo) {
    static $tbl = null;
    if ($tbl !== null) return $tbl;

    $stmt = $pdo->query("SHOW TABLES LIKE 'products_rows'");
    if ($stmt->fetch()) {
        $tbl = 'products_rows';
        return $tbl;
    }

    $stmt = $pdo->query("SHOW TABLES LIKE 'products'");
    if ($stmt->fetch()) {
        $tbl = 'products';
        return $tbl;
    }

    // Si ninguna existe, crear products_rows por defecto
    $pdo->exec("CREATE TABLE IF NOT EXISTS products_rows (
        id VARCHAR(191) PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        category VARCHAR(100) NULL,
        subcategory VARCHAR(100) NULL,
        image_url TEXT NULL,
        external_url TEXT NULL,
        sku VARCHAR(100) NULL,
        visible TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $tbl = 'products_rows';
    return $tbl;
}

// Detecta si la tabla de usuarios se llama users_rows o users
function getUsersTableName(PDO $pdo) {
    static $tbl = null;
    if ($tbl !== null) return $tbl;

    $stmt = $pdo->query("SHOW TABLES LIKE 'users_rows'");
    if ($stmt->fetch()) {
        $tbl = 'users_rows';
        return $tbl;
    }

    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->fetch()) {
        $tbl = 'users';
        return $tbl;
    }

    // Crear users_rows si no existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS users_rows (
        id VARCHAR(191) PRIMARY KEY,
        email VARCHAR(191) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        name VARCHAR(100) NOT NULL,
        surname VARCHAR(100) NULL,
        phone VARCHAR(50) NULL,
        role VARCHAR(50) NOT NULL DEFAULT 'customer',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $tbl = 'users_rows';
    return $tbl;
}

// Normaliza los nombres de columnas de productos (snake_case / camelCase)
function normalizeProductRow(array $row): array {
    return [
        'id'          => (string)($row['id'] ?? uniqid()),
        'name'        => (string)($row['name'] ?? ($row['nombre'] ?? ($row['title'] ?? ''))),
        'description' => (string)($row['description'] ?? ($row['descripcion'] ?? '')),
        'price'       => (float)($row['price'] ?? ($row['precio'] ?? 0)),
        'category'    => (string)($row['category'] ?? ($row['categoria'] ?? 'General')),
        'subcategory' => (string)($row['subcategory'] ?? ($row['subcategoria'] ?? '')),
        'imageUrl'    => (string)($row['imageUrl'] ?? ($row['image_url'] ?? ($row['imagen'] ?? ''))),
        'externalUrl' => (string)($row['externalUrl'] ?? ($row['external_url'] ?? '')),
        'sku'         => (string)($row['sku'] ?? ''),
        'visible'     => !isset($row['visible']) || $row['visible'] == 1 || $row['visible'] === true || $row['visible'] === 'true'
    ];
}
