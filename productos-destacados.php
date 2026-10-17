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
$featuredProducts = [];
$hasInventory = false;
$filterQuery = trim((string)($_GET['q'] ?? ''));
$filterCategory = trim((string)($_GET['categoria'] ?? ''));
$filterSubcategory = trim((string)($_GET['subcategoria'] ?? ''));
$filterPriceMin = trim((string)($_GET['precio_min'] ?? ''));
$filterPriceMax = trim((string)($_GET['precio_max'] ?? ''));
$filterInStock = ($_GET['stock'] ?? '') === '1';
$allowedSorts = ['relevancia', 'precio_asc', 'precio_desc', 'nombre'];
$sortOrder = in_array((string)($_GET['orden'] ?? ''), $allowedSorts, true) ? (string)$_GET['orden'] : 'relevancia';
$categoryOptions = [];
$subcategoryOptions = [];
$featuredPage = [
    'eyebrow' => 'Selección comercial',
    'title' => 'Productos destacados para redes, empresas y tecnología',
    'description' => 'Una vitrina rápida de artículos del catálogo SmartISP: conectividad, cómputo, energía, seguridad, periféricos y equipamiento TI.',
    'note' => 'Selección pensada para partir rápido: revisa precio, categoría y ficha antes de cotizar o comprar.',
    'products_title' => 'Selección destacada',
    'products_description' => 'Productos elegidos para mostrar novedades y alta rotación.',
];
$pdo = getDbConnection();
if ($pdo) {
    try {
        $pageSettings = $pdo->query("SELECT setting_key, setting_value FROM settings_rows WHERE setting_key LIKE 'store_featured_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (array_keys($featuredPage) as $field) {
            if (isset($pageSettings['store_featured_' . $field])) $featuredPage[$field] = (string)$pageSettings['store_featured_' . $field];
        }
        $pTable = getProductsTableName($pdo);
        ensureProductTableColumns($pdo, $pTable);
        $hasInventory = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'product_inventory'")->fetchColumn() > 0;
        $stockSelect = $hasInventory ? ', (SELECT GREATEST(quantity_available - quantity_reserved, 0) FROM product_inventory WHERE product_id = p.id LIMIT 1) AS available_qty' : ', NULL AS available_qty';
        $selectedStmt = $pdo->query("SELECT p.*$stockSelect FROM storefront_campaign_products cp JOIN storefront_campaigns c ON c.id = cp.campaign_id JOIN `$pTable` p ON p.id = cp.product_id WHERE c.code = 'store-home' AND p.visible = 1 ORDER BY cp.sort_order, p.name LIMIT 12");
        $selectedRows = $selectedStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$selectedRows) $selectedRows = $pdo->query("SELECT p.*$stockSelect FROM `$pTable` p WHERE visible = 1 ORDER BY updated_at DESC, created_at DESC, name ASC LIMIT 24")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($selectedRows as $row) {
            $product = normalizeProductRow($row);
            $product['available_qty'] = $row['available_qty'] === null ? null : (int)$row['available_qty'];
            $product['_featured_order'] = count($featuredProducts);
            $featuredProducts[] = $product;
        }
    } catch (Throwable $e) {
        error_log('Error cargando productos-destacados.php: ' . $e->getMessage());
    }
}

if (!is_numeric($filterPriceMin) || (float)$filterPriceMin < 0) $filterPriceMin = '';
if (!is_numeric($filterPriceMax) || (float)$filterPriceMax < 0) $filterPriceMax = '';
if (!$hasInventory) $filterInStock = false;

foreach ($featuredProducts as $product) {
    $category = trim((string)($product['category'] ?? ''));
    $subcategory = trim((string)($product['subcategory'] ?? ''));
    if ($category !== '') $categoryOptions[$category] = ($categoryOptions[$category] ?? 0) + 1;
    if ($subcategory !== '') $subcategoryOptions[$subcategory] = ($subcategoryOptions[$subcategory] ?? 0) + 1;
}
uksort($categoryOptions, 'strcasecmp');
uksort($subcategoryOptions, 'strcasecmp');

