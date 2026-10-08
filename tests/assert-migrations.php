<?php
declare(strict_types=1);

$database = getenv('MYSQL_DATABASE') ?: '';
$host = getenv('MYSQL_HOST') ?: '127.0.0.1';
if (!preg_match('/(?:^|_)ci$/i', $database) || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Este test solo se permite en una MariaDB local cuyo nombre de base termina en _ci.\n");
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
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$applied = (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
$assert($applied === 6, "Se esperaban 6 migraciones registradas; hay $applied.");

$primaryKeys = (int)$pdo->query("SELECT COUNT(DISTINCT TABLE_NAME) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('products_rows', 'orders_rows', 'users_rows')
      AND INDEX_NAME = 'PRIMARY' AND COLUMN_NAME = 'id'")->fetchColumn();
$assert($primaryKeys === 3, 'Falta una clave primaria de id en productos, pedidos o usuarios.');

$uniqueEmail = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users_rows'
      AND INDEX_NAME = 'idx_email' AND NON_UNIQUE = 0")->fetchColumn();
$assert($uniqueEmail === 1, 'users_rows.email no quedó protegido por el índice único.');

$product = $pdo->query("SELECT DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products_rows' AND COLUMN_NAME = 'visible'")->fetch();
$assert($product && $product['DATA_TYPE'] === 'tinyint' && $product['IS_NULLABLE'] === 'NO', 'products_rows.visible no quedó tipado correctamente.');

$orderDate = $pdo->query("SELECT created_at FROM orders_rows WHERE id = 'order-ci'")->fetchColumn();
$assert($orderDate === '2026-10-01 10:00:00', "La fecha del pedido no se normalizó: $orderDate");
$orderTotalType = $pdo->query("SELECT DATA_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders_rows' AND COLUMN_NAME = 'total'")->fetchColumn();
$assert($orderTotalType === 'decimal', 'orders_rows.total no quedó como DECIMAL.');

$mail = $pdo->query('SELECT admin_email, smtp_host FROM mail_settings WHERE id = 1')->fetch();
$assert($mail && $mail['admin_email'] === 'ops@example.test' && $mail['smtp_host'] === 'smtp.example.test', 'La configuración de correo no se migró.');
$oldMailSettings = (int)$pdo->query("SELECT COUNT(*) FROM settings_rows
    WHERE setting_key IN ('admin_email', 'smtp_host')")->fetchColumn();
$assert($oldMailSettings === 0, 'Los ajustes de correo antiguos no se retiraron de settings_rows.');

$legacyTable = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets_rows'")->fetchColumn();
$assert((int)$legacyTable === 0, 'password_resets_rows debía eliminarse en el fixture aislado.');

$eventTable = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_events'")->fetchColumn();
$assert((int)$eventTable === 1, 'No se creó order_events.');

echo "Migraciones verificadas: 6 aplicadas; claves, tipos, fecha, historial y traspaso de correo correctos.\n";
