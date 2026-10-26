<?php
/**
 * SmartISP - Pagina publica indexable de categoria.
 *
 * Genera contenido HTML sin depender de JavaScript para que buscadores puedan
 * descubrir familias de productos y seguir enlaces canonicos a fichas.
 */

require_once __DIR__ . '/api/lib/db.php';

function slugifyCategoryPage(string $text): string {
    return smartispSlug($text, 90, 'categoria');
}

function productSlugForCategoryPage(array $product): string {
    $id = (string)($product['id'] ?? ($product['sku'] ?? ''));
    $name = (string)($product['name'] ?? 'articulo');
    return rawurlencode($id) . '-' . smartispSlug($name, 80, 'articulo');
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
        $hasInventoryTable = smartispTableExists($pdo, 'product_inventory');
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

        // Slugs del sitemap anterior (iconv): 301 a la URL actual.
        if (!$categoryMatches && !$subcategoryMatches && !headers_sent()) {
            foreach (array_keys($allCategoryNames) as $knownName) {
                $legacy = @iconv('UTF-8', 'ASCII//TRANSLIT', (string)$knownName) ?: (string)$knownName;
                $legacy = substr(strtolower(preg_replace('~-+~', '-', trim(preg_replace('~[^\pL\d]+~u', '-', $legacy), '-'))), 0, 90);
                if ($legacy !== '' && $legacy === $slug && slugifyCategoryPage((string)$knownName) !== $slug) {
                    header('Location: /categoria/' . rawurlencode(slugifyCategoryPage((string)$knownName)) . '/', true, 301);
                    exit;
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
                SELECT p.*" . ($hasInventoryTable ? ", (SELECT GREATEST(CAST(quantity_available AS SIGNED) - CAST(quantity_reserved AS SIGNED), 0) FROM product_inventory WHERE product_id = p.id LIMIT 1) AS available_qty" : ", NULL AS available_qty") . "
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
// El canonical siempre apunta a la categoria "limpia" (sin filtros, pagina 1): paginas 2+ y
// vistas filtradas/ordenadas serian contenido casi duplicado si se indexaran por separado, asi
// que se marcan noindex (pero follow, para no cortar el rastreo de los productos que enlazan).
$hasActiveFilters = $selectedSubcategory !== '' || $selectedPriceMin !== '' || $selectedPriceMax !== '' || $filterInStock || $sortOrder !== 'relevancia';
$robots = $categoryName !== ''
    ? (($currentPage <= 1 && !$hasActiveFilters) ? 'index, follow, max-image-preview:large' : 'noindex, follow')
    : 'noindex, follow';
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
  <script src="/assets/js/store/public-brand.js?v=seo2-20261025" defer></script>
  <link rel="stylesheet" href="/assets/css/store/storefront-discovery.css?v=seo2-20261025">
  <script src="/assets/vendor/lucide-icons.min.js?v=bb816557" defer></script>
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="SmartISP Ecuador">
  <meta property="og:locale" content="es_EC">
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
    "name": <?= json_encode($categoryName . ' SmartISP Ecuador', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    "description": <?= json_encode($metaDesc, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    "url": <?= json_encode($canonicalUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    "mainEntity": {
      "@type": "ItemList",
      "name": <?= json_encode('Productos de ' . $categoryName, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
      "itemListElement": <?= json_encode($productItemList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>
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
    .category-breadcrumb{display:flex;flex-wrap:wrap;align-items:center;gap:7px;margin:22px 0 12px;color:#556d82;font-size:13px}
    .category-breadcrumb a{color:#087ea4}.category-breadcrumb a:hover{text-decoration:underline}
    .category-breadcrumb [aria-current=page]{overflow-wrap:anywhere;color:#17324d;font-weight:700}
    .category-breadcrumb-separator{color:#91a6b5}
    .category-hero{display:grid;grid-template-columns:minmax(0,1fr);align-items:center;gap:clamp(18px,3vw,36px);min-height:0;margin:0 0 18px;padding:clamp(16px,2.2vw,26px);border:1px solid #c5d7e0;border-left:4px solid #087ea4;border-radius:8px;background:linear-gradient(115deg,#edf8fd 0%,#dff2fb 54%,#c9e9f8 100%);overflow:hidden}
    .category-hero.has-banner{grid-template-columns:minmax(0,1.15fr) minmax(220px,.85fr)}
    .category-hero-copy{flex:1;min-width:0}
    .category-banner{display:block;width:100%;min-width:0;max-height:200px;aspect-ratio:2.6;object-fit:cover;object-position:center;border:1px solid rgba(255,255,255,.8);border-radius:8px;background:#fff;box-shadow:0 14px 30px rgba(16,44,61,.12)}
    .category-eyebrow{color:#087ea4;font-size:11px;font-weight:850;text-transform:uppercase}
    .category-hero h1{margin:5px 0 7px;color:#102c3d;font-size:clamp(24px,2.6vw,32px);line-height:1.1;letter-spacing:0}
    .category-hero p{max-width:760px;margin:0;color:#556d82;font-size:15px}
    .category-chips-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
    .category-chips-row span{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border:1px solid rgba(8,126,164,.18);border-radius:8px;background:rgba(255,255,255,.8);color:#17324d;font-size:12px;font-weight:750;white-space:nowrap}
    .category-chips-row span i{color:#087ea4;flex-shrink:0}
    @media(max-width:520px){.category-chips-row span{font-size:11px;padding:7px 10px}}
    .category-results-layout{display:grid;grid-template-columns:260px minmax(0,1fr);gap:18px;align-items:start}
    .category-sidebar{position:sticky;top:16px;padding:16px;border:1px solid #c5d7e0;border-radius:8px;background:#fff}
    .category-sidebar-title{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 4px}
    .category-sidebar-title strong{color:#102c3d;font-size:15px}
    .category-active-badge{padding:3px 9px;border-radius:99px;background:#e8f5fa;color:#087ea4;font-size:10px;font-weight:850;white-space:nowrap}
    .category-sidebar-subtitle{margin:0 0 12px;color:#556d82;font-size:12px;line-height:1.5}
    .category-sidebar-search{display:flex;align-items:center;gap:7px;margin-bottom:14px;padding:8px 10px;border:1px solid #c5d7e0;border-radius:6px;background:#f5f9fb}
    .category-sidebar-search input{flex:1;min-width:0;border:0;background:transparent;outline:0;font-size:12px;color:#17324d}
    .category-sidebar-search i{color:#556d82;flex:0 0 auto}
    .category-sidebar details{border-top:1px solid #d8e5e7}
    .category-sidebar details:first-of-type{border-top:0}
    .category-sidebar summary{display:flex;align-items:center;justify-content:space-between;padding:12px 2px;color:#102c3d;font-size:13px;font-weight:800;cursor:pointer;list-style:none}
    .category-sidebar summary::-webkit-details-marker{display:none}
    .category-sidebar summary::after{content:"";width:8px;height:8px;border-right:2px solid #556d82;border-bottom:2px solid #556d82;transform:rotate(45deg);transition:transform .15s ease}
    .category-sidebar details[open] summary::after{transform:rotate(-135deg)}
    .category-subcategory-list{display:grid;max-height:320px;overflow:auto;margin-bottom:8px}
    .category-subcategory-list a{display:flex;justify-content:space-between;gap:8px;padding:9px 2px;border-bottom:1px solid #eef2f4;color:#17324d;font-size:12px;font-weight:700}
    .category-subcategory-list a[aria-current=true]{color:#087ea4}
    .category-subcategory-list small{color:#556d82}
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
    .category-list-heading p{margin:3px 0 0;color:#556d82;font-size:13px}
    .category-sort{display:flex;align-items:center;gap:7px;font-size:12px;color:#17324d;font-weight:700}
    .category-sort select{padding:8px 10px;border:1px solid #bdced8;border-radius:6px;background:#fff;color:#17324d;font-size:12px;font-weight:750}
    .category-filters{display:flex;flex-wrap:wrap;gap:8px;margin:15px 0 4px}
    .category-filters a{display:inline-flex;align-items:center;gap:8px;padding:8px 11px;border:1px solid #d8e5e7;border-radius:6px;background:#fff;color:#17324d;font-size:12px;font-weight:750}
    .category-filters a:hover,.category-filters a[aria-current=true]{border-color:#64bad3;background:#e8f5fa;color:#087ea4}
    .category-filters small{color:#556d82;font-size:11px}
    .category-pagination{display:flex;justify-content:center;align-items:center;gap:14px;margin:8px 0 30px;color:#556d82;font-size:13px}
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
      <div><a href="#">Términos y Condiciones</a><a href="#">Política de Privacidad</a><a href="#">Políticas de Garantía</a></div>
    </div>
  </footer>
  <link rel="stylesheet" href="/assets/css/store/site-footer.css?v=1">
  <script src="/assets/js/store/site-footer.js?v=1" defer></script>
  <script src="/assets/js/store/storefront-discovery.js?v=mobile-category-drawer-20261008" defer></script>
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
