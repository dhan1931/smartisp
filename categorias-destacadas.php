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
  <script src="https://unpkg.com/lucide@latest"></script>
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
    :root{--navy:#102c3d;--blue:#087ea4;--pale:#f3f7f8;--line:#d8e5e7;--muted:#60798f;--white:#fff;--gold:#f5a524}
    *{box-sizing:border-box}body{margin:0;background:var(--pale);color:#17324d;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}a{text-decoration:none;color:inherit}button,input{font:inherit}
    .topbar{background:var(--navy);color:#d9efff;font-size:12px;padding:8px 5vw;display:flex;justify-content:space-between;gap:18px}
    svg{width:1em;height:1em;vertical-align:-.14em}.nav{background:#fff;border-bottom:1px solid var(--line);padding:15px 5vw;display:grid;grid-template-columns:minmax(120px,220px) minmax(0,680px) minmax(180px,1fr);align-items:center;gap:20px;position:sticky;top:0;z-index:1000;box-shadow:0 5px 18px rgba(16,44,61,.06)}.logo{display:inline-flex;align-items:center;color:var(--navy)}html:not(.public-brand-ready) .logo{opacity:0}.public-brand-ready .logo{opacity:1;transition:opacity .12s ease}.logo img{display:block;object-fit:contain;width:auto;max-width:100%}
    .search{display:flex;width:100%;min-width:0;justify-self:center;background:#f3f7f8;border:2px solid #d8e8f1;border-radius:8px;overflow:hidden;box-shadow:0 8px 20px rgba(16,44,61,.06)}.search:focus-within{border-color:var(--blue);box-shadow:0 0 0 3px rgba(8,126,164,.11)}.search input{width:100%;border:0;outline:0;padding:13px 16px;background:transparent;color:#17324d;text-align:center}.search button{background:var(--blue);border:0;color:#fff;width:48px;display:grid;place-items:center}
    .nav-actions{margin-left:auto;display:flex;gap:12px;align-items:center;justify-self:end}.nav-action{border:0;background:transparent;color:var(--navy);display:flex;align-items:center;gap:7px;font-weight:700;padding:7px 8px;border-radius:8px}.nav-action:hover{color:var(--blue);background:#edf7f8}.cart-button{position:relative}.cart-count{background:var(--gold);border-radius:50%;color:#172b3a;font-size:10px;min-width:17px;height:17px;display:grid;place-items:center;position:absolute;top:0;left:22px}
    .public-menu-btn{display:none;align-items:center;justify-content:center;width:38px;height:38px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--navy)}.public-nav{position:sticky;top:var(--sticky-nav-height,70px);z-index:999;background:var(--navy);color:#fff;display:flex;justify-content:center;gap:clamp(22px,5vw,80px);padding:11px 5vw;font-weight:800;font-size:15px;box-shadow:inset 0 1px rgba(255,255,255,.08)}.public-nav a{color:#fff;white-space:nowrap;opacity:.96}.public-nav a:hover,.public-nav a.active{color:#ffd166}
    main,footer{width:min(1160px,calc(100% - 32px));margin:auto}.hero{margin-top:28px;background:#fff;border:1px solid var(--line);border-radius:12px;padding:34px;margin-bottom:18px;display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,330px);gap:28px;align-items:end;box-shadow:0 14px 35px rgba(16,44,61,.06)}.eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.14em;color:var(--blue);font-weight:900}h1{font-size:clamp(32px,5vw,54px);line-height:1.02;margin:8px 0;color:var(--navy);letter-spacing:-.045em}p{color:var(--muted);line-height:1.6}.hero p{max-width:680px}.hero-panel{border-left:4px solid var(--gold);padding-left:18px;color:var(--muted);font-size:14px}.hero-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.btn{display:inline-flex;align-items:center;justify-content:center;border-radius:8px;padding:11px 14px;font-weight:900;font-size:13px}.btn.primary{background:var(--blue);color:#fff}.btn.secondary{background:#edf7f8;color:var(--navy);border:1px solid var(--line)}
    .section-head{display:flex;justify-content:space-between;align-items:end;gap:18px;margin:28px 0 14px}.section-head h2{margin:0;color:var(--navy);font-size:25px;letter-spacing:-.03em}.section-head p{margin:4px 0 0;font-size:13px}.count-pill{border:1px solid var(--line);background:#fff;border-radius:999px;padding:8px 12px;color:var(--muted);font-size:12px;font-weight:800;white-space:nowrap}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(238px,1fr));gap:14px;margin:0 0 36px}.card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:18px;min-height:152px;display:flex;flex-direction:column;gap:12px;position:relative;overflow:hidden;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease}.card:before{content:'';position:absolute;inset:0 0 auto;height:4px;background:var(--blue);opacity:.85}.card:hover{transform:translateY(-3px);border-color:#9acfe9;box-shadow:0 14px 28px rgba(16,44,61,.09)}.card-top{display:flex;align-items:center;gap:12px}.mark{width:42px;height:42px;border-radius:10px;background:#e8f4fc;color:var(--blue);display:grid;place-items:center;font-weight:900}.card strong{display:block;color:var(--navy);font-size:17px;line-height:1.25}.card span{display:block;color:var(--muted);font-size:13px;line-height:1.45}.card b{display:inline-flex;width:max-content;color:#fff;background:var(--navy);border-radius:999px;padding:5px 10px;margin-top:auto;font-size:12px}.empty{background:#fff;border:1px solid var(--line);border-radius:12px;padding:24px}footer{padding:28px 0 32px;color:var(--muted);font-size:13px;border-top:1px solid var(--line);display:flex;justify-content:space-between;gap:20px}
    @media(max-width:1080px){.nav{display:flex;flex-wrap:wrap}.search{order:3;flex:1 1 100%}.search input{text-align:left}}
    @media(max-width:800px){body{padding-bottom:68px}.topbar{display:none}.public-menu-btn{display:inline-flex}.public-nav{display:none;flex-direction:column;align-items:stretch;gap:0;padding:6px 16px;box-shadow:0 10px 20px rgba(16,44,61,.14)}.public-nav.is-open{display:flex}.public-nav a{padding:12px 4px;border-bottom:1px solid rgba(255,255,255,.12)}.public-nav a:last-child{border-bottom:0}.nav{padding:12px 4vw;gap:10px;overflow:hidden}.logo img{max-width:140px}.nav-actions{gap:8px}.nav-action span{display:none}.hero{grid-template-columns:1fr;padding:24px}.section-head{align-items:flex-start;flex-direction:column}.grid{grid-template-columns:1fr}footer{flex-direction:column}}
  </style>
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
    <a href="/categorias-destacadas" class="active">Categorias Destacadas</a>
    <a href="/productos-destacados">Productos Destacados</a>
    <a href="/servicios">Servicios</a>
    <a href="/nosotros">Nosotros</a>
    <a href="/tienda.html#catalogo">Catalogo</a>
  </nav>
  <main>
    <section class="hero">
      <div>
        <div class="eyebrow">Compra por necesidad</div>
        <h1>Categorias destacadas para encontrar rapido lo que necesitas</h1>
        <p>Familias ordenadas del catalogo SmartISP: redes, computo, energia, impresion, seguridad, accesorios, gaming, software y mas soluciones TI.</p>
        <div class="hero-actions">
          <a class="btn primary" href="/tienda.html#catalogo">Ver catalogo</a>
          <a class="btn secondary" href="/productos-destacados">Ver productos</a>
        </div>
      </div>
      <div class="hero-panel">Usa estas entradas cuando no quieres recorrer todo el catalogo plano. Entra por familia, compara y vuelve al catalogo completo cuando quieras.</div>
    </section>
    <?php if (!empty($categories)): ?>
      <div class="section-head">
        <div>
          <h2>Familias principales</h2>
          <p>Las categorias con mas productos visibles y mejor punto de partida para explorar.</p>
        </div>
        <span class="count-pill"><?= count($categories) ?> categorias</span>
      </div>
      <section class="grid" aria-label="Categorias destacadas">
        <?php foreach ($categories as $category): ?>
          <?php
            $name = (string)$category['name'];
            $initial = strtoupper(substr($name, 0, 1));
          ?>
          <a class="card" href="/categoria/<?= rawurlencode(seoSlugCategoryHub($name)) ?>/">
            <div class="card-top">
              <span class="mark"><?= escCategoryHub($initial) ?></span>
              <strong><?= escCategoryHub($name) ?></strong>
            </div>
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
