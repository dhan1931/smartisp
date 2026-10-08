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
  <script src="https://unpkg.com/lucide@latest"></script>
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
    :root{--navy:#102c3d;--blue:#087ea4;--pale:#f3f7f8;--line:#d8e5e7;--muted:#60798f;--white:#fff;--gold:#f5a524}
    *{box-sizing:border-box}body{margin:0;background:var(--pale);color:#17324d;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}a{text-decoration:none;color:inherit}button,input{font:inherit}
    .topbar{background:var(--navy);color:#d9efff;font-size:12px;padding:8px 5vw;display:flex;justify-content:space-between;gap:18px}
    svg{width:1em;height:1em;vertical-align:-.14em}.nav{background:#fff;border-bottom:1px solid var(--line);padding:15px 5vw;display:grid;grid-template-columns:minmax(120px,220px) minmax(0,680px) minmax(180px,1fr);align-items:center;gap:20px;position:sticky;top:0;z-index:1000;box-shadow:0 5px 18px rgba(16,44,61,.06)}.logo{display:inline-flex;align-items:center;color:var(--navy)}html:not(.public-brand-ready) .logo{opacity:0}.public-brand-ready .logo{opacity:1;transition:opacity .12s ease}.logo img{display:block;object-fit:contain;width:auto;max-width:100%}
    .search{display:flex;width:100%;min-width:0;justify-self:center;background:#f3f7f8;border:2px solid #d8e8f1;border-radius:8px;overflow:hidden;box-shadow:0 8px 20px rgba(16,44,61,.06)}.search:focus-within{border-color:var(--blue);box-shadow:0 0 0 3px rgba(8,126,164,.11)}.search input{width:100%;border:0;outline:0;padding:13px 16px;background:transparent;color:#17324d;text-align:center}.search button{background:var(--blue);border:0;color:#fff;width:48px;display:grid;place-items:center}
    .nav-actions{margin-left:auto;display:flex;gap:12px;align-items:center;justify-self:end}.nav-action{border:0;background:transparent;color:var(--navy);display:flex;align-items:center;gap:7px;font-weight:700;padding:7px 8px;border-radius:8px}.nav-action:hover{color:var(--blue);background:#edf7f8}.cart-button{position:relative}.cart-count{background:var(--gold);border-radius:50%;color:#172b3a;font-size:10px;min-width:17px;height:17px;display:grid;place-items:center;position:absolute;top:0;left:22px}
    .public-menu-btn{display:none;align-items:center;justify-content:center;width:38px;height:38px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--navy)}.public-nav{position:sticky;top:var(--sticky-nav-height,70px);z-index:999;background:var(--navy);color:#fff;display:flex;justify-content:center;gap:clamp(22px,5vw,80px);padding:11px 5vw;font-weight:800;font-size:15px;box-shadow:inset 0 1px rgba(255,255,255,.08)}.public-nav a{color:#fff;white-space:nowrap;opacity:.96}.public-nav a:hover,.public-nav a.active{color:#ffd166}
    main,footer{width:min(1160px,calc(100% - 32px));margin:auto}.hero{margin-top:28px;background:#fff;border:1px solid var(--line);border-radius:12px;padding:34px;margin-bottom:18px;display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,330px);gap:28px;align-items:end;box-shadow:0 14px 35px rgba(16,44,61,.06)}.eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.14em;color:var(--blue);font-weight:900}h1{font-size:clamp(32px,5vw,54px);line-height:1.02;margin:8px 0;color:var(--navy);letter-spacing:-.045em}p{color:var(--muted);line-height:1.6}.hero p{max-width:680px}.hero-panel{border-left:4px solid var(--gold);padding-left:18px;color:var(--muted);font-size:14px}.hero-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.btn{display:inline-flex;align-items:center;justify-content:center;border-radius:8px;padding:11px 14px;font-weight:900;font-size:13px}.btn.primary{background:var(--blue);color:#fff}.btn.secondary{background:#edf7f8;color:var(--navy);border:1px solid var(--line)}
    .section-head{display:flex;justify-content:space-between;align-items:end;gap:18px;margin:28px 0 14px}.section-head h2{margin:0;color:var(--navy);font-size:25px;letter-spacing:-.03em}.section-head p{margin:4px 0 0;font-size:13px}.count-pill{border:1px solid var(--line);background:#fff;border-radius:999px;padding:8px 12px;color:var(--muted);font-size:12px;font-weight:800;white-space:nowrap}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:16px;margin:0 0 36px}.card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:12px;display:flex;flex-direction:column;min-height:100%;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease}.card:hover{transform:translateY(-3px);border-color:#9acfe9;box-shadow:0 14px 28px rgba(16,44,61,.09)}.card img{width:100%;aspect-ratio:1.12;object-fit:contain;border:1px solid #edf4f8;border-radius:8px;background:#fff;padding:10px}.card strong{color:var(--navy);font-size:14px;line-height:1.35;margin-top:12px}.card small{color:var(--muted);margin-top:5px;line-height:1.35}.card-foot{margin-top:auto;padding-top:12px;display:flex;align-items:center;justify-content:space-between;gap:10px}.price{color:var(--navy);font-weight:900;font-size:17px}.see{color:var(--blue);font-size:12px;font-weight:900}.empty{background:#fff;border:1px solid var(--line);border-radius:12px;padding:24px}footer{padding:28px 0 32px;color:var(--muted);font-size:13px;border-top:1px solid var(--line);display:flex;justify-content:space-between;gap:20px}
    @media(max-width:1080px){.nav{display:flex;flex-wrap:wrap}.search{order:3;flex:1 1 100%}.search input{text-align:left}}
    .mobile-bottom-nav{display:none;position:fixed;left:0;right:0;bottom:0;height:calc(58px + env(safe-area-inset-bottom,0px));padding-bottom:env(safe-area-inset-bottom,0px);background:rgba(255,255,255,.96);border-top:1px solid var(--line);box-shadow:0 -8px 22px rgba(16,44,61,.09);z-index:1200;align-items:center;justify-content:space-around}.mobile-nav-item{display:flex;flex-direction:column;align-items:center;gap:3px;border:0;background:transparent;color:#587084;font-size:11px;font-weight:800;text-decoration:none}.mobile-nav-item.is-active{color:var(--blue)}.mobile-nav-cart{position:relative}.mobile-nav-cart b{position:absolute;top:-5px;right:12px;min-width:16px;height:16px;border-radius:99px;background:var(--gold);display:grid;place-items:center;font-size:10px;color:#172b3a}
    @media(max-width:800px){body{padding-bottom:calc(76px + env(safe-area-inset-bottom,0px))}.topbar{display:none}.mobile-bottom-nav{display:flex}.public-menu-btn{display:inline-flex}.public-nav{display:none;flex-direction:column;align-items:stretch;gap:0;padding:6px 16px;box-shadow:0 10px 20px rgba(16,44,61,.14)}.public-nav.is-open{display:flex}.public-nav a{padding:12px 4px;border-bottom:1px solid rgba(255,255,255,.12)}.public-nav a:last-child{border-bottom:0}.nav{padding:12px 4vw;gap:10px;overflow:hidden}.logo img{max-width:140px}.nav-actions{gap:8px}.nav-action span{display:none}.hero{grid-template-columns:1fr;padding:24px}.section-head{align-items:flex-start;flex-direction:column}.grid{grid-template-columns:1fr 1fr;gap:10px}.card{padding:10px}footer{flex-direction:column;padding-bottom:96px}}@media(max-width:460px){.grid{grid-template-columns:1fr}}
  </style>
  <style>.nav, .public-nav { position: relative !important; top: auto !important; }</style>
</head>
<body>
  <div class="topbar"><span><i data-lucide="truck" width="14"></i> Envio gratis en Guayas</span><span><i data-lucide="clock" width="13"></i> Atencion experta · Lun a Vie 9:00 a 18:00</span></div>
  <header class="nav">
    <a class="logo" href="/" title="SmartISP Tienda Online"><img src="/assets/favicons/favicon-192x192.png" alt="SmartISP" style="height:38px;width:auto;object-fit:contain;"></a>
    <form class="search" action="/tienda.html" method="get"><input name="q" type="search" placeholder="¿Qué buscas hoy?" aria-label="Buscar productos"><button aria-label="Buscar"><i data-lucide="search" width="18"></i></button></form>
    <div class="nav-actions">
      <a class="nav-action" href="/tienda.html?openAccount=1"><i data-lucide="user" width="16"></i><span>Mi cuenta</span></a>
      <a class="nav-action cart-button" href="/tienda.html?openCart=1"><i data-lucide="shopping-cart" width="20"></i><span>Carrito</span><b class="cart-count">0</b></a>
      <button class="public-menu-btn" type="button" aria-label="Abrir secciones" aria-expanded="false" aria-controls="publicNav"><i data-lucide="menu" width="20"></i></button>
    </div>
  </header>
  <nav class="public-nav" id="publicNav" aria-label="Secciones principales">
    <a href="/categorias-destacadas">Categorias Destacadas</a>
    <a href="/productos-destacados" class="active">Productos Destacados</a>
    <a href="/servicios">Servicios</a>
    <a href="/nosotros">Nosotros</a>
    <a href="/tienda.html#catalogo">Catalogo</a>
  </nav>
  <main>
    <section class="hero">
      <div>
        <div class="eyebrow">Seleccion comercial</div>
        <h1>Productos destacados para redes, empresas y tecnologia</h1>
        <p>Una vitrina rapida de articulos recientes del catalogo SmartISP: conectividad, computo, energia, seguridad, perifericos y equipamiento TI.</p>
        <div class="hero-actions">
          <a class="btn primary" href="/tienda.html#catalogo">Ver catalogo</a>
          <a class="btn secondary" href="/categorias-destacadas">Ver categorias</a>
        </div>
      </div>
      <div class="hero-panel">Seleccion pensada para partir rapido: revisa precio, categoria y ficha antes de cotizar o comprar.</div>
    </section>
    <?php if (!empty($products)): ?>
      <div class="section-head">
        <div>
          <h2>Seleccion destacada</h2>
          <p>Productos visibles del catalogo, ordenados para mostrar novedades y alta rotacion primero.</p>
        </div>
        <span class="count-pill"><?= count($products) ?> productos</span>
      </div>
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
            <span class="card-foot"><span class="price"><?= $price > 0 ? '$' . number_format($price, 2, '.', ',') : 'Cotizar' ?></span><span class="see">Ver ficha</span></span>
          </a>
        <?php endforeach; ?>
      </section>
    <?php else: ?>
      <section class="empty">No hay productos destacados disponibles. <a href="/tienda.html">Ir al catalogo completo</a>.</section>
    <?php endif; ?>
  </main>
  <footer>SmartISP Ecuador · Catalogo tecnologico amplio para empresas, hogares e ISP.</footer>
  <nav class="mobile-bottom-nav" aria-label="Navegación rápida móvil">
    <a class="mobile-nav-item" href="/tienda.html"><i data-lucide="store" width="20"></i><span>Tienda</span></a>
    <a class="mobile-nav-item" href="/categorias-destacadas"><i data-lucide="layout-grid" width="20"></i><span>Categorías</span></a>
    <a class="mobile-nav-item" href="/tienda.html#catalogo"><i data-lucide="search" width="20"></i><span>Buscar</span></a>
    <a class="mobile-nav-item mobile-nav-cart" href="/tienda.html?openCart=1"><i data-lucide="shopping-cart" width="20"></i><span>Carrito</span><b>0</b></a>
    <a class="mobile-nav-item" href="/tienda.html?openAccount=1"><i data-lucide="user" width="20"></i><span>Cuenta</span></a>
  </nav>
  <script>
    const publicMenuBtn = document.querySelector('.public-menu-btn');
    const publicNav = document.querySelector('#publicNav');
    const updateStickyNavOffset = () => {
      const header = document.querySelector('.nav');
      document.documentElement.style.setProperty('--sticky-nav-height', `${header?.offsetHeight || 70}px`);
    };
    updateStickyNavOffset();
    window.addEventListener('resize', updateStickyNavOffset);
    if (publicMenuBtn && publicNav) {
      publicMenuBtn.addEventListener('click', () => {
        const open = publicNav.classList.toggle('is-open');
        publicMenuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        publicMenuBtn.innerHTML = open ? '<i data-lucide="x" width="20"></i>' : '<i data-lucide="menu" width="20"></i>';
        if (window.lucide) lucide.createIcons();
        updateStickyNavOffset();
      });
      publicNav.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
        publicNav.classList.remove('is-open');
        publicMenuBtn.setAttribute('aria-expanded', 'false');
        publicMenuBtn.innerHTML = '<i data-lucide="menu" width="20"></i>';
        if (window.lucide) lucide.createIcons();
      }));
    }
    if (window.lucide) lucide.createIcons();
  </script>
</body>
</html>
