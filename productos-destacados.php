<?php
require_once __DIR__ . '/api/db.php';

function seoSlugFeatured(string $text): string {
    $clean = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    if (!$clean) $clean = $text;
    $clean = preg_replace('~[^\\pL\\d]+~u', '-', $clean);
    $clean = trim($clean, '-');
    $clean = preg_replace('~-+~', '-', $clean);
    $clean = strtolower($clean);
    return !empty($clean) ? substr($clean, 0, 90) : 'articulo';
}

function escFeatured(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$siteUrl = 'https://smart-isp.com.ec';
$products = [];
$pdo = getDbConnection();
if ($pdo) {
    try {
        $pTable = getProductsTableName($pdo);
        ensureProductTableColumns($pdo, $pTable);
        $stmt = $pdo->query("SELECT * FROM `$pTable` WHERE visible = 1 ORDER BY updated_at DESC, created_at DESC, name ASC LIMIT 24");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $products[] = normalizeProductRow($row);
        }
    } catch (Throwable $e) {
        error_log('Error cargando productos-destacados.php: ' . $e->getMessage());
    }
}
$productItemList = [];
foreach ($products as $idx => $product) {
    $url = $siteUrl . '/producto/' . rawurlencode($product['id']) . '-' . seoSlugFeatured($product['name']);
    $productItemList[] = [
        '@type' => 'ListItem',
        'position' => $idx + 1,
        'url' => $url,
        'name' => (string)$product['name']
    ];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Productos Destacados | SmartISP Ecuador</title>
  <meta name="description" content="Productos destacados de SmartISP Ecuador: networking, fibra optica, computo, energia, seguridad, perifericos y soluciones TI para empresas e ISP.">
  <meta name="robots" content="index, follow, max-image-preview:large">
  <link rel="canonical" href="<?= $siteUrl ?>/productos-destacados">
  <link rel="icon" href="/assets/favicons/favicon.ico" sizes="any">
  <script src="/assets/js/public-brand.js" defer></script>
  <script type="application/ld+json">
  <?= json_encode([
      '@context' => 'https://schema.org',
      '@type' => 'CollectionPage',
      'name' => 'Productos Destacados SmartISP Ecuador',
      'description' => 'Selección comercial de productos recientes y destacados del catálogo SmartISP Ecuador.',
      'url' => $siteUrl . '/productos-destacados',
      'mainEntity' => [
          '@type' => 'ItemList',
          'name' => 'Productos destacados',
          'itemListElement' => $productItemList
      ]
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
  </script>
  <style>
    :root{--navy:#102a43;--blue:#1177c9;--pale:#f5fbff;--line:#d7e7f1;--muted:#60798f;--white:#fff}
    *{box-sizing:border-box}body{margin:0;background:var(--pale);color:#17324d;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}a{text-decoration:none;color:inherit}
    header,main,footer{width:min(1160px,calc(100% - 32px));margin:auto}.top{padding:22px 0;display:flex;justify-content:space-between;align-items:center}.logo{font-size:22px;font-weight:900;color:var(--navy);letter-spacing:-.04em}html:not(.public-brand-ready) .logo{opacity:0}.public-brand-ready .logo{opacity:1;transition:opacity .12s ease}.logo b{color:var(--blue)}.nav a{font-weight:800;color:var(--blue);margin-left:18px}
    .hero{background:#fff;border:1px solid var(--line);border-radius:14px;padding:30px;margin-bottom:22px}.eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.14em;color:var(--blue);font-weight:900}h1{font-size:clamp(32px,5vw,52px);line-height:1.05;margin:8px 0;color:var(--navy)}p{color:var(--muted);line-height:1.6}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin:24px 0 36px}.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px;display:flex;flex-direction:column;min-height:100%}.card img{width:100%;aspect-ratio:1.1;object-fit:contain;border:1px solid #edf4f8;border-radius:8px;background:#fff}.card strong{color:var(--navy);font-size:14px;line-height:1.35;margin-top:10px}.card small{color:var(--muted);margin-top:4px}.price{color:var(--blue);font-weight:900;margin-top:auto;padding-top:10px}.empty{background:#fff;border:1px solid var(--line);border-radius:12px;padding:24px}footer{padding:26px 0;color:var(--muted);font-size:13px}
  </style>
</head>
<body>
  <header class="top">
    <a class="logo" href="/"><img src="/assets/favicons/favicon-192x192.png" alt="SmartISP" style="height:38px;width:auto;object-fit:contain;"></a>
    <nav class="nav"><a href="/categorias-destacadas">Categorias</a><a href="/servicios">Servicios</a><a href="/nosotros">Nosotros</a><a href="/tienda.html">Catalogo</a></nav>
  </header>
  <main>
    <section class="hero">
      <div class="eyebrow">Seleccion comercial</div>
      <h1>Productos destacados para redes, empresas y tecnologia</h1>
      <p>Una vitrina rapida de articulos recientes del catalogo SmartISP: conectividad, computo, energia, seguridad, perifericos y equipamiento TI.</p>
    </section>
    <?php if (!empty($products)): ?>
      <section class="grid" aria-label="Productos destacados">
        <?php foreach ($products as $product): ?>
          <?php
            $url = '/producto/' . rawurlencode($product['id']) . '-' . seoSlugFeatured($product['name']);
            $image = (string)($product['imageUrl'] ?: '/assets/favicons/favicon-512x512.png');
            $price = (float)($product['price'] ?? 0);
          ?>
          <a class="card" href="<?= escFeatured($url) ?>">
            <img src="<?= escFeatured($image) ?>" alt="<?= escFeatured($product['name']) ?>" loading="lazy" onerror="this.src='/assets/favicons/favicon-512x512.png'">
            <strong><?= escFeatured($product['name']) ?></strong>
            <small><?= escFeatured((string)($product['category'] ?: 'SmartISP')) ?></small>
            <span class="price"><?= $price > 0 ? '$' . number_format($price, 2, '.', ',') : 'Cotizar' ?></span>
          </a>
        <?php endforeach; ?>
      </section>
    <?php else: ?>
      <section class="empty">No hay productos destacados disponibles. <a href="/tienda.html">Ir al catalogo completo</a>.</section>
    <?php endif; ?>
  </main>
  <footer>SmartISP Ecuador · Catalogo tecnologico amplio para empresas, hogares e ISP.</footer>
</body>
</html>
