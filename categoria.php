<?php
/**
 * SmartISP - Pagina publica indexable de categoria.
 *
 * Genera contenido HTML sin depender de JavaScript para que buscadores puedan
 * descubrir familias de productos y seguir enlaces canonicos a fichas.
 */

require_once __DIR__ . '/api/db.php';

function slugifyCategoryPage(string $text): string {
    // iconv('UTF-8','ASCII//TRANSLIT', ...) es inconsistente entre builds/locales de PHP (en el
    // hosting real deja apostrofes en vez de quitar el acento limpio, p. ej. "inform-aticos" en
    // vez de "informaticos"), asi que los acentos del espanol se reemplazan primero a mano.
    $accentMap = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U'];
    $clean = strtr($text, $accentMap);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $clean);
    if ($ascii !== false) $clean = $ascii;
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
$categoryParentName = '';
$products = [];
$allCategoryNames = [];
$audioVideoSubcategories = [];
$selectedSubcategory = '';
$selectedPriceMin = trim((string)($_GET['precio_min'] ?? ''));
$selectedPriceMax = trim((string)($_GET['precio_max'] ?? ''));
$filterInStock = ($_GET['stock'] ?? '') === '1';
$allowedSortOrders = ['relevancia', 'precio_asc', 'precio_desc', 'nombre'];
$sortOrder = in_array((string)($_GET['orden'] ?? ''), $allowedSortOrders, true) ? (string)$_GET['orden'] : 'relevancia';
$hasInventoryTable = false;
$totalProducts = 0;
$allProductCount = 0;
$categoryBannerUrl = '';
$categoryBannerAlt = '';
$categoryIcon = 'package';
$customCategoryDescription = '';
$pageSize = 24;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalPages = 1;