$products = array_values(array_filter($featuredProducts, static function (array $product) use ($filterQuery, $filterCategory, $filterSubcategory, $filterPriceMin, $filterPriceMax, $filterInStock): bool {
    if ($filterCategory !== '' && (string)($product['category'] ?? '') !== $filterCategory) return false;
    if ($filterSubcategory !== '' && (string)($product['subcategory'] ?? '') !== $filterSubcategory) return false;
    $price = (float)($product['price'] ?? 0);
    if ($filterPriceMin !== '' && $price < (float)$filterPriceMin) return false;
    if ($filterPriceMax !== '' && $price > (float)$filterPriceMax) return false;
    if ($filterInStock && (!array_key_exists('available_qty', $product) || $product['available_qty'] === null || (int)$product['available_qty'] <= 0)) return false;
    if ($filterQuery !== '') {
        $haystack = implode(' ', [(string)($product['name'] ?? ''), (string)($product['sku'] ?? ''), (string)($product['category'] ?? ''), (string)($product['subcategory'] ?? '')]);
        $needle = function_exists('mb_strtolower') ? mb_strtolower($filterQuery, 'UTF-8') : strtolower($filterQuery);
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
        if (!str_contains($haystack, $needle)) return false;
    }
    return true;
}));

if ($sortOrder !== 'relevancia') {
    usort($products, static function (array $a, array $b) use ($sortOrder): int {
        if ($sortOrder === 'precio_asc') $cmp = (float)$a['price'] <=> (float)$b['price'];
        elseif ($sortOrder === 'precio_desc') $cmp = (float)$b['price'] <=> (float)$a['price'];
        else $cmp = strcasecmp((string)$a['name'], (string)$b['name']);
        return $cmp !== 0 ? $cmp : ((int)$a['_featured_order'] <=> (int)$b['_featured_order']);
    });
}

