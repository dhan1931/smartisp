<?php
/**
 * SmartISP - Pagina publica indexable de categoria.
 *
 * Genera contenido HTML sin depender de JavaScript para que buscadores puedan
 * descubrir familias de productos y seguir enlaces canonicos a fichas.
 */

require_once __DIR__ . '/api/db.php';

function slugifyCategoryPage(string $text): string {
    $clean = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    if (!$clean) $clean = $text;
    $clean = preg_replace('~[^\\pL\\d]+~u', '-', $clean);
    $clean = trim($clean, '-');
    $clean = preg_replace('~-+~', '-', $clean);
    $clean = strtolower($clean);
    return !empty($clean) ? substr($clean, 0, 90) : 'categoria';
}

function productSlugForCategoryPage(array $product): string {
    $id = (string)($product['id'] ?? ($product['sku'] ?? ''));
    $name = (string)($product['name'] ?? 'articulo');
    return rawurlencode($id) . '-' . slugifyCategoryPage($name);
}

function escapeCategoryPage(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

$siteUrl = 'https://smart-isp.com.ec';
$slug = trim((string)($_GET['slug'] ?? ''));
$pdo = getDbConnection();
$categoryName = '';
$products = [];
$allCategoryNames = [];

if ($pdo && $slug !== '') {
    try {
        $pTable = getProductsTableName($pdo);
        ensureProductTableColumns($pdo, $pTable);
        $stmt = $pdo->query("
            SELECT DISTINCT category, subcategory
            FROM `$pTable`
            WHERE visible = 1 AND TRIM(category) <> ''
            ORDER BY category ASC, subcategory ASC
        ");

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            foreach (['category', 'subcategory'] as $field) {
                $name = trim((string)($row[$field] ?? ''));
                if ($name === '') continue;
                $allCategoryNames[$name] = true;
                if ($categoryName === '' && slugifyCategoryPage($name) === $slug) {
                    $categoryName = $name;
                }
            }
        }

        if ($categoryName !== '') {
            $stmtProducts = $pdo->prepare("
                SELECT *
                FROM `$pTable`
                WHERE visible = 1 AND (TRIM(category) = :category_name OR TRIM(subcategory) = :subcategory_name)
                ORDER BY updated_at DESC, name ASC
                LIMIT 48
            ");
            $stmtProducts->execute([
                ':category_name' => $categoryName,
                ':subcategory_name' => $categoryName
            ]);
            while ($row = $stmtProducts->fetch(PDO::FETCH_ASSOC)) {
                $products[] = normalizeProductRow($row);
            }
        }
    } catch (Throwable $e) {
        error_log('Error cargando categoria.php: ' . $e->getMessage());
    }
}

if ($categoryName === '') {
    http_response_code(404);
}

$canonicalUrl = $categoryName !== ''
    ? $siteUrl . '/categoria/' . slugifyCategoryPage($categoryName) . '/'
    : $siteUrl . '/tienda.html';
$pageTitle = $categoryName !== ''
    ? $categoryName . ' | Productos SmartISP Ecuador'
    : 'Categoria no encontrada | SmartISP';
$metaDesc = $categoryName !== ''
    ? 'Compra ' . $categoryName . ' en SmartISP Ecuador. Catalogo de equipamiento tecnologico, redes, servidores y soluciones TI para empresas e ISP.'
    : 'Categoria no encontrada en el catalogo de SmartISP Ecuador.';
$robots = $categoryName !== '' ? 'index, follow, max-image-preview:large' : 'noindex, follow';
$relatedNames = array_slice(array_keys($allCategoryNames), 0, 12);
$productItemList = [];
foreach ($products as $idx => $product) {
    $productItemList[] = [
        '@type' => 'ListItem',
        'position' => $idx + 1,
        'url' => $siteUrl . '/producto/' . productSlugForCategoryPage($product),
        'name' => (string)$product['name']
    ];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= escapeCategoryPage($pageTitle) ?></title>
  <meta name="description" content="<?= escapeCategoryPage($metaDesc) ?>">
  <meta name="robots" content="<?= $robots ?>">
  <link rel="canonical" href="<?= escapeCategoryPage($canonicalUrl) ?>">
  <link rel="icon" href="/assets/favicons/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="/assets/favicons/favicon.svg">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/favicons/apple-touch-icon.png">
  <script src="/assets/js/public-brand.js?v=landing-logo" defer></script>
  <link rel="stylesheet" href="/assets/css/storefront-discovery.css?v=20261009">
  <script src="https://unpkg.com/lucide@latest"></script>
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="SmartISP Ecuador">
  <meta property="og:title" content="<?= escapeCategoryPage($pageTitle) ?>">
  <meta property="og:description" content="<?= escapeCategoryPage($metaDesc) ?>">
  <meta property="og:url" content="<?= escapeCategoryPage($canonicalUrl) ?>">
  <meta property="og:image" content="<?= $siteUrl ?>/assets/favicons/favicon-512x512.png">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= escapeCategoryPage($pageTitle) ?>">
  <meta name="twitter:description" content="<?= escapeCategoryPage($metaDesc) ?>">
  <meta name="twitter:image" content="<?= $siteUrl ?>/assets/favicons/favicon-512x512.png">
  <?php if ($categoryName !== ''): ?>
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "CollectionPage",
    "name": <?= json_encode($categoryName . ' SmartISP Ecuador', JSON_UNESCAPED_UNICODE) ?>,
    "description": <?= json_encode($metaDesc, JSON_UNESCAPED_UNICODE) ?>,
    "url": <?= json_encode($canonicalUrl, JSON_UNESCAPED_SLASHES) ?>,
    "mainEntity": {
      "@type": "ItemList",
      "name": <?= json_encode('Productos de ' . $categoryName, JSON_UNESCAPED_UNICODE) ?>,
      "itemListElement": <?= json_encode($productItemList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    },
    "isPartOf": {
      "@type": "WebSite",
      "name": "SmartISP Ecuador",
      "url": "https://smart-isp.com.ec/"
    }
  }
  </script>
  <?php endif; ?>
  <style>
    .category-hero{margin:24px 0 18px;padding:28px;border:1px solid #d8e5e7;border-left:4px solid #087ea4;border-radius:8px;background:#fff}
    .category-eyebrow{color:#087ea4;font-size:11px;font-weight:850;text-transform:uppercase}
    .category-hero h1{margin:7px 0;color:#102c3d;font-size:clamp(28px,4vw,42px);line-height:1.1;letter-spacing:0}
    .category-hero p{max-width:760px;margin:0;color:#60798f;font-size:14px}
    .category-list-heading{display:flex;align-items:end;justify-content:space-between;gap:14px;margin-top:24px}
    .category-list-heading h2{margin:0;color:#102c3d;font-size:22px}
    .category-list-heading p{margin:3px 0 0;color:#60798f;font-size:13px}
    .category-empty{padding:22px;border:1px solid #d8e5e7;border-radius:8px;background:#fff}
    .category-empty a{color:#087ea4;font-weight:800}
    .category-chips{display:flex;flex-wrap:wrap;gap:8px;margin:18px 0 34px}
    .category-chips a{padding:7px 11px;border:1px solid #d8e5e7;border-radius:6px;background:#fff;color:#102c3d;font-size:12px;font-weight:750}
    .category-chips a:hover{border-color:#087ea4;color:#087ea4}
    @media(max-width:760px){.category-hero{margin-top:16px;padding:20px}.category-list-heading{align-items:start;flex-direction:column}}
  </style>
</head>
<body>
  <?php $storefrontActivePage = 'categories'; require __DIR__ . '/includes/storefront-discovery-nav.php'; ?>
  <main class="storefront-page">
    <section class="hero">
      <div class="category-eyebrow">Categoria SmartISP Ecuador</div>
      <h1><?= escapeCategoryPage($categoryName !== '' ? $categoryName : 'Categoria no encontrada') ?></h1>
      <p><?= escapeCategoryPage($categoryName !== '' ? $metaDesc : 'Vuelve al catalogo para explorar los productos disponibles.') ?></p>
    </section>

    <?php if ($categoryName !== '' && !empty($products)): ?>
      <div class="category-list-heading"><div><h2>Productos en <?= escapeCategoryPage($categoryName) ?></h2><p><?= count($products) ?> productos disponibles</p></div></div>
      <section class="store-product-grid" aria-label="Productos de <?= escapeCategoryPage($categoryName) ?>">
        <?php foreach ($products as $index => $product): ?>
          <?php $storeProduct = $product; $storeProductUrl = '/producto/' . productSlugForCategoryPage($product); $storeProductIndex = $index; require __DIR__ . '/includes/storefront-product-card.php'; ?>
        <?php endforeach; ?>
      </section>
    <?php else: ?>
      <section class="category-empty">
        <strong>No encontramos productos visibles para esta categoria.</strong>
        <p><a href="/tienda.html">Ir al catalogo completo</a></p>
      </section>
    <?php endif; ?>

    <?php if (!empty($relatedNames)): ?>
      <nav class="category-chips" aria-label="Otras categorias">
        <?php foreach ($relatedNames as $name): ?>
          <a href="/categoria/<?= rawurlencode(slugifyCategoryPage($name)) ?>/"><?= escapeCategoryPage($name) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
  </main>
  <footer class="storefront-footer">SmartISP Ecuador · Equipamiento tecnologico, redes e infraestructura TI.</footer>
  <script src="/assets/js/storefront-discovery.js?v=20261009" defer></script>
</body>
</html>