if ($pdo && $slug !== '') {
    try {
        $pTable = getProductsTableName($pdo);
        ensureProductTableColumns($pdo, $pTable);
        $hasInventoryTable = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'product_inventory'")->fetchColumn() > 0;
        $stmt = $pdo->query("
            SELECT TRIM(category) AS category, TRIM(subcategory) AS subcategory, COUNT(*) AS product_count
            FROM `$pTable`
            WHERE visible = 1 AND TRIM(category) <> ''
            GROUP BY TRIM(category), TRIM(subcategory)
            ORDER BY TRIM(category) ASC, TRIM(subcategory) ASC
        ");

        $categoryMatches = [];
        $subcategoryMatches = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $parent = trim((string)($row['category'] ?? ''));
            $name = trim((string)($row['subcategory'] ?? ''));
            if ($parent !== '') {
                $allCategoryNames[$parent] = true;
                if (slugifyCategoryPage($parent) === $slug) {
                    $categoryMatches[$parent] = ($categoryMatches[$parent] ?? 0) + (int)$row['product_count'];
                }
            }
            if ($name !== '') {
                $allCategoryNames[$name] = true;
                if (slugifyCategoryPage($name) === $slug) {
                    $subcategoryMatches[$name][$parent] = ($subcategoryMatches[$name][$parent] ?? 0) + (int)$row['product_count'];
                }
            }
        }

        if ($categoryMatches) {
            arsort($categoryMatches);
            $categoryName = (string)array_key_first($categoryMatches);
        } elseif ($subcategoryMatches) {
            $categoryName = (string)array_key_first($subcategoryMatches);
            $parents = $subcategoryMatches[$categoryName];
            unset($parents['']);
            if ($parents) {
                arsort($parents);
                $categoryParentName = (string)array_key_first($parents);
            }
        }

        if ($categoryName !== '') {
            $facetStmt = $pdo->prepare("SELECT TRIM(subcategory) AS name, COUNT(*) AS product_count FROM `$pTable` WHERE visible = 1 AND (TRIM(category) = :category_name OR TRIM(subcategory) = :subcategory_name) AND TRIM(subcategory) <> '' GROUP BY TRIM(subcategory) ORDER BY TRIM(subcategory) ASC");
            $facetStmt->execute([':category_name' => $categoryName, ':subcategory_name' => $categoryName]);
            $audioVideoSubcategories = $facetStmt->fetchAll(PDO::FETCH_ASSOC);

            // Unificar con las subcategorias curadas en admin (categories_rows): tienda y
            // admin deben mostrar la misma lista. getDynamicCategoriesList() ya fusiona las
            // subcategorias reales de productos con las agregadas a mano en el panel.
            $knownSubNames = [];
            foreach ($audioVideoSubcategories as $facet) {
                $knownSubNames[mb_strtolower((string)$facet['name'])] = true;
            }
            $catalogCategories = getDynamicCategoriesList($pdo, true);
            foreach ($catalogCategories as $catEntry) {
                if (strcasecmp((string)($catEntry['name'] ?? ''), $categoryName) !== 0) continue;
                foreach ((array)($catEntry['subcategories'] ?? []) as $curatedSub) {
                    $curatedSub = trim((string)$curatedSub);
                    if ($curatedSub === '' || isset($knownSubNames[mb_strtolower($curatedSub)])) continue;
                    $audioVideoSubcategories[] = ['name' => $curatedSub, 'product_count' => 0];
                    $knownSubNames[mb_strtolower($curatedSub)] = true;
                }
                break;
            }
            usort($audioVideoSubcategories, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));

            $requestedSubcategory = trim((string)($_GET['subcategoria'] ?? ''));
            foreach ($audioVideoSubcategories as $facet) {
                if ((string)$facet['name'] === $requestedSubcategory) {
                    $selectedSubcategory = $requestedSubcategory;
                    break;
                }
            }

            $where = 'visible = 1 AND (TRIM(category) = :category_name OR TRIM(subcategory) = :subcategory_name)';
            $params = [':category_name' => $categoryName, ':subcategory_name' => $categoryName];
            $allCountStmt = $pdo->prepare("SELECT COUNT(*) FROM `$pTable` WHERE $where");
            $allCountStmt->execute($params);
            $allProductCount = (int)$allCountStmt->fetchColumn();
            if ($selectedSubcategory !== '') {
                $where .= ' AND TRIM(subcategory) = :filter_subcategory';
                $params[':filter_subcategory'] = $selectedSubcategory;
            }
            if ($selectedPriceMin !== '' && is_numeric($selectedPriceMin) && (float)$selectedPriceMin >= 0) {
                $where .= ' AND price >= :price_min';
                $params[':price_min'] = (float)$selectedPriceMin;
            }
            if ($selectedPriceMax !== '' && is_numeric($selectedPriceMax) && (float)$selectedPriceMax >= 0) {
                $where .= ' AND price <= :price_max';
                $params[':price_max'] = (float)$selectedPriceMax;
            }
            if ($filterInStock && $hasInventoryTable) $where .= ' AND EXISTS (SELECT 1 FROM product_inventory pi WHERE pi.product_id = id AND pi.quantity_available > pi.quantity_reserved)';
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `$pTable` WHERE $where");
            $countStmt->execute($params);
            $totalProducts = (int)$countStmt->fetchColumn();
            $totalPages = max(1, (int)ceil($totalProducts / $pageSize));
            $currentPage = min($currentPage, $totalPages);
            $offset = ($currentPage - 1) * $pageSize;

            $orderBySql = 'updated_at DESC, name ASC';
            if ($sortOrder === 'precio_asc') $orderBySql = '(price <= 0) ASC, price ASC, name ASC';
            elseif ($sortOrder === 'precio_desc') $orderBySql = '(price <= 0) ASC, price DESC, name ASC';
            elseif ($sortOrder === 'nombre') $orderBySql = 'name ASC';
            $stmtProducts = $pdo->prepare("
                SELECT p.*" . ($hasInventoryTable ? ", (SELECT GREATEST(quantity_available - quantity_reserved, 0) FROM product_inventory WHERE product_id = p.id LIMIT 1) AS available_qty" : ", NULL AS available_qty") . "
                FROM `$pTable` p
                WHERE $where
                ORDER BY $orderBySql
                LIMIT $pageSize OFFSET $offset
            ");
            $stmtProducts->execute($params);
            while ($row = $stmtProducts->fetch(PDO::FETCH_ASSOC)) {
                $product = normalizeProductRow($row);
                $product['available_qty'] = $row['available_qty'] === null ? null : (int)$row['available_qty'];
                $products[] = $product;
            }

            $configName = $categoryParentName !== '' ? $categoryParentName : $categoryName;
            $configStmt = $pdo->prepare('SELECT banner_image_url, banner_alt, description, icon FROM categories_rows WHERE LOWER(name) = LOWER(:name) LIMIT 1');
            $configStmt->execute([':name' => $configName]);
            $categoryConfig = $configStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $categoryBannerUrl = trim((string)($categoryConfig['banner_image_url'] ?? ''));
            $categoryBannerAlt = trim((string)($categoryConfig['banner_alt'] ?? ''));
            $customCategoryDescription = trim((string)($categoryConfig['description'] ?? ''));
            $configuredIcon = trim((string)($categoryConfig['icon'] ?? ''));
            $knownCategoryIcons = ['laptop','network','cable','server','shield-check','zap','monitor','hard-drive','headphones','printer','phone-call','file-code','package','cpu','wifi','camera'];
            if (in_array($configuredIcon, $knownCategoryIcons, true)) $categoryIcon = $configuredIcon;
            $safeLocalBanner = str_starts_with($categoryBannerUrl, '/') && !str_starts_with($categoryBannerUrl, '//') && !str_contains($categoryBannerUrl, '..') && !str_contains($categoryBannerUrl, '\\');
            $safeRemoteBanner = filter_var($categoryBannerUrl, FILTER_VALIDATE_URL) !== false && strtolower((string)parse_url($categoryBannerUrl, PHP_URL_SCHEME)) === 'https';
            if ((!$safeLocalBanner && !$safeRemoteBanner) || preg_match('/[\\x00-\\x1F\\x7F]/', $categoryBannerUrl)) $categoryBannerUrl = '';
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
    ? ($customCategoryDescription !== '' ? $customCategoryDescription : (slugifyCategoryPage($categoryName) === slugifyCategoryPage('Audio y Video')
        ? 'Encuentra equipos de audio y video para entretenimiento, reuniones y creación de contenido.'
        : 'Explora ' . $categoryName . ' y compara opciones del catálogo para encontrar lo que necesitas.'))
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
  <script src="/assets/js/public-brand.js?v=canonical-logo-layout-20261009" defer></script>
  <link rel="stylesheet" href="/assets/css/storefront-discovery.css?v=public-admin-access-20261009">
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
    .category-breadcrumb{display:flex;flex-wrap:wrap;align-items:center;gap:7px;margin:22px 0 12px;color:#60798f;font-size:13px}
    .category-breadcrumb a{color:#087ea4}.category-breadcrumb a:hover{text-decoration:underline}
    .category-breadcrumb [aria-current=page]{overflow-wrap:anywhere;color:#17324d;font-weight:700}
    .category-breadcrumb-separator{color:#91a6b5}
    .category-hero{display:grid;grid-template-columns:minmax(0,1fr);align-items:center;gap:clamp(18px,3vw,36px);min-height:0;margin:0 0 18px;padding:clamp(16px,2.2vw,26px);border:1px solid #c5d7e0;border-left:4px solid #087ea4;border-radius:8px;background:linear-gradient(115deg,#edf8fd 0%,#dff2fb 54%,#c9e9f8 100%);overflow:hidden}
    .category-hero.has-banner{grid-template-columns:minmax(0,1.15fr) minmax(220px,.85fr)}
    .category-hero-copy{flex:1;min-width:0}
    .category-banner{display:block;width:100%;min-width:0;max-height:200px;aspect-ratio:2.6;object-fit:cover;object-position:center;border:1px solid rgba(255,255,255,.8);border-radius:8px;background:#fff;box-shadow:0 14px 30px rgba(16,44,61,.12)}
    .category-eyebrow{color:#087ea4;font-size:11px;font-weight:850;text-transform:uppercase}
    .category-hero h1{margin:5px 0 7px;color:#102c3d;font-size:clamp(24px,2.6vw,32px);line-height:1.1;letter-spacing:0}
    .category-hero p{max-width:760px;margin:0;color:#60798f;font-size:15px}
    .category-chips-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
    .category-chips-row span{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border:1px solid rgba(8,126,164,.18);border-radius:8px;background:rgba(255,255,255,.8);color:#17324d;font-size:12px;font-weight:750;white-space:nowrap}
    .category-chips-row span i{color:#087ea4;flex-shrink:0}
    @media(max-width:520px){.category-chips-row span{font-size:11px;padding:7px 10px}}
    .category-results-layout{display:grid;grid-template-columns:260px minmax(0,1fr);gap:18px;align-items:start}
    .category-sidebar{position:sticky;top:16px;padding:16px;border:1px solid #c5d7e0;border-radius:8px;background:#fff}
    .category-sidebar-title{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 4px}
    .category-sidebar-title strong{color:#102c3d;font-size:15px}
    .category-active-badge{padding:3px 9px;border-radius:99px;background:#e8f5fa;color:#087ea4;font-size:10px;font-weight:850;white-space:nowrap}
    .category-sidebar-subtitle{margin:0 0 12px;color:#60798f;font-size:12px;line-height:1.5}
    .category-sidebar-search{display:flex;align-items:center;gap:7px;margin-bottom:14px;padding:8px 10px;border:1px solid #c5d7e0;border-radius:6px;background:#f5f9fb}
    .category-sidebar-search input{flex:1;min-width:0;border:0;background:transparent;outline:0;font-size:12px;color:#17324d}
    .category-sidebar-search i{color:#60798f;flex:0 0 auto}
    .category-sidebar details{border-top:1px solid #d8e5e7}
    .category-sidebar details:first-of-type{border-top:0}
    .category-sidebar summary{display:flex;align-items:center;justify-content:space-between;padding:12px 2px;color:#102c3d;font-size:13px;font-weight:800;cursor:pointer;list-style:none}
    .category-sidebar summary::-webkit-details-marker{display:none}
    .category-sidebar summary::after{content:"";width:8px;height:8px;border-right:2px solid #60798f;border-bottom:2px solid #60798f;transform:rotate(45deg);transition:transform .15s ease}
    .category-sidebar details[open] summary::after{transform:rotate(-135deg)}
    .category-subcategory-list{display:grid;max-height:320px;overflow:auto;margin-bottom:8px}
    .category-subcategory-list a{display:flex;justify-content:space-between;gap:8px;padding:9px 2px;border-bottom:1px solid #eef2f4;color:#17324d;font-size:12px;font-weight:700}
    .category-subcategory-list a[aria-current=true]{color:#087ea4}
    .category-subcategory-list small{color:#60798f}
    .category-filter-form{display:grid;gap:10px;padding-bottom:4px}
    .category-filter-form label{display:grid;gap:4px;color:#17324d;font-size:12px;font-weight:750}
    .category-filter-form input[type=number]{width:100%;min-width:0;padding:9px;border:1px solid #bdced8;border-radius:5px}
    .category-price-row{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .category-filter-form .category-stock{display:flex;align-items:center;gap:8px;font-weight:650}
    .category-filter-form .category-stock input{width:auto;margin:0}
    .category-filter-actions{display:flex;gap:8px;align-items:center;margin-top:4px}
    .category-filter-actions button,.category-filter-actions a{min-height:36px;padding:8px 10px;border:1px solid #b7cbd6;border-radius:5px;background:#fff;color:#123247;font-size:12px;font-weight:800}
    .category-filter-actions button{border-color:#087ea4;background:#087ea4;color:white}
    .category-results-main{min-width:0}
    .category-list-heading{display:flex;align-items:end;justify-content:space-between;gap:14px;margin-top:0;flex-wrap:wrap}
    .category-list-heading h2{margin:0;color:#102c3d;font-size:22px}
    .category-list-heading p{margin:3px 0 0;color:#60798f;font-size:13px}
    .category-sort{display:flex;align-items:center;gap:7px;font-size:12px;color:#17324d;font-weight:700}
    .category-sort select{padding:8px 10px;border:1px solid #bdced8;border-radius:6px;background:#fff;color:#17324d;font-size:12px;font-weight:750}
    .category-filters{display:flex;flex-wrap:wrap;gap:8px;margin:15px 0 4px}
    .category-filters a{display:inline-flex;align-items:center;gap:8px;padding:8px 11px;border:1px solid #d8e5e7;border-radius:6px;background:#fff;color:#17324d;font-size:12px;font-weight:750}
    .category-filters a:hover,.category-filters a[aria-current=true]{border-color:#64bad3;background:#e8f5fa;color:#087ea4}
    .category-filters small{color:#60798f;font-size:11px}
    .category-pagination{display:flex;justify-content:center;align-items:center;gap:14px;margin:8px 0 30px;color:#60798f;font-size:13px}
    .category-pagination a{padding:8px 12px;border:1px solid #d8e5e7;border-radius:6px;background:#fff;color:#087ea4;font-weight:750}
    .category-pagination a:focus-visible,.category-filters a:focus-visible,.category-breadcrumb a:focus-visible{outline:3px solid #71c6df;outline-offset:2px}
    .category-pagination span{min-width:96px;text-align:center}
    .category-empty{padding:22px;border:1px solid #d8e5e7;border-radius:8px;background:#fff}
    .category-empty a{color:#087ea4;font-weight:800}
    .category-chips{display:flex;flex-wrap:wrap;gap:8px;margin:18px 0 34px}
    .category-chips a{padding:7px 11px;border:1px solid #d8e5e7;border-radius:6px;background:#fff;color:#102c3d;font-size:12px;font-weight:750}
    .category-chips a:hover{border-color:#087ea4;color:#087ea4}
    @media(max-width:900px){.category-results-layout{grid-template-columns:220px minmax(0,1fr);gap:12px}}
    @media(max-width:760px){.category-breadcrumb{margin-top:16px;font-size:12px}.category-hero,.category-hero.has-banner{grid-template-columns:1fr;gap:14px;min-height:0;padding:16px}.category-hero h1{font-size:26px}.category-banner{aspect-ratio:2.4;max-height:260px;object-fit:contain;background:#fff}.category-results-layout{grid-template-columns:1fr}.category-sidebar{position:static}.category-subcategory-list{max-height:210px}.category-list-heading{align-items:flex-start;flex-direction:column}.category-pagination{gap:8px}.category-pagination a{padding:8px}}
  </style>
</head>
<body>
  <?php $storefrontActivePage = 'categories'; require __DIR__ . '/includes/storefront-discovery-nav.php'; ?>
  <main class="storefront-page">
    <nav class="category-breadcrumb" aria-label="Ruta de navegación">
      <a href="/">Inicio</a><span class="category-breadcrumb-separator" aria-hidden="true">›</span>
      <a href="/tienda.html">Tienda</a>
      <?php if ($categoryParentName !== ''): ?><span class="category-breadcrumb-separator" aria-hidden="true">›</span><a href="/categoria/<?= rawurlencode(slugifyCategoryPage($categoryParentName)) ?>/"><?= escapeCategoryPage($categoryParentName) ?></a><?php endif; ?>
      <span class="category-breadcrumb-separator" aria-hidden="true">›</span><span aria-current="page"><?= escapeCategoryPage($categoryName !== '' ? $categoryName : 'Categoría no encontrada') ?></span>
    </nav>
    <section class="category-hero<?= $categoryBannerUrl !== '' ? ' has-banner' : '' ?>">
      <div class="category-hero-copy"><div class="category-eyebrow"><?= $categoryParentName !== '' ? escapeCategoryPage($categoryParentName) : 'Catálogo de productos' ?></div>
      <h1><?= escapeCategoryPage($categoryName !== '' ? $categoryName : 'Categoría no encontrada') ?></h1>
      <p><?= escapeCategoryPage($categoryName !== '' ? $metaDesc : 'Vuelve al catálogo para explorar los productos disponibles.') ?></p>
      <?php if ($categoryName !== '' && $totalProducts > 0): ?>
        <div class="category-chips-row">
          <span><i data-lucide="box" width="14"></i> <?= number_format($totalProducts, 0, ',', '.') ?> productos disponibles</span>
          <span><i data-lucide="truck" width="14"></i> Envío gratis en Guayas</span>
          <span><i data-lucide="headphones" width="14"></i> Atención experta</span>
        </div>
      <?php endif; ?></div>
      <?php if ($categoryName !== '' && $categoryBannerUrl !== ''): ?><img class="category-banner" src="<?= escapeCategoryPage($categoryBannerUrl) ?>" alt="<?= escapeCategoryPage($categoryBannerAlt !== '' ? $categoryBannerAlt : $categoryName) ?>" width="900" height="440" fetchpriority="high" onerror="this.hidden=true;this.parentElement.classList.remove('has-banner')"><?php endif; ?>
    </section>

    <?php if ($categoryName !== '' && $totalProducts > 0): ?>
      <?php $categoryPath = '/categoria/' . rawurlencode(slugifyCategoryPage($categoryName)) . '/'; ?>
      <div class="category-results-layout">
        <aside class="category-sidebar" aria-label="Filtros de productos">
          <div class="category-sidebar-title"><strong><?= escapeCategoryPage($categoryName) ?></strong><span class="category-active-badge">Categoría activa</span></div>
          <p class="category-sidebar-subtitle">Filtra los resultados por subcategorías y características de esta categoría.</p>
          <label class="category-sidebar-search"><i data-lucide="search" width="14"></i><input type="search" id="categorySidebarSearch" placeholder="Buscar en <?= strtolower(escapeCategoryPage($categoryName)) ?>..." aria-label="Buscar subcategoría"></label>

          <details open>
            <summary>Subcategorías</summary>
            <nav class="category-subcategory-list" id="categorySubcategoryList" aria-label="Subcategorías">
              <a href="<?= $categoryPath ?>" data-subcat-name="todos los productos"<?= $selectedSubcategory === '' ? ' aria-current="true"' : '' ?>><span>Todos los productos</span><small><?= number_format($allProductCount, 0, ',', '.') ?></small></a>
              <?php foreach ($audioVideoSubcategories as $facet): ?>
                <a href="<?= $categoryPath . '?' . http_build_query(['subcategoria' => (string)$facet['name']]) ?>" data-subcat-name="<?= strtolower(escapeCategoryPage((string)$facet['name'])) ?>"<?= $selectedSubcategory === (string)$facet['name'] ? ' aria-current="true"' : '' ?>><span><?= escapeCategoryPage((string)$facet['name']) ?></span><small><?= number_format((int)$facet['product_count'], 0, ',', '.') ?></small></a>
              <?php endforeach; ?>
            </nav>
          </details>

          <form method="get" action="<?= $categoryPath ?>">
            <?php if ($selectedSubcategory !== ''): ?><input type="hidden" name="subcategoria" value="<?= escapeCategoryPage($selectedSubcategory) ?>"><?php endif; ?>
            <?php if ($sortOrder !== 'relevancia'): ?><input type="hidden" name="orden" value="<?= escapeCategoryPage($sortOrder) ?>"><?php endif; ?>
            <details open>
              <summary>Precio (USD)</summary>
              <div class="category-filter-form">
                <div class="category-price-row"><label>Desde<input type="number" min="0" step="0.01" name="precio_min" value="<?= escapeCategoryPage($selectedPriceMin) ?>" placeholder="0"></label><label>Hasta<input type="number" min="0" step="0.01" name="precio_max" value="<?= escapeCategoryPage($selectedPriceMax) ?>" placeholder="Sin límite"></label></div>
              </div>
            </details>
            <?php if ($hasInventoryTable): ?>
            <details open>
              <summary>Stock</summary>
              <div class="category-filter-form"><label class="category-stock"><input type="checkbox" name="stock" value="1" <?= $filterInStock ? 'checked' : '' ?>> Solo disponibles</label></div>
            </details>
            <?php endif; ?>
            <div class="category-filter-actions"><button type="submit">Aplicar</button><a href="<?= $categoryPath ?>">Limpiar</a></div>
          </form>
        </aside>
        <div class="category-results-main">
          <div class="category-list-heading">
            <div><h2>Productos de <?= escapeCategoryPage($categoryName) ?></h2><p><?= number_format($totalProducts, 0, ',', '.') ?> resultados<?= $selectedSubcategory !== '' ? ' · ' . escapeCategoryPage($selectedSubcategory) : '' ?></p></div>
            <form class="category-sort" method="get" action="<?= $categoryPath ?>" id="categorySortForm">
              <?php if ($selectedSubcategory !== ''): ?><input type="hidden" name="subcategoria" value="<?= escapeCategoryPage($selectedSubcategory) ?>"><?php endif; ?>
              <?php if ($selectedPriceMin !== ''): ?><input type="hidden" name="precio_min" value="<?= escapeCategoryPage($selectedPriceMin) ?>"><?php endif; ?>
              <?php if ($selectedPriceMax !== ''): ?><input type="hidden" name="precio_max" value="<?= escapeCategoryPage($selectedPriceMax) ?>"><?php endif; ?>
              <?php if ($filterInStock): ?><input type="hidden" name="stock" value="1"><?php endif; ?>
              <label for="categorySortSelect">Ordenar por</label>
              <select id="categorySortSelect" name="orden" onchange="this.form.submit()">
                <option value="relevancia"<?= $sortOrder === 'relevancia' ? ' selected' : '' ?>>Más relevantes</option>
                <option value="precio_asc"<?= $sortOrder === 'precio_asc' ? ' selected' : '' ?>>Menor precio</option>
                <option value="precio_desc"<?= $sortOrder === 'precio_desc' ? ' selected' : '' ?>>Mayor precio</option>
                <option value="nombre"<?= $sortOrder === 'nombre' ? ' selected' : '' ?>>Nombre A-Z</option>
              </select>
            </form>
          </div>
          <section class="store-product-grid" aria-label="Productos de <?= escapeCategoryPage($categoryName) ?>">
            <?php foreach ($products as $index => $product): ?>
              <?php $storeProduct = $product; $storeProductUrl = '/producto/' . productSlugForCategoryPage($product); $storeProductIndex = $index; require __DIR__ . '/includes/storefront-product-card.php'; ?>
            <?php endforeach; ?>
          </section>
        </div>
      </div>
      <?php if ($totalPages > 1): ?>
        <?php
          $previousQuery = array_filter(['subcategoria' => $selectedSubcategory, 'precio_min' => $selectedPriceMin, 'precio_max' => $selectedPriceMax, 'stock' => $filterInStock ? '1' : '', 'orden' => $sortOrder !== 'relevancia' ? $sortOrder : '', 'page' => $currentPage > 2 ? $currentPage - 1 : null], fn($value) => $value !== null && $value !== '');
          $nextQuery = array_filter(['subcategoria' => $selectedSubcategory, 'precio_min' => $selectedPriceMin, 'precio_max' => $selectedPriceMax, 'stock' => $filterInStock ? '1' : '', 'orden' => $sortOrder !== 'relevancia' ? $sortOrder : '', 'page' => $currentPage + 1], fn($value) => $value !== null && $value !== '');
        ?>
        <nav class="category-pagination" aria-label="Paginación de productos">
          <?php if ($currentPage > 1): ?><a href="<?= $categoryPath . ($previousQuery ? '?' . http_build_query($previousQuery) : '') ?>">Anterior</a><?php endif; ?>
          <span>Página <?= $currentPage ?> de <?= $totalPages ?></span>
          <?php if ($currentPage < $totalPages): ?><a href="<?= $categoryPath . '?' . http_build_query($nextQuery) ?>">Siguiente</a><?php endif; ?>
        </nav>
      <?php endif; ?>
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
  <script src="/assets/js/storefront-discovery.js?v=mobile-category-drawer-20261008" defer></script>
  <script src="/assets/js/storefront-product-card.js" defer></script>
  <?php if ($categoryName !== '' && $totalProducts > 0): ?>
  <script>
    (() => {
      const input = document.querySelector('#categorySidebarSearch');
      const list = document.querySelector('#categorySubcategoryList');
      if (!input || !list) return;
      const links = Array.from(list.querySelectorAll('a[data-subcat-name]'));
      input.addEventListener('input', () => {
        const q = input.value.trim().toLowerCase();
        links.forEach(a => { a.hidden = q !== '' && !a.dataset.subcatName.includes(q); });
      });
    })();
  </script>
  <?php endif; ?>
</body>
</html>
