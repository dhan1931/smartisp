<?php
// Feed de productos para Google Merchant Center: fichas gratuitas de Google Shopping y compras desde
// asistentes de IA. Solo productos visibles con precio y foto (Merchant rechaza los que no tienen imagen).
header('Content-Type: application/xml; charset=utf-8');

$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smartisp_feed_google_v1.xml';
if (is_file($cacheFile) && time() - filemtime($cacheFile) < 21600 && filesize($cacheFile) > 300) {
    readfile($cacheFile);
    exit;
}

require_once __DIR__ . '/api/lib/db.php';
require_once __DIR__ . '/api/lib/catalog-search.php';

$siteUrl = 'https://smart-isp.com.ec';
$x = static fn($s): string => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$cut = static fn(string $s, int $max): string => mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
$abs = static fn(string $u): string => str_starts_with($u, '/') ? $siteUrl . $u : $u;

ob_start();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n<channel>\n";
echo "<title>SmartISP Ecuador</title>\n<link>{$siteUrl}/</link>\n<description>Tecnología, redes y equipos TI con precios en dólares e IVA incluido.</description>\n";

$items = 0;
$pdo = getDbConnection();
if ($pdo) {
    try {
        $pTable = getProductsTableName($pdo);
        ensureProductTableColumns($pdo, $pTable);
        $stock = [];
        if (smartispTableExists($pdo, 'product_inventory')) {
            foreach ($pdo->query('SELECT product_id, GREATEST(CAST(quantity_available AS SIGNED) - CAST(quantity_reserved AS SIGNED), 0) AS free FROM product_inventory') as $inv) {
                $stock[(string)$inv['product_id']] = (int)$inv['free'];
            }
        }
        $stmt = $pdo->query("SELECT id, name, description, price, category, subcategory, image_url, sku, specs_json, visible FROM `$pTable` WHERE visible = 1 AND price > 0 AND image_url IS NOT NULL AND TRIM(image_url) <> '' ORDER BY id");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $p = normalizeProductRow($row);
            $images = array_values(array_filter(array_map($abs, $p['images']), static fn($u) => (bool)preg_match('#^https?://#i', $u)));
            if (!$images) continue;
            $id = (string)$row['id'];
            $desc = trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)$p['description'])));
            if ($desc === '') $desc = $p['name'];
            $brand = (string)(smartispProductBrand((string)$row['name']) ?? '');
            $mpn = smartispProductMpn($p['specs']);
            $type = trim($p['category'] . ($p['subcategory'] !== '' ? ' > ' . $p['subcategory'] : ''));
            echo "<item>\n";
            echo '  <g:id>' . $x($id) . "</g:id>\n";
            echo '  <g:title>' . $x($cut($p['name'], 150)) . "</g:title>\n";
            echo '  <g:description>' . $x($cut($desc, 5000)) . "</g:description>\n";
            echo '  <g:link>' . $x($siteUrl . smartispProductPath($id, (string)$row['name'])) . "</g:link>\n";
            echo '  <g:image_link>' . $x($images[0]) . "</g:image_link>\n";
            foreach (array_slice($images, 1, 10) as $extra) echo '  <g:additional_image_link>' . $x($extra) . "</g:additional_image_link>\n";
            echo '  <g:availability>' . (isset($stock[$id]) && $stock[$id] <= 0 ? 'out_of_stock' : 'in_stock') . "</g:availability>\n";
            echo '  <g:price>' . number_format((float)$p['price'], 2, '.', '') . " USD</g:price>\n";
            echo "  <g:condition>new</g:condition>\n";
            if ($brand !== '') echo '  <g:brand>' . $x($brand) . "</g:brand>\n";
            if ($mpn !== '') echo '  <g:mpn>' . $x($mpn) . "</g:mpn>\n";
            if ($brand === '' && $mpn === '') echo "  <g:identifier_exists>no</g:identifier_exists>\n";
            if ($type !== '') echo '  <g:product_type>' . $x($type) . "</g:product_type>\n";
            echo "</item>\n";
            $items++;
        }
    } catch (Throwable $e) {
        error_log('feed-google: ' . $e->getMessage());
    }
}
echo "</channel>\n</rss>\n";
$xml = (string)ob_get_clean();
if ($items > 0) @file_put_contents($cacheFile, $xml, LOCK_EX);
echo $xml;
