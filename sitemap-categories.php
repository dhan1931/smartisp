<?php
/**
 * SmartISP - Sitemap XML dinamico de categorias publicas.
 *
 * Lista categorias y subcategorias con productos visibles. Cada URL apunta a
 * /categoria/<slug>/, una pagina HTML indexable con enlaces reales a productos.
 */

header('Content-Type: application/xml; charset=utf-8');

$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smartisp_sitemap_categories.xml';
$cacheTime = 21600; // 6 horas

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime) && filesize($cacheFile) > 400) {
    readfile($cacheFile);
    exit;
}

require_once __DIR__ . '/api/db.php';

function slugifyCategorySitemap(string $text): string {
    $clean = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    if (!$clean) $clean = $text;
    $clean = preg_replace('~[^\\pL\\d]+~u', '-', $clean);
    $clean = trim($clean, '-');
    $clean = preg_replace('~-+~', '-', $clean);
    $clean = strtolower($clean);
    return !empty($clean) ? substr($clean, 0, 90) : 'categoria';
}

$siteUrl = 'https://smart-isp.com.ec';
$pdo = getDbConnection();

ob_start();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

if ($pdo) {
    try {
        $pTable = getProductsTableName($pdo);
        ensureProductTableColumns($pdo, $pTable);
        $stmt = $pdo->query("
            SELECT category, subcategory, MAX(updated_at) AS updated_at, COUNT(*) AS total
            FROM `$pTable`
            WHERE visible = 1 AND TRIM(category) <> ''
            GROUP BY category, subcategory
            HAVING total > 0
            ORDER BY category ASC, subcategory ASC
        ");

        $seen = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $category = trim((string)($row['category'] ?? ''));
            $subcategory = trim((string)($row['subcategory'] ?? ''));
            $names = array_filter([$category, $subcategory], fn($v) => $v !== '');

            foreach ($names as $name) {
                $slug = slugifyCategorySitemap($name);
                if (isset($seen[$slug])) continue;
                $seen[$slug] = true;
                $updatedAt = !empty($row['updated_at']) ? date('Y-m-d', strtotime($row['updated_at'])) : date('Y-m-d');

                echo "  <url>\n";
                echo "    <loc>" . htmlspecialchars($siteUrl . '/categoria/' . rawurlencode($slug) . '/', ENT_XML1, 'UTF-8') . "</loc>\n";
                echo "    <lastmod>{$updatedAt}</lastmod>\n";
                echo "    <changefreq>weekly</changefreq>\n";
                echo "    <priority>0.7</priority>\n";
                echo "  </url>\n";
            }
        }
    } catch (Throwable $e) {
        error_log('Error generando sitemap-categories.php: ' . $e->getMessage());
    }
}

echo '</urlset>' . "\n";
$xmlOutput = ob_get_clean();

@file_put_contents($cacheFile, $xmlOutput);
echo $xmlOutput;
