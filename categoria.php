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
$categoryParentName = '';
$products = [];
$allCategoryNames = [];
$audioVideoSubcategories = [];
$selectedSubcategory = '';
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
            $isAudioVideoCategory = slugifyCategoryPage($categoryName) === slugifyCategoryPage('Audio y Video') && $categoryParentName === '';
            if ($isAudioVideoCategory) {
                $facetStmt = $pdo->prepare("
                    SELECT TRIM(subcategory) AS name, COUNT(*) AS product_count
                    FROM `$pTable`
                    WHERE visible = 1 AND TRIM(category) = :category_name AND TRIM(subcategory) <> ''
                    GROUP BY TRIM(subcategory)
                    ORDER BY TRIM(subcategory) ASC
                ");
                $facetStmt->execute([':category_name' => $categoryName]);
                $audioVideoSubcategories = $facetStmt->fetchAll(PDO::FETCH_ASSOC);
                $requestedSubcategory = trim((string)($_GET['subcategoria'] ?? ''));
                foreach ($audioVideoSubcategories as $facet) {
                    if ((string)$facet['name'] === $requestedSubcategory) {
                        $selectedSubcategory = $requestedSubcategory;
                        break;
                    }
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
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `$pTable` WHERE $where");
            $countStmt->execute($params);
            $totalProducts = (int)$countStmt->fetchColumn();
            $totalPages = max(1, (int)ceil($totalProducts / $pageSize));
            $currentPage = min($currentPage, $totalPages);
            $offset = ($currentPage - 1) * $pageSize;

            $stmtProducts = $pdo->prepare("
                SELECT *
                FROM `$pTable`
                WHERE $where
                ORDER BY updated_at DESC, name ASC
                LIMIT $pageSize OFFSET $offset
            ");
            $stmtProducts->execute($params);
            while ($row = $stmtProducts->fetch(PDO::FETCH_ASSOC)) {
                $products[] = normalizeProductRow($row);
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
  <script src="/assets/js/public-brand.js?v=store-grid-density-20261009" defer></script>
  <link rel="stylesheet" href="/assets/css/storefront-discovery.css?v=store-grid-density-20261009">
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
    .category-hero{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(240px,.95fr);align-items:center;gap:clamp(20px,4vw,48px);min-height:270px;margin:0 0 22px;padding:clamp(22px,4vw,42px);border:1px solid #cfe3ed;border-left:4px solid #087ea4;border-radius:10px;background:linear-gradient(115deg,#edf8fd 0%,#dff2fb 54%,#c9e9f8 100%);overflow:hidden}
    .category-hero-copy{flex:1;min-width:0}
    .category-banner{display:block;width:100%;min-width:0;aspect-ratio:1.92;object-fit:cover;border:1px solid rgba(255,255,255,.8);border-radius:8px;background:#fff;box-shadow:0 14px 30px rgba(16,44,61,.12)}
    .category-banner-fallback{display:grid;place-items:center;min-height:190px;aspect-ratio:1.92;border:1px solid #c5e4f2;border-radius:8px;background:radial-gradient(ellipse at 72% 35%,#bce6f8,transparent 44%),linear-gradient(135deg,#eaf7fc,#d4edf9);color:#087ea4}
    .category-banner-fallback svg{width:62px;height:62px;stroke-width:1.5}
    .category-eyebrow{color:#087ea4;font-size:11px;font-weight:850;text-transform:uppercase}
    .category-hero h1{margin:5px 0 7px;color:#102c3d;font-size:38px;line-height:1.1;letter-spacing:0}
    .category-hero p{max-width:760px;margin:0;color:#60798f;font-size:15px}
    .category-total{display:inline-flex;width:max-content;margin-top:14px;padding:9px 13px;border:1px solid #d8e5e7;border-radius:99px;background:#fff;color:#17324d;font-size:13px;font-weight:800;white-space:nowrap}
    .category-list-heading{display:flex;align-items:end;justify-content:space-between;gap:14px;margin-top:18px}
    .category-list-heading h2{margin:0;color:#102c3d;font-size:22px}
    .category-list-heading p{margin:3px 0 0;color:#60798f;font-size:13px}
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
    @media(max-width:760px){.category-breadcrumb{margin-top:16px;font-size:12px}.category-hero{grid-template-columns:1fr;gap:18px;min-height:0;padding:20px}.category-hero h1{font-size:30px}.category-banner,.category-banner-fallback{aspect-ratio:1.8}.category-list-heading{align-items:flex-start;flex-direction:column}.category-pagination{gap:8px}.category-pagination a{padding:8px}}
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
    <section class="category-hero">
      <div class="category-hero-copy"><div class="category-eyebrow"><?= $categoryParentName !== '' ? escapeCategoryPage($categoryParentName) : 'Catálogo de productos' ?></div>
      <h1><?= escapeCategoryPage($categoryName !== '' ? $categoryName : 'Categoría no encontrada') ?></h1>
      <p><?= escapeCategoryPage($categoryName !== '' ? $metaDesc : 'Vuelve al catálogo para explorar los productos disponibles.') ?></p>
      <?php if ($categoryName !== ''): ?><div class="category-total"><?= number_format($totalProducts, 0, ',', '.') ?> productos</div><?php endif; ?></div>
      <?php if ($categoryName !== '' && $categoryBannerUrl !== ''): ?><img class="category-banner" src="<?= escapeCategoryPage($categoryBannerUrl) ?>" alt="<?= escapeCategoryPage($categoryBannerAlt !== '' ? $categoryBannerAlt : $categoryName) ?>" width="900" height="440" fetchpriority="high" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><div class="category-banner-fallback" hidden aria-hidden="true"><i data-lucide="<?= escapeCategoryPage($categoryIcon) ?>"></i></div><?php elseif ($categoryName !== ''): ?><div class="category-banner-fallback" aria-hidden="true"><i data-lucide="<?= escapeCategoryPage($categoryIcon) ?>"></i></div><?php endif; ?>
    </section>

    <?php if ($categoryName !== '' && $totalProducts > 0): ?>
      <div class="category-list-heading"><div><h2>Productos de <?= escapeCategoryPage($categoryName) ?></h2><p><?= number_format($totalProducts, 0, ',', '.') ?> resultados<?= $selectedSubcategory !== '' ? ' en ' . escapeCategoryPage($selectedSubcategory) : '' ?></p></div></div>
      <?php if (!empty($audioVideoSubcategories)): ?>
        <nav class="category-filters" aria-label="Filtrar Audio y Video por tipo">
          <?php $allFilterUrl = '/categoria/' . rawurlencode(slugifyCategoryPage($categoryName)) . '/'; ?>
          <a href="<?= $allFilterUrl ?>"<?= $selectedSubcategory === '' ? ' aria-current="true"' : '' ?>>Todos <small><?= number_format($allProductCount, 0, ',', '.') ?></small></a>
          <?php foreach ($audioVideoSubcategories as $facet): ?>
            <a href="<?= $allFilterUrl . '?' . http_build_query(['subcategoria' => (string)$facet['name']]) ?>"<?= $selectedSubcategory === (string)$facet['name'] ? ' aria-current="true"' : '' ?>><?= escapeCategoryPage((string)$facet['name']) ?> <small><?= number_format((int)$facet['product_count'], 0, ',', '.') ?></small></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>
      <section class="store-product-grid" aria-label="Productos de <?= escapeCategoryPage($categoryName) ?>">
        <?php foreach ($products as $index => $product): ?>
          <?php $storeProduct = $product; $storeProductUrl = '/producto/' . productSlugForCategoryPage($product); $storeProductIndex = $index; require __DIR__ . '/includes/storefront-product-card.php'; ?>
        <?php endforeach; ?>
      </section>
      <?php if ($totalPages > 1): ?>
        <?php
          $previousQuery = array_filter(['subcategoria' => $selectedSubcategory, 'page' => $currentPage > 2 ? $currentPage - 1 : null], fn($value) => $value !== null && $value !== '');
          $nextQuery = array_filter(['subcategoria' => $selectedSubcategory, 'page' => $currentPage + 1], fn($value) => $value !== null && $value !== '');
          $categoryPath = '/categoria/' . rawurlencode(slugifyCategoryPage($categoryName)) . '/';
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
</body>
</html>
