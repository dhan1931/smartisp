<?php
// /llms-full.txt: el catálogo publicado en texto para asistentes de IA (complementa /llms.txt).
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smartisp_llms_full_v1.txt';
if (is_file($cacheFile) && time() - filemtime($cacheFile) < 21600 && filesize($cacheFile) > 200) {
    readfile($cacheFile);
    exit;
}

require_once __DIR__ . '/api/lib/db.php';
require_once __DIR__ . '/api/lib/catalog-search.php';

$siteUrl = rtrim((string)(getenv('SITE_URL') ?: 'https://smart-isp.com.ec'), '/');
$groups = [];
$total = 0;
$pdo = getDbConnection();
if ($pdo) {
    try {
        $pTable = getProductsTableName($pdo);
        $stock = smartispTableExists($pdo, 'product_inventory')
            ? '(SELECT GREATEST(CAST(quantity_available AS SIGNED) - CAST(quantity_reserved AS SIGNED), 0) FROM product_inventory WHERE product_id = p.id LIMIT 1)'
            : 'NULL';
        $stmt = $pdo->query("SELECT p.id, p.name, p.price, p.sku, TRIM(p.category) AS category, TRIM(p.subcategory) AS subcategory, $stock AS available_qty
            FROM `$pTable` p WHERE p.visible = 1 ORDER BY TRIM(p.category), TRIM(p.subcategory), p.name");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = smartispDisplayName((string)$row['name']);
            if ($name === '' || (string)$row['id'] === '') continue;
            $price = (float)$row['price'];
            $brand = smartispProductBrand($name);
            $details = [$price > 0 ? '$' . number_format($price, 2, '.', ',') : 'precio a cotizar'];
            if ($brand !== null) $details[] = 'marca ' . $brand;
            if (trim((string)$row['sku']) !== '') $details[] = 'SKU ' . trim((string)$row['sku']);
            if ($row['available_qty'] !== null && (int)$row['available_qty'] <= 0) $details[] = 'agotado';
            $url = $siteUrl . '/producto/' . rawurlencode((string)$row['id']) . '-' . smartispSlug($name, 80, 'articulo');
            $category = $row['category'] !== '' ? $row['category'] : 'Otros productos';
            $groups[$category][$row['subcategory']][] = '- [' . strtr($name, ['[' => '(', ']' => ')']) . '](' . $url . '): ' . implode(' · ', $details);
            $total++;
        }
    } catch (Throwable $e) {
        error_log('llms-full.txt: ' . $e->getMessage());
    }
}

$lines = ['# SmartISP Ecuador: catálogo completo', ''];
$lines[] = '> Los ' . number_format($total, 0, ',', '.') . ' productos publicados en ' . $siteUrl . ', por categoría, con precio en dólares (USD) con IVA incluido, marca y enlace a la ficha. Generado el ' . gmdate('Y-m-d') . '; el precio y la disponibilidad vigentes están en cada ficha.';
$lines[] = '';
$lines[] = 'Contacto, envíos, devoluciones y garantías: ' . $siteUrl . '/llms.txt';
$lines[] = '';
foreach ($groups as $category => $subgroups) {
    $count = array_sum(array_map('count', $subgroups));
    $lines[] = '## ' . $category . ' (' . $count . ($count === 1 ? ' producto' : ' productos') . ')';
    $lines[] = '';
    if ($category !== 'Otros productos') {
        $lines[] = 'Página de la categoría: ' . $siteUrl . '/categoria/' . smartispSlug($category, 90, 'categoria') . '/';
        $lines[] = '';
    }
    foreach ($subgroups as $subcategory => $items) {
        if ($subcategory !== '' && count($subgroups) > 1) {
            $lines[] = '### ' . $subcategory;
            $lines[] = '';
        }
        array_push($lines, ...$items);
        $lines[] = '';
    }
}

$output = implode("\n", $lines);
if ($total > 0) {
    $tmp = $cacheFile . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $output) !== false) @rename($tmp, $cacheFile);
}
echo $output;
