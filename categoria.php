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
    :root { --navy:#102a43; --blue:#1177c9; --ink:#17324d; --muted:#60798f; --line:#d7e7f1; --pale:#f5fbff; --white:#fff; }
    * { box-sizing:border-box; }
    body { margin:0; background:var(--pale); color:var(--ink); font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; line-height:1.55; }
    a { color:inherit; }
    header, main, footer { width:min(1120px, calc(100% - 32px)); margin-inline:auto; }
    header { padding:22px 0 12px; display:flex; justify-content:space-between; gap:16px; align-items:center; }
    .logo { font-weight:800; color:var(--navy); text-decoration:none; font-size:22px; letter-spacing:-.04em; }
    .logo b { color:var(--blue); }
    .navlink { color:var(--blue); font-weight:700; text-decoration:none; }
    .hero { background:var(--white); border:1px solid var(--line); border-radius:14px; padding:28px; margin:16px auto 22px; }
    .eyebrow { color:var(--blue); text-transform:uppercase; letter-spacing:.12em; font-size:12px; font-weight:800; }
    h1 { color:var(--navy); font-size:clamp(30px,5vw,48px); line-height:1.05; margin:8px 0 10px; letter-spacing:-.045em; }
    .hero p { max-width:720px; color:var(--muted); margin:0; }
    .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:16px; margin:22px 0; }
    .product { background:var(--white); border:1px solid var(--line); border-radius:12px; padding:14px; text-decoration:none; display:flex; flex-direction:column; min-height:100%; }
    .product img { width:100%; aspect-ratio:1.1; object-fit:contain; background:#fff; border-radius:8px; border:1px solid #edf4f8; margin-bottom:10px; }
    .product strong { color:var(--navy); font-size:14px; line-height:1.35; }
    .product span { color:var(--blue); font-weight:800; margin-top:auto; padding-top:10px; }
    .empty { background:var(--white); border:1px solid var(--line); border-radius:12px; padding:24px; }
    .chips { display:flex; flex-wrap:wrap; gap:8px; margin:18px 0 34px; }
    .chips a { border:1px solid var(--line); background:var(--white); border-radius:999px; padding:7px 11px; color:var(--navy); text-decoration:none; font-size:13px; font-weight:700; }
    footer { padding:24px 0 34px; color:var(--muted); font-size:13px; }
  </style>
</head>
<body>
  <header>
    <a class="logo" href="/">smart<b>isp</b><span style="color:#f5a524">.</span></a>
    <a class="navlink" href="/tienda.html">Ver catalogo completo</a>
  </header>
  <main>
    <section class="hero">
      <div class="eyebrow">Categoria SmartISP Ecuador</div>
      <h1><?= escapeCategoryPage($categoryName !== '' ? $categoryName : 'Categoria no encontrada') ?></h1>
      <p><?= escapeCategoryPage($categoryName !== '' ? $metaDesc : 'Vuelve al catalogo para explorar los productos disponibles.') ?></p>
    </section>

    <?php if ($categoryName !== '' && !empty($products)): ?>
      <section class="grid" aria-label="Productos de <?= escapeCategoryPage($categoryName) ?>">
        <?php foreach ($products as $product): ?>
          <?php
            $productUrl = '/producto/' . productSlugForCategoryPage($product);
            $imageUrl = (string)($product['imageUrl'] ?: '/assets/favicons/favicon-512x512.png');
            $price = (float)($product['price'] ?? 0);
          ?>
          <a class="product" href="<?= escapeCategoryPage($productUrl) ?>">
            <img src="<?= escapeCategoryPage($imageUrl) ?>" alt="<?= escapeCategoryPage($product['name']) ?>" loading="lazy" onerror="this.src='/assets/favicons/favicon-512x512.png'">
            <strong><?= escapeCategoryPage($product['name']) ?></strong>
            <span><?= $price > 0 ? '$' . number_format($price, 2, '.', ',') : 'Cotizar' ?></span>
          </a>
        <?php endforeach; ?>
      </section>
    <?php else: ?>
      <section class="empty">
        <strong>No encontramos productos visibles para esta categoria.</strong>
        <p><a href="/tienda.html">Ir al catalogo completo</a></p>
      </section>
    <?php endif; ?>

    <?php if (!empty($relatedNames)): ?>
      <nav class="chips" aria-label="Otras categorias">
        <?php foreach ($relatedNames as $name): ?>
          <a href="/categoria/<?= rawurlencode(slugifyCategoryPage($name)) ?>/"><?= escapeCategoryPage($name) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
  </main>
  <footer>SmartISP Ecuador · Equipamiento tecnologico, redes e infraestructura TI.</footer>
</body>
</html>