$featuredTotal = count($featuredProducts);
$filteredTotal = count($products);
$activeFilterCount = (int)($filterQuery !== '') + (int)($filterCategory !== '') + (int)($filterSubcategory !== '') + (int)($filterPriceMin !== '') + (int)($filterPriceMax !== '') + (int)$filterInStock;
$clearFiltersUrl = '/productos-destacados';
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
  <title><?= escFeatured($featuredPage['title']) ?> | SmartISP Ecuador</title>
  <meta name="description" content="<?= escFeatured($featuredPage['description']) ?>">
  <meta name="robots" content="index, follow, max-image-preview:large">
  <link rel="canonical" href="<?= $siteUrl ?>/productos-destacados">
  <link rel="icon" href="/assets/favicons/favicon.ico" sizes="any">
  <link rel="stylesheet" href="/assets/css/storefront-discovery.css?v=header-unify-20261013">
  <script src="/assets/js/public-brand.js?v=admin-brand-20261011" defer></script>
  <script src="/assets/vendor/lucide.min.js?v=1.55.0"></script>
  <script type="application/ld+json">
  <?= json_encode([
      '@context' => 'https://schema.org',
      '@type' => 'CollectionPage',
      'name' => $featuredPage['title'] . ' | SmartISP Ecuador',
      'description' => $featuredPage['description'],
      'url' => $siteUrl . '/productos-destacados',
      'mainEntity' => [
          '@type' => 'ItemList',
          'name' => 'Productos destacados',
          'itemListElement' => $productItemList
      ]
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
  </script>
  <style>
    :root{--navy:#102c3d;--blue:#087ea4;--pale:#f3f7f8;--line:#d8e5e7;--muted:#60798f;--white:#fff;--gold:#f5a524}
    *{box-sizing:border-box}body{margin:0;background:var(--pale);color:#17324d;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}a{text-decoration:none;color:inherit}button,input{font:inherit}
    .topbar{background:var(--navy);color:#d9efff;font-size:12px;padding:8px 5vw;display:flex;justify-content:space-between;gap:18px}
    svg{width:1em;height:1em;vertical-align:-.14em}.nav{background:#fff;border-bottom:1px solid var(--line);padding:15px 5vw;display:grid;grid-template-columns:minmax(120px,220px) minmax(0,680px) minmax(180px,1fr);align-items:center;gap:20px;position:sticky;top:0;z-index:1000;box-shadow:0 5px 18px rgba(16,44,61,.06)}.logo{display:inline-flex;align-items:center;color:var(--navy);font-size:22px;font-weight:900;letter-spacing:-.04em}.logo b{color:var(--blue)}html:not(.public-brand-ready) .logo{opacity:0}.public-brand-ready .logo{opacity:1;transition:opacity .12s ease}.logo img{display:block;object-fit:contain;width:auto;max-width:100%}
    .search{display:flex;width:100%;min-width:0;justify-self:center;background:#f3f7f8;border:2px solid #d8e8f1;border-radius:8px;overflow:hidden;box-shadow:0 8px 20px rgba(16,44,61,.06)}.search:focus-within{border-color:var(--blue);box-shadow:0 0 0 3px rgba(8,126,164,.11)}.search input{width:100%;border:0;outline:0;padding:13px 16px;background:transparent;color:#17324d;text-align:center}.search button{background:var(--blue);border:0;color:#fff;width:48px;display:grid;place-items:center}
    .nav-actions{margin-left:auto;display:flex;gap:12px;align-items:center;justify-self:end}.nav-action{border:0;background:transparent;color:var(--navy);display:flex;align-items:center;gap:7px;font-weight:700;padding:7px 8px;border-radius:8px}.nav-action:hover{color:var(--blue);background:#edf7f8}.cart-button{position:relative}.cart-count{background:var(--gold);border-radius:50%;color:#172b3a;font-size:10px;min-width:17px;height:17px;display:grid;place-items:center;position:absolute;top:0;left:22px}
    .public-menu-btn{display:none;align-items:center;justify-content:center;width:38px;height:38px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--navy)}.public-nav{position:sticky;top:var(--sticky-nav-height,70px);z-index:999;background:var(--navy);color:#fff;display:flex;justify-content:center;gap:clamp(22px,5vw,80px);padding:11px 5vw;font-weight:800;font-size:15px;box-shadow:inset 0 1px rgba(255,255,255,.08)}.public-nav a{color:#fff;white-space:nowrap;opacity:.96}.public-nav a:hover,.public-nav a.active{color:#ffd166}
    main,footer{width:min(1160px,calc(100% - 32px));margin:auto}.hero{margin-top:28px;background:#fff;border:1px solid var(--line);border-radius:12px;padding:34px;margin-bottom:18px;display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,330px);gap:28px;align-items:end;box-shadow:0 14px 35px rgba(16,44,61,.06)}.eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.14em;color:var(--blue);font-weight:900}h1{font-size:clamp(32px,5vw,54px);line-height:1.02;margin:8px 0;color:var(--navy);letter-spacing:-.045em}p{color:var(--muted);line-height:1.6}.hero p{max-width:680px}.hero-panel{border-left:4px solid var(--gold);padding-left:18px;color:var(--muted);font-size:14px}.hero-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.btn{display:inline-flex;align-items:center;justify-content:center;border-radius:8px;padding:11px 14px;font-weight:900;font-size:13px}.btn.primary{background:var(--blue);color:#fff}.btn.secondary{background:#edf7f8;color:var(--navy);border:1px solid var(--line)}
    .section-head{display:flex;justify-content:space-between;align-items:end;gap:18px;margin:28px 0 14px}.section-head h2{margin:0;color:var(--navy);font-size:25px;letter-spacing:-.03em}.section-head p{margin:4px 0 0;font-size:13px}.count-pill{border:1px solid var(--line);background:#fff;border-radius:999px;padding:8px 12px;color:var(--muted);font-size:12px;font-weight:800;white-space:nowrap}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:16px;margin:0 0 36px}.card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:12px;display:flex;flex-direction:column;min-height:100%;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease}.card:hover{transform:translateY(-3px);border-color:#9acfe9;box-shadow:0 14px 28px rgba(16,44,61,.09)}.card img{width:100%;aspect-ratio:1.12;object-fit:contain;border:1px solid #edf4f8;border-radius:8px;background:#fff;padding:10px}.card strong{color:var(--navy);font-size:14px;line-height:1.35;margin-top:12px}.card small{color:var(--muted);margin-top:5px;line-height:1.35}.card-foot{margin-top:auto;padding-top:12px;display:flex;align-items:center;justify-content:space-between;gap:10px}.price{color:var(--navy);font-weight:900;font-size:17px}.see{color:var(--blue);font-size:12px;font-weight:900}.empty{background:#fff;border:1px solid var(--line);border-radius:12px;padding:24px}footer{padding:28px 0 32px;color:var(--muted);font-size:13px;border-top:1px solid var(--line);display:flex;justify-content:space-between;gap:20px}
    @media(max-width:1080px){.nav{display:flex;flex-wrap:wrap}.search{order:3;flex:1 1 100%}.search input{text-align:left}}
    .mobile-bottom-nav{display:none;position:fixed;left:0;right:0;bottom:0;height:calc(58px + env(safe-area-inset-bottom,0px));padding-bottom:env(safe-area-inset-bottom,0px);background:rgba(255,255,255,.96);border-top:1px solid var(--line);box-shadow:0 -8px 22px rgba(16,44,61,.09);z-index:1200;align-items:center;justify-content:space-around}.mobile-nav-item{display:flex;flex-direction:column;align-items:center;gap:3px;border:0;background:transparent;color:#587084;font-size:11px;font-weight:800;text-decoration:none}.mobile-nav-item.is-active{color:var(--blue)}.mobile-nav-cart{position:relative}.mobile-nav-cart b{position:absolute;top:-5px;right:12px;min-width:16px;height:16px;border-radius:99px;background:var(--gold);display:grid;place-items:center;font-size:10px;color:#172b3a}
    @media(max-width:800px){body{padding-bottom:calc(76px + env(safe-area-inset-bottom,0px))}.topbar{display:none}.mobile-bottom-nav{display:flex}.public-menu-btn{display:inline-flex}.public-nav{display:none;flex-direction:column;align-items:stretch;gap:0;padding:6px 16px;box-shadow:0 10px 20px rgba(16,44,61,.14)}.public-nav.is-open{display:flex}.public-nav a{padding:12px 4px;border-bottom:1px solid rgba(255,255,255,.12)}.public-nav a:last-child{border-bottom:0}.nav{padding:12px 4vw;gap:10px;overflow:hidden}.logo img{max-width:140px}.nav-actions{gap:8px}.nav-action span{display:none}.hero{grid-template-columns:1fr;padding:24px}.section-head{align-items:flex-start;flex-direction:column}.grid{grid-template-columns:1fr 1fr;gap:10px}.card{padding:10px}footer{flex-direction:column;padding-bottom:96px}}@media(max-width:460px){.grid{grid-template-columns:1fr}}
    @media(max-width:800px){.public-menu-btn{display:none!important}.public-nav{display:flex!important;position:sticky;top:var(--sticky-nav-height,70px);flex-direction:row;align-items:center;justify-content:flex-start;gap:18px;padding:10px 16px;overflow-x:auto;white-space:nowrap}.public-nav a{flex:0 0 auto;padding:3px 0;border:0}}
    .featured-layout{display:grid;grid-template-columns:260px minmax(0,1fr);gap:18px;align-items:start;margin:18px 0 36px}.featured-sidebar{position:sticky;top:16px;min-width:0;padding:14px;border:1px solid #bdced8;border-radius:8px;background:#fff;box-shadow:0 4px 14px rgba(16,44,61,.04)}.featured-filter-panel>summary{display:flex;align-items:center;justify-content:space-between;gap:12px;color:var(--navy);font-size:15px;font-weight:850;cursor:pointer;list-style:none}.featured-filter-panel>summary::-webkit-details-marker{display:none}.featured-filter-panel>summary:after{width:8px;height:8px;border-right:2px solid var(--muted);border-bottom:2px solid var(--muted);content:"";transform:rotate(45deg)}.featured-filter-panel[open]>summary:after{transform:rotate(225deg)}.featured-filter-count{margin-left:auto;padding:3px 8px;border-radius:99px;background:#e8f5fa;color:var(--blue);font-size:11px}.featured-filter-fields{display:grid;gap:12px;margin-top:12px}.featured-filter-fields label{display:grid;gap:5px;color:#17324d;font-size:12px;font-weight:750}.featured-filter-fields input[type=search],.featured-filter-fields input[type=number],.featured-filter-fields select{width:100%;min-width:0;min-height:38px;margin:0;padding:8px 9px;border:1px solid #bdced8;border-radius:5px;background:#fff;color:#17324d;font-size:13px}.featured-filter-price{display:grid;grid-template-columns:1fr 1fr;gap:8px}.featured-filter-stock{display:flex!important;align-items:center;gap:8px}.featured-filter-stock input{width:auto;margin:0}.featured-filter-actions{display:flex;align-items:center;gap:8px}.featured-filter-actions button,.featured-filter-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 10px;border:1px solid #bdced8;border-radius:5px;background:#fff;color:var(--navy);font-size:12px;font-weight:800}.featured-filter-actions button{border-color:var(--blue);background:var(--blue);color:#fff}.featured-results{min-width:0}.featured-results .section-head{margin-top:0}.featured-results .store-product-grid{margin-top:16px}.featured-results-empty{padding:22px;border:1px solid #bdced8;border-radius:8px;background:#fff;color:var(--muted)}.featured-results-empty a{color:var(--blue);font-weight:800}
    @media(max-width:900px){.featured-layout{grid-template-columns:220px minmax(0,1fr);gap:12px}.featured-sidebar{padding:12px}}
    @media(max-width:760px){.featured-layout{grid-template-columns:1fr;gap:12px;margin-top:14px}.featured-sidebar{position:static;padding:12px}.featured-filter-panel:not([open]) .featured-filter-fields{display:none}.featured-results .section-head{margin:8px 0 12px}.featured-results .section-head h2{font-size:21px}.featured-results .count-pill{white-space:normal}}
    @media(max-width:460px){.featured-layout{gap:10px}.featured-filter-actions button,.featured-filter-actions a{flex:1}.featured-filter-price{grid-template-columns:1fr 1fr}}
  </style>
  <style>.nav, .public-nav { position: relative !important; top: auto !important; }</style>
</head>
<body>
  <?php $storefrontActivePage = 'products'; require __DIR__ . '/includes/storefront-discovery-nav.php'; ?>
  <main class="storefront-page">
    <section class="hero">
      <div>
            <div class="eyebrow"><?= escFeatured($featuredPage['eyebrow']) ?></div>
        <h1><?= escFeatured($featuredPage['title']) ?></h1>
        <p><?= escFeatured($featuredPage['description']) ?></p>
        <div class="hero-actions">
          <a class="btn primary" href="/tienda.html#catalogo">Ver catalogo</a>
          <a class="btn secondary" href="/categorias-destacadas">Ver categorias</a>
        </div>
      </div>
      <div class="hero-panel"><?= escFeatured($featuredPage['note']) ?></div>
    </section>
    <?php if ($featuredTotal > 0): ?>
      <form class="featured-layout" method="get" action="/productos-destacados">
        <aside class="featured-sidebar" aria-label="Filtros de productos destacados">
          <details class="featured-filter-panel" id="featuredFilterPanel" open>
            <summary><span>Filtrar destacados</span><span class="featured-filter-count"><?= $activeFilterCount > 0 ? $activeFilterCount . ' activos' : $featuredTotal . ' productos' ?></span></summary>
            <div class="featured-filter-fields">
              <label>Buscar producto<input type="search" name="q" value="<?= escFeatured($filterQuery) ?>" placeholder="Nombre o SKU" aria-label="Buscar en productos destacados"></label>
              <?php if (count($categoryOptions) > 1): ?>
                <label>Categoría<select name="categoria"><option value="">Todas las categorías</option><?php foreach ($categoryOptions as $name => $count): ?><option value="<?= escFeatured((string)$name) ?>"<?= $filterCategory === (string)$name ? ' selected' : '' ?>><?= escFeatured((string)$name) ?> (<?= (int)$count ?>)</option><?php endforeach; ?></select></label>
              <?php endif; ?>
              <?php if ($subcategoryOptions): ?>
                <label>Subcategoría<select name="subcategoria"><option value="">Todas las subcategorías</option><?php foreach ($subcategoryOptions as $name => $count): ?><option value="<?= escFeatured((string)$name) ?>"<?= $filterSubcategory === (string)$name ? ' selected' : '' ?>><?= escFeatured((string)$name) ?> (<?= (int)$count ?>)</option><?php endforeach; ?></select></label>
              <?php endif; ?>
              <div class="featured-filter-price"><label>Precio desde<input type="number" name="precio_min" min="0" step="0.01" value="<?= escFeatured($filterPriceMin) ?>" placeholder="$0"></label><label>Precio hasta<input type="number" name="precio_max" min="0" step="0.01" value="<?= escFeatured($filterPriceMax) ?>" placeholder="Sin límite"></label></div>
              <?php if ($hasInventory): ?><label class="featured-filter-stock"><input type="checkbox" name="stock" value="1"<?= $filterInStock ? ' checked' : '' ?>> Solo disponibles</label><?php endif; ?>
              <label>Ordenar por<select name="orden"><option value="relevancia"<?= $sortOrder === 'relevancia' ? ' selected' : '' ?>>Más relevantes</option><option value="precio_asc"<?= $sortOrder === 'precio_asc' ? ' selected' : '' ?>>Menor precio</option><option value="precio_desc"<?= $sortOrder === 'precio_desc' ? ' selected' : '' ?>>Mayor precio</option><option value="nombre"<?= $sortOrder === 'nombre' ? ' selected' : '' ?>>Nombre</option></select></label>
              <div class="featured-filter-actions"><button type="submit"><i data-lucide="sliders-horizontal" width="14"></i> Aplicar</button><a href="/productos-destacados">Limpiar</a></div>
            </div>
          </details>
        </aside>
        <section class="featured-results" aria-label="Resultados destacados">
          <div class="section-head"><div><h2><?= escFeatured($featuredPage['products_title']) ?></h2><p><?= $filteredTotal ?> de <?= $featuredTotal ?> productos destacados</p></div><span class="count-pill"><?= $filteredTotal ?> productos</span></div>
          <?php if ($products): ?>
            <div class="store-product-grid" aria-label="Productos destacados">
              <?php foreach ($products as $index => $product): ?>
                <?php $storeProduct = $product; $storeProductUrl = '/producto/' . rawurlencode($product['id']) . '-' . seoSlugFeatured($product['name']); $storeProductIndex = $index; require __DIR__ . '/includes/storefront-product-card.php'; ?>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="featured-results-empty">No hay productos destacados que coincidan con esos filtros. <a href="/productos-destacados">Limpiar filtros</a>.</div>
          <?php endif; ?>
        </section>
      </form>
    <?php else: ?>
      <section class="empty">No hay productos destacados disponibles. <a href="/tienda.html">Ir al catalogo completo</a>.</section>
    <?php endif; ?>
  </main>
  <footer class="site-footer">
    <div class="site-footer-top">
      <div class="site-footer-brand">
        <a class="logo site-footer-logo" href="/" title="SmartISP Tienda Online">SMART-ISP</a>
        <small class="site-footer-logo-tag">Infraestructura y Soluciones TI</small>
        <h3>Tecnología que impulsa tus proyectos.</h3>
        <p>Equipamiento tecnológico, infraestructura y soluciones TI para hogares y empresas.</p>
        <div class="site-footer-social" id="footerSocial">
          <a href="#" id="footerSocialInstagram" aria-label="Instagram" hidden><svg viewBox="0 0 24 24" width="16" fill="currentColor"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41-.56-.22-.96-.48-1.38-.9-.42-.42-.68-.82-.9-1.38-.16-.42-.36-1.06-.41-2.23-.06-1.27-.07-1.65-.07-4.85s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41 1.27-.06 1.65-.07 4.85-.07M12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.31-1.46.72-2.13 1.39C1.35 2.68.94 3.35.63 4.14.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.31.79.72 1.46 1.39 2.13.67.67 1.34 1.08 2.13 1.39.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56.79-.31 1.46-.72 2.13-1.39.67-.67 1.08-1.34 1.39-2.13.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91-.31-.79-.72-1.46-1.39-2.13C20.32 1.35 19.65.94 18.86.63c-.76-.3-1.64-.5-2.91-.56C14.67.01 14.26 0 12 0zm0 5.84A6.16 6.16 0 1018.16 12 6.16 6.16 0 0012 5.84zM12 16a4 4 0 114-4 4 4 0 01-4 4zm6.41-10.84a1.44 1.44 0 11-1.44-1.44 1.44 1.44 0 011.44 1.44z"/></svg></a>
          <a href="#" id="footerSocialFacebook" aria-label="Facebook" hidden><svg viewBox="0 0 24 24" width="16" fill="currentColor"><path d="M22 12c0-5.52-4.48-10-10-10S2 6.48 2 12c0 4.84 3.44 8.87 8 9.8V15H8v-3h2V9.5C10 7.57 11.57 6 13.5 6H16v3h-2c-.55 0-1 .45-1 1v2h3v3h-3v6.95c5.05-.5 9-4.76 9-9.95z"/></svg></a>
          <a href="#" id="footerSocialYoutube" aria-label="YouTube" hidden><svg viewBox="0 0 24 24" width="16" fill="currentColor"><path d="M23.5 6.2a3.02 3.02 0 00-2.12-2.13C19.5 3.5 12 3.5 12 3.5s-7.5 0-9.38.57A3.02 3.02 0 00.5 6.2 31.6 31.6 0 000 12a31.6 31.6 0 00.5 5.8 3.02 3.02 0 002.12 2.13C4.5 20.5 12 20.5 12 20.5s7.5 0 9.38-.57a3.02 3.02 0 002.12-2.13A31.6 31.6 0 0024 12a31.6 31.6 0 00-.5-5.8zM9.75 15.5v-7l6.5 3.5z"/></svg></a>
          <a href="#" id="footerSocialLinkedin" aria-label="LinkedIn" hidden><svg viewBox="0 0 24 24" width="16" fill="currentColor"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.86 0-2.14 1.45-2.14 2.94v5.67H9.34V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.45zM5.34 7.43a2.07 2.07 0 110-4.13 2.07 2.07 0 010 4.13zM7.12 20.45H3.56V9h3.56zM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0z"/></svg></a>
        </div>
      </div>
      <div class="site-footer-col">
        <h4>Tienda</h4>
        <a href="/tienda.html#catalogo">Catálogo de Productos</a>
        <a href="#">Nuevos Ingresos</a>
        <a href="#">Ofertas y Promociones</a>
        <a href="#">Marcas</a>
        <a href="/tienda.html#catalogo">Todos los Productos</a>
      </div>
      <div class="site-footer-col">
        <h4>Nosotros</h4>
        <a href="/nosotros">Quiénes Somos</a>
        <a href="#">Proyectos y Servicios</a>
        <a href="#">Blog / Novedades</a>
        <a href="#">Trabaja con Nosotros</a>
        <a href="#">Ubicación</a>
      </div>
      <div class="site-footer-col">
        <h4>Servicios</h4>
        <a href="#">Soluciones Empresariales</a>
        <a href="#">Implementación TI</a>
        <a href="#">Infraestructura de Redes</a>
        <a href="#">Seguridad y Videovigilancia</a>
        <a href="/servicios">Soporte y Mantenimiento</a>
      </div>
      <div class="site-footer-col site-footer-contact">
        <h4>Contáctanos</h4>
        <a href="#" id="footerWhatsappLink" hidden><svg viewBox="0 0 24 24" width="17" fill="currentColor"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zm0 18.15c-1.48 0-2.93-.4-4.2-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.26 8.26 0 01-1.27-4.38c0-4.54 3.7-8.24 8.24-8.24 2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 012.41 5.83c.01 4.54-3.7 8.23-8.21 8.23zm4.52-6.16c-.25-.12-1.47-.72-1.7-.81-.23-.08-.39-.12-.56.13-.17.25-.64.81-.78.97-.14.17-.29.19-.54.06-.25-.12-1.04-.38-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.12-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.42-.14-.01-.31-.01-.48-.01-.17 0-.43.06-.66.31-.23.25-.86.84-.86 2.05s.88 2.38 1 2.55c.12.17 1.74 2.65 4.21 3.72.59.25 1.05.4 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.14-1.18-.06-.1-.23-.16-.48-.28z"/></svg><span><strong id="footerWhatsappText"></strong><small>Escríbenos por WhatsApp</small></span></a>
        <a href="#" id="footerEmailLink" hidden><i data-lucide="mail" width="17"></i><span><strong id="footerEmailText"></strong><small>Respuesta rápida</small></span></a>
        <div class="site-footer-contact-item" id="footerAddressItem" hidden><i data-lucide="map-pin" width="17"></i><span><strong id="footerAddressText"></strong></span></div>
      </div>
      <div class="site-footer-whatsapp-card" id="footerWhatsappCard" hidden>
        <svg viewBox="0 0 24 24" width="26" fill="currentColor"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zm0 18.15c-1.48 0-2.93-.4-4.2-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.26 8.26 0 01-1.27-4.38c0-4.54 3.7-8.24 8.24-8.24 2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 012.41 5.83c.01 4.54-3.7 8.23-8.21 8.23zm4.52-6.16c-.25-.12-1.47-.72-1.7-.81-.23-.08-.39-.12-.56.13-.17.25-.64.81-.78.97-.14.17-.29.19-.54.06-.25-.12-1.04-.38-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.12-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.42-.14-.01-.31-.01-.48-.01-.17 0-.43.06-.66.31-.23.25-.86.84-.86 2.05s.88 2.38 1 2.55c.12.17 1.74 2.65 4.21 3.72.59.25 1.05.4 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.14-1.18-.06-.1-.23-.16-.48-.28z"/></svg>
        <strong>¿Tienes dudas?</strong>
        <h4>Escríbenos por WhatsApp</h4>
        <p>Te asesoramos en productos y soluciones para tu hogar o empresa.</p>
        <a href="#" id="footerWhatsappCta" class="site-footer-whatsapp-btn"><svg viewBox="0 0 24 24" width="16" fill="currentColor"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zm0 18.15c-1.48 0-2.93-.4-4.2-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.26 8.26 0 01-1.27-4.38c0-4.54 3.7-8.24 8.24-8.24 2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 012.41 5.83c.01 4.54-3.7 8.23-8.21 8.23zm4.52-6.16c-.25-.12-1.47-.72-1.7-.81-.23-.08-.39-.12-.56.13-.17.25-.64.81-.78.97-.14.17-.29.19-.54.06-.25-.12-1.04-.38-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.12-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.42-.14-.01-.31-.01-.48-.01-.17 0-.43.06-.66.31-.23.25-.86.84-.86 2.05s.88 2.38 1 2.55c.12.17 1.74 2.65 4.21 3.72.59.25 1.05.4 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.14-1.18-.06-.1-.23-.16-.48-.28z"/></svg> Abrir WhatsApp <i data-lucide="arrow-right" width="15"></i></a>
      </div>
    </div>
    <div class="site-footer-badges">
      <span><i data-lucide="shield-check" width="18"></i> Productos originales</span>
      <span><i data-lucide="award" width="18"></i> Garantía oficial</span>
      <span><i data-lucide="package-check" width="18"></i> Devoluciones seguras</span>
      <span><i data-lucide="headphones" width="18"></i> Soporte técnico</span>
      <span><i data-lucide="handshake" width="18"></i> Asesoría especializada</span>
      <div class="site-footer-payments"><small>Métodos de pago:</small>
        <span class="payment-badge" aria-label="Visa"><svg viewBox="0 0 48 30" width="40" height="26" role="img"><rect width="48" height="30" fill="#fff"/><text x="24" y="20" font-family="Arial, Helvetica, sans-serif" font-size="13" font-weight="900" font-style="italic" fill="#1434CB" text-anchor="middle">VISA</text></svg></span>
        <span class="payment-badge" aria-label="Mastercard"><svg viewBox="0 0 48 30" width="40" height="26" role="img"><rect width="48" height="30" fill="#fff"/><circle cx="20" cy="15" r="9" fill="#EB001B"/><circle cx="28" cy="15" r="9" fill="#F79E1B" fill-opacity=".92"/></svg></span>
        <span class="payment-badge" aria-label="American Express"><svg viewBox="0 0 48 30" width="40" height="26" role="img"><rect width="48" height="30" fill="#006FCF"/><text x="24" y="19" font-family="Arial, Helvetica, sans-serif" font-size="9.5" font-weight="800" fill="#fff" text-anchor="middle">AMEX</text></svg></span>
        <span class="payment-badge" aria-label="Diners Club"><svg viewBox="0 0 48 30" width="40" height="26" role="img"><rect width="48" height="30" fill="#fff"/><circle cx="24" cy="15" r="10" fill="#0079BE"/><path d="M24 7a8 8 0 0 0 0 16z" fill="#fff"/></svg></span>
        <span class="payment-badge" aria-label="Banco Pichincha"><svg viewBox="0 0 48 30" width="40" height="26" role="img"><rect width="48" height="30" fill="#FFCB05"/><text x="24" y="18" font-family="Arial, Helvetica, sans-serif" font-size="7" font-weight="900" fill="#0A3D62" text-anchor="middle">PICHINCHA</text></svg></span>
        <span class="payment-badge" aria-label="PayPhone"><svg viewBox="0 0 48 30" width="40" height="26" role="img"><rect width="48" height="30" fill="#00B4A6"/><text x="24" y="19" font-family="Arial, Helvetica, sans-serif" font-size="8" font-weight="800" fill="#fff" text-anchor="middle">PayPhone</text></svg></span>
      </div>
    </div>
    <div class="site-footer-bottom">
      <span id="footerCopyright">© SMART-ISP. Todos los derechos reservados.</span>
      <div><a href="#">Términos y Condiciones</a><a href="#">Política de Privacidad</a><a href="#">Políticas de Garantía</a></div>
    </div>
  </footer>
  <link rel="stylesheet" href="/assets/css/site-footer.css?v=1">
  <script src="/assets/js/site-footer.js?v=1" defer></script>
  <script src="/assets/js/storefront-discovery.js?v=mobile-category-drawer-20261008" defer></script>
  <script src="/assets/js/public-account.js" defer></script>
  <script src="/assets/js/storefront-product-card.js" defer></script>
  <script>
    const featuredFilterPanel = document.getElementById('featuredFilterPanel');
    if (featuredFilterPanel && window.matchMedia('(max-width: 760px)').matches) featuredFilterPanel.open = false;
  </script>
</body>
</html>
