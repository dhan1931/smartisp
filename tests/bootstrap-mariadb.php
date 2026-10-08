<?php
declare(strict_types=1);

$database = getenv('MYSQL_DATABASE') ?: '';
$host = getenv('MYSQL_HOST') ?: '127.0.0.1';
if (!preg_match('/(?:^|_)ci$/i', $database) || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Este fixture solo se permite en una MariaDB local cuyo nombre de base termina en _ci.\n");
    exit(2);
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $host,
    getenv('MYSQL_PORT') ?: '3306',
    $database
);
$pdo = new PDO($dsn, getenv('MYSQL_USER') ?: '', getenv('MYSQL_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$schema = [
    "CREATE TABLE products_rows (
        id TEXT NULL, name TEXT NULL, description TEXT NULL, price TEXT NULL,
        category TEXT NULL, image_url TEXT NULL, visible TEXT NULL,
        created_at TEXT NULL, updated_at TEXT NULL, external_url TEXT NULL,
        sku TEXT NULL, subcategory TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE orders_rows (
        id TEXT NULL, user_id TEXT NULL, total TEXT NULL, status TEXT NULL,
        items TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL,
        payment_status TEXT NULL, payment_provider TEXT NULL, shipping TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE users_rows (
        id TEXT NULL, email TEXT NULL, password_hash TEXT NULL, name TEXT NULL,
        surname TEXT NULL, phone TEXT NULL, created_at TEXT NULL, role TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE password_resets_rows (
        `COL 1` VARCHAR(64) NULL, `COL 2` VARCHAR(36) NULL,
        `COL 3` VARCHAR(29) NULL, `COL 4` VARCHAR(29) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE settings_rows (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(191) NOT NULL UNIQUE,
        setting_value LONGTEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($schema as $statement) $pdo->exec($statement);

$pdo->exec("INSERT INTO products_rows (id, name, price, visible, created_at, updated_at)
    VALUES ('product-ci', 'Producto de prueba', '12.50', '1', '2026-10-01 10:00:00', '2026-10-01 10:00:00')");
$pdo->exec("INSERT INTO orders_rows (id, user_id, total, status, created_at, updated_at)
    VALUES ('order-ci', 'user-ci', '12.50', 'received', '2026-10-01 10:00:00.123456+00', '2026-10-01 10:00:00.123456+00')");
$pdo->exec("INSERT INTO users_rows (id, email, created_at, role)
    VALUES ('user-ci', 'ci@example.test', '2026-10-01 10:00:00.123456+00', 'customer')");
$pdo->exec("INSERT INTO password_resets_rows (`COL 1`, `COL 2`, `COL 3`, `COL 4`) VALUES ('old', 'token', 'expiry', 'created')");
$pdo->exec("INSERT INTO settings_rows (setting_key, setting_value) VALUES
    ('admin_email', 'ops@example.test'), ('smtp_host', 'smtp.example.test')");

echo "MariaDB CI fixture initialized.\n";
