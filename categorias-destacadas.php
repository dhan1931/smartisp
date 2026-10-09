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

function categoryIconHub(string $name): string {
    $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $name = strtolower($normalized !== false ? $normalized : $name);
    foreach ([
        'printer' => ['impres', 'tinta', 'toner', 'consumible'],
        'cpu' => ['componente', 'procesador', 'memoria', 'almacenamiento'],
        'network' => ['red', 'conectiv', 'router', 'wifi', 'fibra'],
        'shield' => ['seguridad', 'vigilancia', 'camara', 'cámara', 'control de acceso'],
        'monitor' => ['monitor', 'pantalla', 'computador', 'computación', 'computacion'],
        'headphones' => ['audio', 'video', 'parlante', 'auricular'],
        'keyboard' => ['perif', 'accesorio'],
        'zap' => ['energ', 'bater', 'ups', 'climat'],
        'smartphone' => ['celular', 'móvil', 'movil', 'telefon'],
        'gamepad-2' => ['gaming', 'gamer', 'juego'],
        'briefcase-business' => ['oficina', 'empresa', 'punto de venta'],
        'code-xml' => ['software', 'licencia'],
    ] as $icon => $terms) {
        foreach ($terms as $term) {
            if (str_contains($name, $term)) return $icon;
        }
    }
    return 'package';
}

function categoryDescriptionHub(string $name): string {
    $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $searchName = strtolower($normalized !== false ? $normalized : $name);
    foreach ([
        'impres' => 'Impresión, escaneo y suministros para cada espacio de trabajo.',
        'red' => 'Conectividad, cobertura y equipos para redes confiables.',
        'seguridad' => 'Videovigilancia y control para proteger tus espacios.',
        'vigilancia' => 'Cámaras y soluciones para monitorear hogar y negocio.',
        'componente' => 'Componentes para actualizar, ampliar y armar tus equipos.',
        'comput' => 'Equipos listos para trabajar, estudiar y crear.',
        'monitor' => 'Pantallas para productividad, señalización y entretenimiento.',
        'energ' => 'Respaldo y protección eléctrica para tus equipos.',
        'accesorio' => 'Complementos prácticos para tu día a día tecnológico.',
        'perif' => 'Teclados, mouse y herramientas para tu espacio de trabajo.',
        'software' => 'Licencias y herramientas digitales para tu operación.',
        'audio' => 'Audio y video para comunicar, trabajar y disfrutar.',
    ] as $term => $description) {
        if (str_contains($searchName, $term)) return $description;
    }
    return 'Encuentra equipos y soluciones seleccionados para esta categoría.';
}

