<?php
/**
 * SmartISP - Generador Dinámico de Sitemap XML de Productos para Google Search
 * 
 * Expone todas las URLs únicas de los productos del catálogo con metadatos de imágenes,
 * fechas de modificación y prioridades de rastreo.
 */

header('Content-Type: application/xml; charset=utf-8');

$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smartisp_sitemap_products.xml';
$cacheTime = 21600; // 6 horas de caché

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime) && filesize($cacheFile) > 1000) {
    readfile($cacheFile);
    exit;
}

require_once __DIR__ . '/api/db.php';

function slugifySitemap(string $text): string {
    $clean = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    if (!$clean) $clean = $text;
    $clean = preg_replace('~[^\\pL\\d]+~u', '-', $clean);
    $clean = trim($clean, '-');
    $clean = preg_replace('~-+~', '-', $clean);
    $clean = strtolower($clean);
    return !empty($clean) ? substr($clean, 0, 80) : 'articulo';
}

$pdo = getDbConnection();
if (!$pdo) {
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
    exit;
}

$pTable = getProductsTableName($pdo);
ensureProductTableColumns($pdo, $pTable);
$siteUrl = 'https://smart-isp.com.ec';

ob_start();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
echo '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

try {
    $stmt = $pdo->query("SELECT id, name, updated_at, image_url, category FROM `$pTable` WHERE visible = 1 ORDER BY updated_at DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (string)$row['id'];
        $name = (string)$row['name'];
        if (empty($id) || empty($name)) continue;

        $slug = $id . '-' . slugifySitemap($name);
        $loc = $siteUrl . '/producto/' . rawurlencode($slug);
        
        $updatedAt = !empty($row['updated_at']) ? date('Y-m-d', strtotime($row['updated_at'])) : date('Y-m-d');
        
        $rawImg = (string)($row['image_url'] ?? '');
        $imgLoc = '';
        if (!empty($rawImg)) {
            $norm = normalizeProductRow($row);
            $imgLoc = $norm['imageUrl'];
            if (strpos($imgLoc, '/') === 0) {
                $imgLoc = $siteUrl . $imgLoc;
            }
        }

        echo "  <url>\n";
        echo "    <loc>" . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . "</loc>\n";
        echo "    <lastmod>{$updatedAt}</lastmod>\n";
        echo "    <changefreq>weekly</changefreq>\n";
        echo "    <priority>0.8</priority>\n";
        if (!empty($imgLoc)) {
            echo "    <image:image>\n";
            echo "      <image:loc>" . htmlspecialchars($imgLoc, ENT_XML1, 'UTF-8') . "</image:loc>\n";
            echo "      <image:title>" . htmlspecialchars($name, ENT_XML1, 'UTF-8') . "</image:title>\n";
            echo "    </image:image>\n";
        }
        echo "  </url>\n";
    }
} catch (Throwable $e) {
    error_log('Error generando sitemap-products.php: ' . $e->getMessage());
}

echo '</urlset>' . "\n";
$xmlOutput = ob_get_clean();

@file_put_contents($cacheFile, $xmlOutput);
echo $xmlOutput;
