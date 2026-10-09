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
$campaignMigration = (int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = '008_storefront_campaigns.sql'")->fetchColumn();
$assert($campaignMigration === 1, 'No quedó registrada la migración de campañas del storefront.');
$merchMigration = (int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = '009_storefront_merchandising.sql'")->fetchColumn();
$assert($merchMigration === 1, 'No quedó registrada la migración de merchandising.');
$categoryBannerMigration = (int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = '010_category_banners.sql'")->fetchColumn();
$assert($categoryBannerMigration === 1, 'No quedó registrada la migración de banners por categoría.');

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

$campaignTables = (int)$pdo->query("SELECT COUNT(DISTINCT TABLE_NAME) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (
      'storefront_campaigns', 'storefront_campaign_slides', 'storefront_campaign_products'
    )")->fetchColumn();
$assert($campaignTables === 3, 'Falta alguna tabla dedicada a campañas del storefront.');
$merchTables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storefront_promo_chips'")->fetchColumn();
$assert($merchTables === 1, 'Falta la tabla de accesos promocionales.');
$displayModeColumn = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storefront_campaigns' AND COLUMN_NAME = 'display_mode'")->fetchColumn();
$assert($displayModeColumn === 1, 'La campaña no tiene presentación configurable.');
$imageFitColumn = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storefront_campaign_slides' AND COLUMN_NAME = 'image_fit'")->fetchColumn();
$assert($imageFitColumn === 1, 'Las diapositivas no tienen ajuste de imagen configurable.');
$categoryBannerColumns = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories_rows' AND COLUMN_NAME IN ('banner_image_url', 'banner_alt', 'description')")->fetchColumn();
$assert($categoryBannerColumns === 3, 'Faltan campos de banner o descripción en categories_rows.');
$homeCampaign = $pdo->query("SELECT code, name, is_active, rotation_seconds FROM storefront_campaigns WHERE code = 'store-home'")->fetch();
$assert($homeCampaign && $homeCampaign['name'] === 'Portada de la tienda' && (int)$homeCampaign['is_active'] === 1 && (int)$homeCampaign['rotation_seconds'] === 7, 'No se creó correctamente la campaña inicial de tienda.');
$campaignFk = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storefront_campaign_products'
      AND CONSTRAINT_NAME = 'fk_storefront_campaign_products_product'")->fetchColumn();
$assert($campaignFk === 1, 'La relación de productos destacados no tiene su clave foránea.');

echo "Migraciones verificadas: claves, tipos, fecha, correo, historial y esquema de campañas correctos.\n";
