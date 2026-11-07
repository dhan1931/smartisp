<?php
require_once __DIR__ . '/api/lib/db.php';

function seoSlugCategoryHub(string $text): string {
    return smartispSlug($text, 90, 'categoria');
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

function configuredCategoryIconHub(array $category): string {
    $icon = trim((string)($category['category_icon'] ?? ''));
    $knownIcons = ['laptop','network','cable','server','shield-check','zap','monitor','hard-drive','headphones','printer','phone-call','file-code','package','cpu','wifi','camera'];
    return in_array($icon, $knownIcons, true) ? $icon : categoryIconHub((string)($category['name'] ?? ''));
}

function categoryHubImageUrl(array $category): string {
    $banner = trim((string)($category['category_banner'] ?? ''));
    if ($banner !== '') {
        $safeLocal = str_starts_with($banner, '/') && !str_starts_with($banner, '//') && !str_contains($banner, '..') && !str_contains($banner, '\\');
        $safeRemote = filter_var($banner, FILTER_VALIDATE_URL) !== false && strtolower((string)parse_url($banner, PHP_URL_SCHEME)) === 'https';
        if (($safeLocal || $safeRemote) && !preg_match('/[\\x00-\\x1F\\x7F]/', $banner)) return $banner;
    }
    $image = trim((string)($category['image_url'] ?? ''));
    if ($image === '') return '';
    if (str_starts_with($image, 'uploads/')) $image = '/' . $image;
    if (!preg_match('~^(https?://|/)~i', $image)) return '';

    if (!empty($category['image_product_id']) && isExternalSupplierImageUrl($image)) {
        $image = buildProductProxyImageUrl((string)$category['image_product_id'], $image);
        $image .= '&imgrev=3';
    }
    return $image;
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
              image_product.image_url AS image_url,
              image_product.id AS image_product_id,
              MAX(category_config.icon) AS category_icon,
              MAX(category_config.description) AS category_description,
              MAX(category_config.banner_image_url) AS category_banner,
              MAX(category_config.banner_alt) AS category_banner_alt
            FROM `$pTable` p
            LEFT JOIN (
              SELECT LOWER(TRIM(name)) AS category_key, MAX(icon) AS icon, MAX(description) AS description,
                MAX(banner_image_url) AS banner_image_url, MAX(banner_alt) AS banner_alt
              FROM categories_rows GROUP BY LOWER(TRIM(name))
            ) category_config ON category_config.category_key = LOWER(TRIM(p.category))
            LEFT JOIN `$pTable` image_product ON image_product.id = (
                SELECT p2.id
                FROM `$pTable` p2
                WHERE p2.visible = 1 AND p2.category = p.category AND TRIM(p2.image_url) <> ''
                ORDER BY p2.updated_at DESC, p2.id ASC
                LIMIT 1
              )
            WHERE p.visible = 1 AND TRIM(p.category) <> ''
            GROUP BY p.category, image_product.id, image_product.image_url
            ORDER BY total DESC, p.category ASC
            LIMIT 36
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $categories[] = $row;
        }
    } catch (Throwable $e) {
        error_log('Error cargando categorias-destacadas.php: ' . $e->getMessage());
        try {
            $fallback = $pdo->query("\n+                SELECT p.category AS name, COUNT(*) AS total, MAX(p.updated_at) AS updated_at,\n+                  image_product.image_url AS image_url, image_product.id AS image_product_id,\n+                  NULL AS category_icon, NULL AS category_description,\n+                  NULL AS category_banner, NULL AS category_banner_alt\n+                FROM `$pTable` p\n+                LEFT JOIN `$pTable` image_product ON image_product.id = (\n+                  SELECT p2.id FROM `$pTable` p2\n+                  WHERE p2.visible = 1 AND p2.category = p.category AND TRIM(p2.image_url) <> ''\n+                  ORDER BY p2.updated_at DESC, p2.id ASC LIMIT 1\n+                )\n+                WHERE p.visible = 1 AND TRIM(p.category) <> ''\n+                GROUP BY p.category, image_product.id, image_product.image_url\n+                ORDER BY total DESC, p.category ASC\n+                LIMIT 36\n+            ");
            $categories = $fallback->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $fallbackError) {
            error_log('Error en consulta de respaldo de categorias-destacadas.php: ' . $fallbackError->getMessage());
        }
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
  <link rel="stylesheet" href="/assets/css/store/storefront-discovery.css?v=titulos-20261107">
  <script src="/assets/js/store/public-brand.js?v=seo2-20261025" defer></script>
  <script src="/assets/vendor/lucide-icons.min.js?v=68df8f2b"></script>
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
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP) ?>
  </script>
  <style>
    body{background:#f3f7f9;color:#102c3d}
    main.page-wrap,footer.page-wrap{width:min(1440px,calc(100% - 48px))}
    .category-hero{display:grid;grid-template-columns:minmax(0,.88fr) minmax(0,1.12fr);align-items:center;gap:28px;min-height:310px;margin:22px auto 34px;padding:30px 36px;border:1px solid #d8eaf1;border-radius:12px;background:#fff;overflow:hidden}
    .category-hero-copy{max-width:590px}.category-eyebrow,.section-eyebrow{display:block;color:#087ea4;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.12em}
    .category-hero h1{max-width:590px;margin:10px 0 12px;color:#102c3d;font-size:48px;line-height:1.02;letter-spacing:0}
    .category-hero p{max-width:530px;margin:0;color:#556d82;font-size:15px;line-height:1.55}
    .category-hero-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:20px}.category-hero .btn{gap:8px;min-height:44px;padding:0 16px;border-radius:6px}.category-hero .btn.primary{background:#087ea4}.category-hero .btn.secondary{background:white;color:#087ea4;border-color:#b9d9e5}
    .hero-audiences{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}.hero-audiences span{display:inline-flex;align-items:center;gap:6px;color:#31536a;font-size:12px;font-weight:700}.hero-audiences svg{color:#087ea4}
    .category-hero-mosaic{display:grid;grid-template-columns:1fr 1fr;grid-template-rows:132px 132px;gap:10px;min-width:0}
    .hero-product-tile{position:relative;display:grid;place-items:center;min-width:0;overflow:hidden;border:1px solid #d7eaf2;border-radius:8px;background:#eaf6fb}
    .hero-product-tile:nth-child(2){background:#edf7f3;border-color:#d6ece2}.hero-product-tile:nth-child(3){background:#edf2ff;border-color:#dce4fb}.hero-product-tile:nth-child(4){background:#f4effd;border-color:#e6dcf7}
    .hero-product-tile img{width:100%;height:100%;padding:8px;object-fit:contain}.hero-product-tile .tile-fallback{font-size:34px;color:#087ea4}
    .category-section-head{display:flex;align-items:end;justify-content:space-between;gap:18px;margin:0 0 14px}.category-section-head h2{margin:3px 0 0;color:#102c3d;font-size:29px;line-height:1.15;letter-spacing:0}.category-section-head p{margin:5px 0 0;color:#556d82;font-size:13px}.category-total{display:inline-flex;align-items:center;gap:8px;padding:9px 13px;border:1px solid #cfe2ec;border-radius:999px;background:#fff;color:#31536a;font-size:12px;font-weight:800;white-space:nowrap}
    .featured-category-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}
    .featured-category{--accent:#0787bb;--tint:#e4f5fc;position:relative;display:flex;min-height:168px;overflow:hidden;padding:16px;border:1px solid #d7e7ee;border-top:3px solid var(--accent);border-radius:8px;background:linear-gradient(105deg,#fff 0%,#fff 52%,var(--tint) 100%);transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease}
    .featured-category:nth-child(2){--accent:#3e83eb;--tint:#eaf1ff}.featured-category:nth-child(3){--accent:#14a879;--tint:#e7f7f0}.featured-category:nth-child(4){--accent:#9362da;--tint:#f2ecfc}
    .featured-category:hover{transform:translateY(-2px);border-color:var(--accent);box-shadow:0 10px 24px rgba(16,44,61,.09)}
    .featured-copy{position:relative;z-index:1;display:flex;flex:0 0 62%;flex-direction:column;align-items:flex-start;min-width:0}
    .featured-title-row{display:flex;align-items:center;gap:10px}.featured-icon{display:grid;place-items:center;flex:0 0 42px;width:42px;height:42px;border-radius:8px;background:var(--accent);color:#fff}.featured-icon svg{width:21px;height:21px}
    .featured-category h3{margin:0;color:#102c3d;font-size:16px;line-height:1.25;letter-spacing:0}.featured-description{max-width:230px;margin:9px 0;color:#556d82;font-size:12px;line-height:1.4}
    .featured-count{display:inline-flex;align-items:center;gap:6px;margin-top:auto;padding:6px 10px;border-radius:999px;background:#102c3d;color:#fff;font-size:11px;font-weight:800}.featured-count svg{width:14px;height:14px}
    .featured-image{position:absolute;right:0;top:0;bottom:0;width:43%;display:grid;place-items:center}.featured-image img{width:100%;height:100%;padding:8px 4px;object-fit:contain}.featured-image svg{width:42px;height:42px;color:var(--accent);opacity:.65}
    .category-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:38px}
    .category-link{display:grid;grid-template-columns:44px minmax(0,1fr) 18px;align-items:center;gap:10px;min-height:76px;padding:11px 13px;border:1px solid #e0ebf0;border-radius:8px;background:#fff;transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease}
    .category-link:hover{transform:translateY(-1px);border-color:#a8d4e5;box-shadow:0 7px 18px rgba(16,44,61,.07)}
    .category-link-icon{display:grid;place-items:center;width:42px;height:42px;border-radius:8px;background:#e9f5fb;color:#087ea4}.category-link-icon svg{width:21px;height:21px}
    .category-link-copy{min-width:0}.category-link strong{display:block;overflow-wrap:anywhere;color:#102c3d;font-size:14px;line-height:1.25}.category-link small{display:inline-flex;margin-top:4px;color:#556d82;font-size:11px;font-weight:700}.category-link-arrow{color:#5794b3}
    .category-empty{padding:24px;border:1px solid #d8e5e7;border-radius:8px;background:#fff;color:#556d82}
    @media(max-width:1100px){.featured-category-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.category-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.category-hero{grid-template-columns:minmax(0,.9fr) minmax(0,1.1fr);padding:26px}.category-hero-mosaic{grid-template-rows:112px 112px}}
    @media(max-width:760px){main.page-wrap,footer.page-wrap{width:calc(100% - 28px)}.category-hero{grid-template-columns:1fr;gap:18px;min-height:0;margin:14px auto 26px;padding:22px}.category-hero h1{max-width:550px;font-size:36px}.category-hero p{font-size:14px}.category-hero-mosaic{grid-template-rows:100px 100px}.hero-audiences{gap:12px}.category-section-head{align-items:flex-start;flex-direction:column;gap:10px}.category-section-head h2{font-size:25px}.featured-category-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.featured-category{min-height:160px;padding:12px}.featured-copy{flex-basis:74%}.featured-title-row{align-items:flex-start;gap:8px}.featured-icon{flex-basis:36px;width:36px;height:36px}.featured-category h3{font-size:14px}.featured-description{font-size:11px;margin:7px 0}.featured-image{width:38%;opacity:.9}.category-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.category-link{grid-template-columns:36px minmax(0,1fr) 14px;gap:8px;min-height:70px;padding:9px}.category-link-icon{width:36px;height:36px}.category-link strong{font-size:12px}.category-link small{font-size:10px}footer.page-wrap{padding-bottom:96px}}
    @media(max-width:420px){.category-hero h1{font-size:32px}.category-hero-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.category-hero-actions .btn{min-width:0;min-height:48px;padding:7px;font-size:11px;line-height:1.25;text-align:center}.category-hero-mosaic{grid-template-rows:84px 84px}.featured-category{min-height:152px;padding:10px}.featured-icon{flex-basis:32px;width:32px;height:32px}.featured-category h3{font-size:12.5px}.featured-description{font-size:10px}.featured-count{padding:5px 7px;font-size:10px}.category-link{grid-template-columns:32px minmax(0,1fr) 12px;gap:6px;padding:8px}.category-link-icon{width:32px;height:32px}.category-link-icon svg{width:18px;height:18px}.category-link strong{font-size:11px}}
    @media(prefers-reduced-motion:reduce){.featured-category,.category-link{transition:none}.featured-category:hover,.category-link:hover{transform:none}}
  </style>
  <style>
    :root{--navy:#102c3d;--blue:#087ea4;--pale:#f3f7f8;--line:#d8e5e7;--muted:#556d82;--white:#fff;--gold:#f5a524}
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
  <link rel="stylesheet" href="/assets/css/store/category-catalog.css?v=seo2-20261025">
</head>
<body>
  <?php $storefrontActivePage = 'categories'; require __DIR__ . '/includes/storefront-discovery-nav.php'; ?>
  <main class="storefront-page">
    <section class="category-catalog-hero" aria-labelledby="categoryHeroTitle">
      <div class="category-catalog-hero-copy">
        <span class="catalog-kicker">Catálogo SmartISP</span>
        <h1 id="categoryHeroTitle">Categorías de <span>productos</span></h1>
        <p>Encuentra tecnología, conectividad y soluciones para tu hogar o empresa.</p>
        <div class="catalog-trust-row">
          <span><i data-lucide="shield-check"></i> Productos originales</span>
          <span><i data-lucide="truck"></i> Envíos a todo Ecuador</span>
          <span><i data-lucide="headphones"></i> Asesoría especializada</span>
        </div>
      </div>
      <div class="category-hero-products" id="categoryHeroProducts" aria-label="Productos del catálogo">
        <?php foreach (array_slice($featuredCategories, 0, 3) as $category): ?>
          <?php $heroImage = categoryHubImageUrl($category); if ($heroImage !== ''): ?>
            <div class="hero-category-product"><img src="<?= escCategoryHub($heroImage) ?>" alt="<?= escCategoryHub((string)($category['category_banner_alt'] ?? 'Producto de ' . $category['name'])) ?>" width="420" height="260" loading="lazy"></div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="category-family-section" aria-label="Explorar familias">
      <div id="categoryFamilyRail" class="category-family-rail" tabindex="0"></div>
    </section>

    <section class="category-catalog-layout" id="catalogo">
      <aside class="catalog-sidebar" aria-label="Filtros del catálogo">
        <div class="catalog-sidebar-head"><strong><i data-lucide="layout-grid"></i> Categorías</strong><button type="button" id="clearCategoryFilters">Ver todas</button></div>
        <label class="catalog-category-search"><i data-lucide="search"></i><input id="categorySearch" type="search" placeholder="Buscar categoría..." autocomplete="off"></label>
        <button class="catalog-all-categories is-active" id="showAllCategories" type="button"><i data-lucide="layers"></i><span>Todo el catálogo</span><b id="catalogProductTotal">—</b></button>
        <div id="categoryFilterList" class="catalog-category-list"><div class="catalog-loading">Cargando categorías…</div></div>
        <div class="catalog-price-filter">
          <h2>Precio</h2>
          <div class="price-inputs"><label>Desde<input id="minPrice" type="number" min="0" step="0.01" inputmode="decimal" placeholder="$0"></label><label>Hasta<input id="maxPrice" type="number" min="0" step="0.01" inputmode="decimal" placeholder="Sin límite"></label></div>
          <button id="applyPriceFilter" class="price-apply" type="button">Aplicar precio</button>
        </div>
      </aside>

      <div class="category-products-main">
        <div class="category-products-toolbar">
          <div><p id="categoryResultCount" aria-live="polite">Cargando productos…</p><span id="activeCategoryLabel" hidden></span></div>
          <div class="category-products-controls">
            <label class="product-search-control"><i data-lucide="search"></i><input id="productSearch" type="search" placeholder="Buscar producto..." aria-label="Buscar producto"></label>
            <div class="view-switch" role="group" aria-label="Presentación de productos"><button id="gridViewButton" class="is-active" type="button" aria-label="Vista de cuadrícula" aria-pressed="true"><i data-lucide="layout-grid"></i></button><button id="listViewButton" type="button" aria-label="Vista de lista" aria-pressed="false"><i data-lucide="list"></i></button></div>
            <label class="sort-control"><span>Ordenar</span><select id="categorySort"><option value="relevance">Más relevantes</option><option value="low">Menor precio</option><option value="high">Mayor precio</option></select></label>
          </div>
        </div>
        <div id="categoryProductsGrid" class="category-products-grid" aria-live="polite"><div class="catalog-loading">Cargando productos del catálogo…</div></div>
        <nav id="categoryPagination" class="category-pagination" aria-label="Paginación de productos"></nav>
      </div>
    </section>
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
        <div class="site-footer-contact" id="contacto">
          <a href="#" id="footerWhatsappLink" hidden><svg viewBox="0 0 24 24" width="17" fill="currentColor"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zm0 18.15c-1.48 0-2.93-.4-4.2-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.26 8.26 0 01-1.27-4.38c0-4.54 3.7-8.24 8.24-8.24 2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 012.41 5.83c.01 4.54-3.7 8.23-8.21 8.23zm4.52-6.16c-.25-.12-1.47-.72-1.7-.81-.23-.08-.39-.12-.56.13-.17.25-.64.81-.78.97-.14.17-.29.19-.54.06-.25-.12-1.04-.38-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.12-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.42-.14-.01-.31-.01-.48-.01-.17 0-.43.06-.66.31-.23.25-.86.84-.86 2.05s.88 2.38 1 2.55c.12.17 1.74 2.65 4.21 3.72.59.25 1.05.4 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.14-1.18-.06-.1-.23-.16-.48-.28z"/></svg><span><strong id="footerWhatsappText"></strong><small>Escríbenos por WhatsApp</small></span></a>
          <a href="#" id="footerEmailLink" hidden><i data-lucide="mail" width="17"></i><span><strong id="footerEmailText"></strong><small>Respuesta rápida</small></span></a>
          <div class="site-footer-contact-item" id="footerAddressItem" hidden><i data-lucide="map-pin" width="17"></i><span><strong id="footerAddressText"></strong></span></div>
        </div>
      </div>
      <nav class="site-footer-col" aria-label="Comprar">
        <h4>Comprar</h4>
        <a href="/categorias-destacadas">Catálogo</a>
        <a href="/categorias-destacadas">Categorías</a>
        <a href="/productos-destacados">Productos destacados</a>
        <a href="/servicios">Servicios</a>
      </nav>
      <nav class="site-footer-col" aria-label="Atención al cliente">
        <h4>Atención al cliente</h4>
        <a href="#contacto">Contacto y WhatsApp</a>
        <a href="/envios">Envíos y pagos</a>
        <a href="/devoluciones">Devoluciones</a>
        <a href="/garantias">Garantías</a>
      </nav>
      <nav class="site-footer-col" aria-label="Información legal">
        <h4>Información legal</h4>
        <a href="/terminos">Términos y condiciones</a>
        <a href="/privacidad">Política de privacidad y protección de datos</a>
      </nav>
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
        <span class="payment-badge" role="img" aria-label="Visa"><svg viewBox="0 0 48 30" width="40" height="26" aria-hidden="true"><rect width="48" height="30" fill="#fff"/><text x="24" y="20" font-family="Arial, Helvetica, sans-serif" font-size="13" font-weight="900" font-style="italic" fill="#1434CB" text-anchor="middle">VISA</text></svg></span>
        <span class="payment-badge" role="img" aria-label="Mastercard"><svg viewBox="0 0 48 30" width="40" height="26" aria-hidden="true"><rect width="48" height="30" fill="#fff"/><circle cx="20" cy="15" r="9" fill="#EB001B"/><circle cx="28" cy="15" r="9" fill="#F79E1B" fill-opacity=".92"/></svg></span>
        <span class="payment-badge" role="img" aria-label="American Express"><svg viewBox="0 0 48 30" width="40" height="26" aria-hidden="true"><rect width="48" height="30" fill="#006FCF"/><text x="24" y="19" font-family="Arial, Helvetica, sans-serif" font-size="9.5" font-weight="800" fill="#fff" text-anchor="middle">AMEX</text></svg></span>
        <span class="payment-badge" role="img" aria-label="Diners Club"><svg viewBox="0 0 48 30" width="40" height="26" aria-hidden="true"><rect width="48" height="30" fill="#fff"/><circle cx="24" cy="15" r="10" fill="#0079BE"/><path d="M24 7a8 8 0 0 0 0 16z" fill="#fff"/></svg></span>
        <span class="payment-badge" role="img" aria-label="Banco Pichincha"><svg viewBox="0 0 48 30" width="40" height="26" aria-hidden="true"><rect width="48" height="30" fill="#FFCB05"/><text x="24" y="18" font-family="Arial, Helvetica, sans-serif" font-size="7" font-weight="900" fill="#0A3D62" text-anchor="middle">PICHINCHA</text></svg></span>
        <span class="payment-badge" role="img" aria-label="PayPhone"><svg viewBox="0 0 48 30" width="40" height="26" aria-hidden="true"><rect width="48" height="30" fill="#00B4A6"/><text x="24" y="19" font-family="Arial, Helvetica, sans-serif" font-size="8" font-weight="800" fill="#fff" text-anchor="middle">PayPhone</text></svg></span>
      </div>
    </div>
    <div class="site-footer-bottom">
      <span id="footerCopyright">© SMART-ISP. Todos los derechos reservados.</span>
      <div><a href="/nosotros">Nosotros</a><a href="/terminos">Términos y condiciones</a><a href="/privacidad">Política de privacidad</a><a href="/garantias">Garantías</a></div>
    </div>
  </footer>
  <link rel="stylesheet" href="/assets/css/store/site-footer.css?v=pie-20261105">
  <script src="/assets/js/store/site-footer.js?v=iva-20261027" defer></script>
  <script src="/assets/js/store/storefront-discovery.js?v=familias-20261101" defer></script>
  <script src="/assets/js/store/public-account.js?v=cuenta-pagina-20261030" defer></script>
  <script src="/assets/js/store/category-catalog.js?v=subcat-20261028" defer></script>
</body>
</html>