$siteUrl = 'https://smart-isp.com.ec';
$categories = [];
$pdo = getDbConnection();
if ($pdo) {
    try {
        $pTable = getProductsTableName($pdo);
        ensureProductTableColumns($pdo, $pTable);
        $stmt = $pdo->query("
            SELECT p.category AS name, COUNT(*) AS total, MAX(p.updated_at) AS updated_at,
              (
                SELECT NULLIF(TRIM(p2.image_url), '')
                FROM `$pTable` p2
                WHERE p2.visible = 1 AND p2.category = p.category AND TRIM(p2.image_url) <> ''
                ORDER BY p2.updated_at DESC
                LIMIT 1
              ) AS image_url
            FROM `$pTable` p
            WHERE p.visible = 1 AND TRIM(p.category) <> ''
            GROUP BY p.category
            ORDER BY total DESC, p.category ASC
            LIMIT 36
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $categories[] = $row;
        }
    } catch (Throwable $e) {
        error_log('Error cargando categorias-destacadas.php: ' . $e->getMessage());
    }
}
$featuredCategories = array_slice($categories, 0, 4);
$otherCategories = array_slice($categories, 4);
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
  <script src="/assets/js/public-brand.js?v=landing-logo" defer></script>
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
    body{background:#f3f7f9;color:#102c3d}
    main.page-wrap,footer.page-wrap{width:min(1440px,calc(100% - 48px))}
    .category-hero{display:grid;grid-template-columns:minmax(0,.88fr) minmax(0,1.12fr);align-items:center;gap:28px;min-height:310px;margin:22px auto 34px;padding:30px 36px;border:1px solid #d8eaf1;border-radius:12px;background:#fff;overflow:hidden}
    .category-hero-copy{max-width:590px}.category-eyebrow,.section-eyebrow{display:block;color:#087ea4;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.12em}
    .category-hero h1{max-width:590px;margin:10px 0 12px;color:#102c3d;font-size:48px;line-height:1.02;letter-spacing:0}
    .category-hero p{max-width:530px;margin:0;color:#60798f;font-size:15px;line-height:1.55}
    .category-hero-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:20px}.category-hero .btn{gap:8px;min-height:44px;padding:0 16px;border-radius:6px}.category-hero .btn.primary{background:#087ea4}.category-hero .btn.secondary{background:white;color:#087ea4;border-color:#b9d9e5}
    .hero-audiences{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}.hero-audiences span{display:inline-flex;align-items:center;gap:6px;color:#31536a;font-size:12px;font-weight:700}.hero-audiences svg{color:#087ea4}
    .category-hero-mosaic{display:grid;grid-template-columns:1fr 1fr;grid-template-rows:132px 132px;gap:10px;min-width:0}
    .hero-product-tile{position:relative;display:grid;place-items:center;min-width:0;overflow:hidden;border:1px solid #d7eaf2;border-radius:8px;background:#eaf6fb}
    .hero-product-tile:nth-child(2){background:#edf7f3;border-color:#d6ece2}.hero-product-tile:nth-child(3){background:#edf2ff;border-color:#dce4fb}.hero-product-tile:nth-child(4){background:#f4effd;border-color:#e6dcf7}
    .hero-product-tile img{width:100%;height:100%;padding:8px;object-fit:contain}.hero-product-tile .tile-fallback{font-size:34px;color:#087ea4}
    .category-section-head{display:flex;align-items:end;justify-content:space-between;gap:18px;margin:0 0 14px}.category-section-head h2{margin:3px 0 0;color:#102c3d;font-size:29px;line-height:1.15;letter-spacing:0}.category-section-head p{margin:5px 0 0;color:#60798f;font-size:13px}.category-total{display:inline-flex;align-items:center;gap:8px;padding:9px 13px;border:1px solid #cfe2ec;border-radius:999px;background:#fff;color:#31536a;font-size:12px;font-weight:800;white-space:nowrap}
    .featured-category-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}
    .featured-category{--accent:#0787bb;--tint:#e4f5fc;position:relative;display:flex;min-height:168px;overflow:hidden;padding:16px;border:1px solid #d7e7ee;border-top:3px solid var(--accent);border-radius:8px;background:linear-gradient(105deg,#fff 0%,#fff 52%,var(--tint) 100%);transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease}
    .featured-category:nth-child(2){--accent:#3e83eb;--tint:#eaf1ff}.featured-category:nth-child(3){--accent:#14a879;--tint:#e7f7f0}.featured-category:nth-child(4){--accent:#9362da;--tint:#f2ecfc}
    .featured-category:hover{transform:translateY(-2px);border-color:var(--accent);box-shadow:0 10px 24px rgba(16,44,61,.09)}
    .featured-copy{position:relative;z-index:1;display:flex;flex:0 0 62%;flex-direction:column;align-items:flex-start;min-width:0}
    .featured-title-row{display:flex;align-items:center;gap:10px}.featured-icon{display:grid;place-items:center;flex:0 0 42px;width:42px;height:42px;border-radius:8px;background:var(--accent);color:#fff}.featured-icon svg{width:21px;height:21px}
    .featured-category h3{margin:0;color:#102c3d;font-size:16px;line-height:1.25;letter-spacing:0}.featured-description{max-width:230px;margin:9px 0;color:#60798f;font-size:12px;line-height:1.4}
    .featured-count{display:inline-flex;align-items:center;gap:6px;margin-top:auto;padding:6px 10px;border-radius:999px;background:#102c3d;color:#fff;font-size:11px;font-weight:800}.featured-count svg{width:14px;height:14px}
    .featured-image{position:absolute;right:0;top:0;bottom:0;width:43%;display:grid;place-items:center}.featured-image img{width:100%;height:100%;padding:8px 4px;object-fit:contain}.featured-image svg{width:42px;height:42px;color:var(--accent);opacity:.65}
    .category-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:38px}
    .category-link{display:grid;grid-template-columns:44px minmax(0,1fr) 18px;align-items:center;gap:10px;min-height:76px;padding:11px 13px;border:1px solid #e0ebf0;border-radius:8px;background:#fff;transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease}
    .category-link:hover{transform:translateY(-1px);border-color:#a8d4e5;box-shadow:0 7px 18px rgba(16,44,61,.07)}
    .category-link-icon{display:grid;place-items:center;width:42px;height:42px;border-radius:8px;background:#e9f5fb;color:#087ea4}.category-link-icon svg{width:21px;height:21px}
    .category-link-copy{min-width:0}.category-link strong{display:block;overflow-wrap:anywhere;color:#102c3d;font-size:14px;line-height:1.25}.category-link small{display:inline-flex;margin-top:4px;color:#60798f;font-size:11px;font-weight:700}.category-link-arrow{color:#5794b3}
    .category-empty{padding:24px;border:1px solid #d8e5e7;border-radius:8px;background:#fff;color:#60798f}
    @media(max-width:1100px){.featured-category-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.category-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.category-hero{grid-template-columns:minmax(0,.9fr) minmax(0,1.1fr);padding:26px}.category-hero-mosaic{grid-template-rows:112px 112px}}
    @media(max-width:760px){main.page-wrap,footer.page-wrap{width:calc(100% - 28px)}.category-hero{grid-template-columns:1fr;gap:18px;min-height:0;margin:14px auto 26px;padding:22px}.category-hero h1{max-width:550px;font-size:36px}.category-hero p{font-size:14px}.category-hero-mosaic{grid-template-rows:100px 100px}.hero-audiences{gap:12px}.category-section-head{align-items:flex-start;flex-direction:column;gap:10px}.category-section-head h2{font-size:25px}.featured-category-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.featured-category{min-height:160px;padding:12px}.featured-copy{flex-basis:74%}.featured-title-row{align-items:flex-start;gap:8px}.featured-icon{flex-basis:36px;width:36px;height:36px}.featured-category h3{font-size:14px}.featured-description{font-size:11px;margin:7px 0}.featured-image{width:38%;opacity:.9}.category-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.category-link{grid-template-columns:36px minmax(0,1fr) 14px;gap:8px;min-height:70px;padding:9px}.category-link-icon{width:36px;height:36px}.category-link strong{font-size:12px}.category-link small{font-size:10px}footer.page-wrap{padding-bottom:96px}}
    @media(max-width:420px){.category-hero h1{font-size:32px}.category-hero-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.category-hero-actions .btn{min-width:0;min-height:48px;padding:7px;font-size:11px;line-height:1.25;text-align:center}.category-hero-mosaic{grid-template-rows:84px 84px}.featured-category{min-height:152px;padding:10px}.featured-icon{flex-basis:32px;width:32px;height:32px}.featured-category h3{font-size:12.5px}.featured-description{font-size:10px}.featured-count{padding:5px 7px;font-size:10px}.category-link{grid-template-columns:32px minmax(0,1fr) 12px;gap:6px;padding:8px}.category-link-icon{width:32px;height:32px}.category-link-icon svg{width:18px;height:18px}.category-link strong{font-size:11px}}
    @media(prefers-reduced-motion:reduce){.featured-category,.category-link{transition:none}.featured-category:hover,.category-link:hover{transform:none}}
  </style>
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
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(258px,1fr));gap:14px;margin:0 0 36px}.card{background:#fff;border:1px solid #d9e8ef;border-radius:8px;padding:18px;min-height:142px;display:flex;flex-direction:column;gap:12px;position:relative;overflow:hidden;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease}.card:after{content:'';position:absolute;right:-28px;top:-28px;width:86px;height:86px;border-radius:50%;background:#eff8fb}.card:hover{transform:translateY(-3px);border-color:#61b6d4;box-shadow:0 16px 34px rgba(16,44,61,.1)}.card-top{display:flex;align-items:center;gap:12px;position:relative;z-index:1}.mark{width:42px;height:42px;border-radius:8px;background:#e8f4fc;color:var(--blue);display:grid;place-items:center;font-weight:900}.card strong{display:block;color:var(--navy);font-size:17px;line-height:1.25}.card span{display:block;color:var(--muted);font-size:13px;line-height:1.45;position:relative;z-index:1}.card b{display:inline-flex;width:max-content;color:#fff;background:#0e3347;border-radius:999px;padding:5px 10px;margin-top:auto;font-size:12px;position:relative;z-index:1}.empty{background:#fff;border:1px solid var(--line);border-radius:12px;padding:24px}footer{padding:28px 0 96px;color:var(--muted);font-size:13px;border-top:1px solid var(--line);display:flex;justify-content:space-between;gap:20px}
    .mobile-bottom-nav{display:none;position:fixed;left:0;right:0;bottom:0;height:calc(58px + env(safe-area-inset-bottom,0px));padding-bottom:env(safe-area-inset-bottom,0px);background:rgba(255,255,255,.96);border-top:1px solid var(--line);box-shadow:0 -8px 22px rgba(16,44,61,.09);z-index:1200;align-items:center;justify-content:space-around}.mobile-nav-item{display:flex;flex-direction:column;align-items:center;gap:3px;border:0;background:transparent;color:#587084;font-size:11px;font-weight:800;text-decoration:none}.mobile-nav-item.is-active{color:var(--blue)}.mobile-nav-cart{position:relative}.mobile-nav-cart b{position:absolute;top:-5px;right:12px;min-width:16px;height:16px;border-radius:99px;background:var(--gold);display:grid;place-items:center;font-size:10px;color:#172b3a}
    @media(max-width:1080px){.nav{display:flex;flex-wrap:wrap}.search{order:3;flex:1 1 100%}.search input{text-align:left}}
    @media(max-width:800px){body{padding-bottom:calc(76px + env(safe-area-inset-bottom,0px))}.topbar{display:none}.mobile-bottom-nav{display:flex}.public-menu-btn{display:inline-flex}.public-nav{display:none;flex-direction:column;align-items:stretch;gap:0;padding:6px 16px;box-shadow:0 10px 20px rgba(16,44,61,.14)}.public-nav.is-open{display:flex}.public-nav a{padding:12px 4px;border-bottom:1px solid rgba(255,255,255,.12)}.public-nav a:last-child{border-bottom:0}.nav{padding:12px 4vw;gap:10px;overflow:hidden}.logo img{max-width:140px}.nav-actions{gap:8px}.nav-action span{display:none}.hero{grid-template-columns:1fr;padding:24px}.section-head{align-items:flex-start;flex-direction:column}.grid{grid-template-columns:1fr}footer{flex-direction:column}}
    @media(max-width:800px){.public-menu-btn{display:none!important}.public-nav{display:flex!important;position:sticky;top:var(--sticky-nav-height,70px);flex-direction:row;align-items:center;justify-content:flex-start;gap:18px;padding:10px 16px;overflow-x:auto;white-space:nowrap}.public-nav a{flex:0 0 auto;padding:3px 0;border:0}}
  </style>
  <style>.nav, .public-nav { position: relative !important; top: auto !important; }</style>
</head>
<body>
  <div class="topbar"><span><i data-lucide="truck" width="14"></i> Envio gratis en Guayas</span><span><i data-lucide="clock" width="13"></i> Atencion experta · Lun a Vie 9:00 a 18:00</span></div>
  <header class="nav">
    <a class="logo" href="/" title="SmartISP Tienda Online">SmartISP</a>
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
  <main class="page-wrap">
    <section class="category-hero">
      <div class="category-hero-copy">
        <div class="category-eyebrow">Compra por necesidad</div>
        <h1>Categorías destacadas para encontrar rápido lo que necesitas</h1>
        <p>Explora equipos, componentes, redes, seguridad, accesorios y soluciones TI para tu hogar o empresa.</p>
        <div class="category-hero-actions">
          <a class="btn primary" href="#familias"><i data-lucide="layout-grid" width="17"></i> Ver todas las categorías</a>
          <a class="btn secondary" href="/productos-destacados"><i data-lucide="tag" width="17"></i> Ver productos destacados</a>
        </div>
        <div class="hero-audiences" aria-label="Soluciones para distintos espacios">
          <span><i data-lucide="house" width="16"></i> Hogar</span>
          <span><i data-lucide="building-2" width="16"></i> Empresas</span>
          <span><i data-lucide="users-round" width="16"></i> Instituciones</span>
        </div>
      </div>
      <div class="category-hero-mosaic" aria-label="Productos de las principales categorías">
        <?php foreach ($featuredCategories as $index => $category): ?>
          <?php $image = trim((string)($category['image_url'] ?? '')); if (str_starts_with($image, 'uploads/')) $image = '/' . $image; $image = preg_match('~^(https?://|/)~i', $image) ? $image : ''; ?>
          <div class="hero-product-tile">
            <?php if ($image !== ''): ?><img src="<?= escCategoryHub($image) ?>" alt="Producto de <?= escCategoryHub((string)$category['name']) ?>" width="320" height="180" loading="<?= $index < 2 ? 'eager' : 'lazy' ?>" <?= $index === 0 ? 'fetchpriority="high"' : '' ?>><?php else: ?><i class="tile-fallback" data-lucide="<?= escCategoryHub(categoryIconHub((string)$category['name'])) ?>"></i><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php $fallbackIcons = ['router', 'printer', 'shield-check', 'monitor']; for ($index = count($featuredCategories); $index < 4; $index++): ?>
          <div class="hero-product-tile"><i class="tile-fallback" data-lucide="<?= $fallbackIcons[$index] ?>"></i></div>
        <?php endfor; ?>
      </div>
    </section>
    <?php if (!empty($categories)): ?>
      <div class="category-section-head" id="familias">
        <div>
          <span class="section-eyebrow">Nuestras categorías</span>
          <h2>Familias principales</h2>
          <p>Empieza por las más exploradas o recorre todas las familias del catálogo.</p>
        </div>
        <span class="category-total"><i data-lucide="layers" width="17"></i> <?= count($categories) ?> categorías</span>
      </div>
      <section class="featured-category-grid" aria-label="Familias con más productos">
        <?php foreach ($featuredCategories as $index => $category): ?>
          <?php
            $name = (string)$category['name'];
            $image = trim((string)($category['image_url'] ?? ''));
            if (str_starts_with($image, 'uploads/')) $image = '/' . $image;
            $image = preg_match('~^(https?://|/)~i', $image) ? $image : '';
          ?>
          <a class="featured-category" href="/categoria/<?= rawurlencode(seoSlugCategoryHub($name)) ?>/">
            <div class="featured-copy">
              <div class="featured-title-row">
                <span class="featured-icon"><i data-lucide="<?= escCategoryHub(categoryIconHub($name)) ?>"></i></span>
                <h3><?= escCategoryHub($name) ?></h3>
              </div>
              <p class="featured-description"><?= escCategoryHub(categoryDescriptionHub($name)) ?></p>
              <span class="featured-count"><?= number_format((int)$category['total'], 0, ',', '.') ?> productos <i data-lucide="chevron-right"></i></span>
            </div>
            <?php if ($image !== ''): ?><span class="featured-image"><img src="<?= escCategoryHub($image) ?>" alt="" width="180" height="150" loading="lazy"></span><?php else: ?><span class="featured-image"><i data-lucide="<?= escCategoryHub(categoryIconHub($name)) ?>"></i></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </section>
      <?php if (!empty($otherCategories)): ?>
        <section class="category-grid" aria-label="Más categorías del catálogo">
          <?php foreach ($otherCategories as $category): ?>
            <?php $name = (string)$category['name']; ?>
            <a class="category-link" href="/categoria/<?= rawurlencode(seoSlugCategoryHub($name)) ?>/">
              <span class="category-link-icon"><i data-lucide="<?= escCategoryHub(categoryIconHub($name)) ?>"></i></span>
              <span class="category-link-copy"><strong><?= escCategoryHub($name) ?></strong><small><?= number_format((int)$category['total'], 0, ',', '.') ?> productos</small></span>
              <i class="category-link-arrow" data-lucide="chevron-right" width="17"></i>
            </a>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
    <?php else: ?>
      <section class="category-empty">No hay categorías visibles todavía. <a href="/tienda.html">Ir al catálogo completo</a>.</section>
    <?php endif; ?>
  </main>
  <footer class="page-wrap">SmartISP Ecuador · Categorías comerciales para descubrir productos y soluciones.</footer>
  <nav class="mobile-bottom-nav" aria-label="Navegación rápida móvil">
    <a class="mobile-nav-item" href="/tienda.html"><i data-lucide="store" width="20"></i><span>Tienda</span></a>
    <a class="mobile-nav-item is-active" href="/categorias-destacadas"><i data-lucide="layout-grid" width="20"></i><span>Categorías</span></a>
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
  <script src="/assets/js/public-account.js" defer></script>
</body>
</html>
