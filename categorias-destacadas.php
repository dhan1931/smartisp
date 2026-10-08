<?php
require_once __DIR__ . '/api/db.php';

function seoSlugCategoryHub(string $text): string {
    $clean = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    if (!$clean) $clean = $text;
    $clean = preg_replace('~[^\\pL\\d]+~u', '-', $clean);
    $clean = trim($clean, '-');
    $clean = preg_replace('~-+~', '-', $clean);
    $clean = strtolower($clean);
    return !empty($clean) ? substr($clean, 0, 90) : 'categoria';
}

function escCategoryHub(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$siteUrl = 'https://smart-isp.com.ec';
$categories = [];
$pdo = getDbConnection();
if ($pdo) {
    try {
        $pTable = getProductsTableName($pdo);
        ensureProductTableColumns($pdo, $pTable);
        $stmt = $pdo->query("
            SELECT category AS name, COUNT(*) AS total, MAX(updated_at) AS updated_at
            FROM `$pTable`
            WHERE visible = 1 AND TRIM(category) <> ''
            GROUP BY category
            ORDER BY total DESC, name ASC
            LIMIT 36
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $categories[] = $row;
        }
    } catch (Throwable $e) {
        error_log('Error cargando categorias-destacadas.php: ' . $e->getMessage());
    }
}
$categoryItemList = [];
foreach ($categories as $idx => $category) {
    $name = (string)$category['name'];
    $categoryItemList[] = [
        '@type' => 'ListItem',
        'position' => $idx + 1,
        'url' => $siteUrl . '/categoria/' . rawurlencode(seoSlugCategoryHub($name)) . '/',
        'name' => $name
    ];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Categorias Destacadas | SmartISP Ecuador</title>
  <meta name="description" content="Explora categorias destacadas de SmartISP Ecuador: redes, fibra optica, computadoras, energia, impresion, seguridad, gaming, software y perifericos.">
  <meta name="robots" content="index, follow, max-image-preview:large">
  <link rel="canonical" href="<?= $siteUrl ?>/categorias-destacadas">
  <link rel="icon" href="/assets/favicons/favicon.ico" sizes="any">
  <script src="/assets/js/public-brand.js" defer></script>
  <script type="application/ld+json">
  <?= json_encode([
      '@context' => 'https://schema.org',
      '@type' => 'CollectionPage',
      'name' => 'Categorías Destacadas SmartISP Ecuador',
      'description' => 'Hub comercial de categorías de tecnología, redes, cómputo, energía, seguridad y accesorios.',
      'url' => $siteUrl . '/categorias-destacadas',
      'mainEntity' => [
          '@type' => 'ItemList',
          'name' => 'Categorías destacadas',
          'itemListElement' => $categoryItemList
      ]
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
  </script>
  <style>
    :root{--navy:#102a43;--blue:#1177c9;--pale:#f5fbff;--line:#d7e7f1;--muted:#60798f;--white:#fff}
    *{box-sizing:border-box}body{margin:0;background:var(--pale);color:#17324d;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}a{text-decoration:none;color:inherit}
    header,main,footer{width:min(1120px,calc(100% - 32px));margin:auto}.top{padding:22px 0;display:flex;justify-content:space-between;align-items:center}.logo{font-size:22px;font-weight:900;color:var(--navy);letter-spacing:-.04em}html:not(.public-brand-ready) .logo{opacity:0}.public-brand-ready .logo{opacity:1;transition:opacity .12s ease}.logo b{color:var(--blue)}.nav a{font-weight:800;color:var(--blue);margin-left:18px}
    .hero{background:#fff;border:1px solid var(--line);border-radius:14px;padding:30px;margin-bottom:22px}.eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.14em;color:var(--blue);font-weight:900}h1{font-size:clamp(32px,5vw,52px);line-height:1.05;margin:8px 0;color:var(--navy)}p{color:var(--muted);line-height:1.6}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin:24px 0 36px}.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:18px;min-height:130px}.card strong{display:block;color:var(--navy);font-size:17px}.card span{display:block;color:var(--muted);margin-top:8px}.card b{display:inline-block;color:#fff;background:var(--blue);border-radius:999px;padding:4px 9px;margin-top:16px;font-size:12px}.empty{background:#fff;border:1px solid var(--line);border-radius:12px;padding:24px}footer{padding:26px 0;color:var(--muted);font-size:13px}
  </style>
</head>
<body>
  <header class="top">
    <a class="logo" href="/"><img src="/assets/favicons/favicon-192x192.png" alt="SmartISP" style="height:38px;width:auto;object-fit:contain;"></a>
    <nav class="nav"><a href="/productos-destacados">Destacados</a><a href="/servicios">Servicios</a><a href="/nosotros">Nosotros</a><a href="/tienda.html">Catalogo</a></nav>
  </header>
  <main>
    <section class="hero">
      <div class="eyebrow">Compra por necesidad</div>
      <h1>Categorias destacadas para armar, operar y proteger tu tecnologia</h1>
      <p>Accede rapido a familias del catalogo: redes, computo, energia, impresion, seguridad, accesorios, gaming, software y mas soluciones TI.</p>
    </section>
    <?php if (!empty($categories)): ?>
      <section class="grid" aria-label="Categorias destacadas">
        <?php foreach ($categories as $category): ?>
          <?php $name = (string)$category['name']; ?>
          <a class="card" href="/categoria/<?= rawurlencode(seoSlugCategoryHub($name)) ?>/">
            <strong><?= escCategoryHub($name) ?></strong>
            <span>Productos disponibles en esta familia del catalogo.</span>
            <b><?= (int)$category['total'] ?> productos</b>
          </a>
        <?php endforeach; ?>
      </section>
    <?php else: ?>
      <section class="empty">No hay categorias visibles todavia. <a href="/tienda.html">Ir al catalogo completo</a>.</section>
    <?php endif; ?>
  </main>
  <footer>SmartISP Ecuador · Categorias comerciales para descubrir productos y soluciones.</footer>
</body>
</html>
