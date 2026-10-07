<?php
/**
 * SmartISP - Página Individual de Producto para SEO y Enlaces Únicos
 * 
 * Genera URLs únicas indexables por Google con microdatos Schema.org Product,
 * etiquetas Open Graph, Twitter Cards y soporte para compra/carrito directo.
 */

require_once __DIR__ . '/api/db.php';

$pdo = getDbConnection();
$pTable = $pdo ? getProductsTableName($pdo) : 'products_rows';

$slug = trim($_GET['slug'] ?? ($_GET['id'] ?? ($_GET['p'] ?? '')));

// Función para limpiar texto y convertirlo en slug SEO amigable
function slugify(string $text): string {
    $clean = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    if (!$clean) $clean = $text;
    $clean = preg_replace('~[^\\pL\\d]+~u', '-', $clean);
    $clean = trim($clean, '-');
    $clean = preg_replace('~-+~', '-', $clean);
    $clean = strtolower($clean);
    return !empty($clean) ? substr($clean, 0, 80) : 'articulo';
}

// Extrae el identificador del producto a partir del slug de la URL
function extractProductId(string $slug): string {
    if (preg_match('/^(prod_[a-zA-Z0-9]+)/i', $slug, $m)) {
        return $m[1];
    }
    if (preg_match('/^(intcomex[_-][a-zA-Z0-9]+)/i', $slug, $m)) {
        return str_replace('_', ':', $m[1]);
    }
    if (preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $slug, $m)) {
        return $m[1];
    }
    if (preg_match('/^([0-9]+)-/', $slug, $m)) {
        return $m[1];
    }
    return $slug;
}

$product = null;
if ($pdo && !empty($slug)) {
    $targetId = extractProductId($slug);
    try {
        $stmt = $pdo->prepare("SELECT * FROM `$pTable` WHERE id = :id OR sku = :sku OR id = :slug OR sku = :slug2 LIMIT 1");
        $stmt->execute([':id' => $targetId, ':sku' => $targetId, ':slug' => $slug, ':slug2' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fallback 1: Si el slug tiene guion, verificar si el texto antes del primer guion es el ID
        if (!$row && strpos($slug, '-') !== false) {
            $prefix = substr($slug, 0, strpos($slug, '-'));
            if (!empty($prefix) && $prefix !== $targetId) {
                $stmt = $pdo->prepare("SELECT * FROM `$pTable` WHERE id = :p OR sku = :p2 LIMIT 1");
                $stmt->execute([':p' => $prefix, ':p2' => $prefix]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }

        // Fallback 2: si no coincide por ID directo, buscar por coincidencia en palabras clave del nombre
        if (!$row && strlen($slug) > 2) {
            $words = array_values(array_filter(explode('-', $slug), fn($w) => strlen($w) > 2));
            if (!empty($words)) {
                $term1 = '%' . implode('%', array_slice($words, 0, 3)) . '%';
                $stmt = $pdo->prepare("SELECT * FROM `$pTable` WHERE name LIKE :term1 LIMIT 1");
                $stmt->execute([':term1' => $term1]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row && count($words) > 1) {
                    $sorted = $words;
                    usort($sorted, fn($a, $b) => strlen($b) <=> strlen($a));
                    $term2 = '%' . $sorted[0] . '%';
                    $stmt = $pdo->prepare("SELECT * FROM `$pTable` WHERE name LIKE :term2 LIMIT 1");
                    $stmt->execute([':term2' => $term2]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            }
        }

        // DEV-20261006-002: esta consulta (y los 2 fallback de arriba) no filtraban por visible=1 --
        // un producto que el admin oculto del catalogo seguia totalmente accesible, indexable por
        // Google y comprable en su URL directa /producto/<slug>, igual que uno visible. Las
        // relacionadas (abajo) ya si filtraban visible=1; se iguala el mismo criterio aqui.
        if ($row && (int)($row['visible'] ?? 1) === 1) {
            $product = normalizeProductRow($row);
        }
    } catch (Throwable $e) {
        error_log('Error cargando producto en producto.php: ' . $e->getMessage());
    }
}

// Si no se encuentra el producto, retornar 404 pero con cabecera y enlace al catálogo
if (!$product) {
    http_response_code(404);
}

// Datos calculados para SEO
$siteUrl = 'https://smart-isp.com.ec';
$productName = $product ? htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') : 'Producto no encontrado';
$productCategory = $product ? htmlspecialchars($product['category'] ?: 'Equipos TI', ENT_QUOTES, 'UTF-8') : 'Catálogo';
$productSku = $product ? htmlspecialchars($product['sku'] ?: $product['id'], ENT_QUOTES, 'UTF-8') : '';
$productPrice = $product ? (float)$product['price'] : 0.0;
$formattedPrice = number_format($productPrice, 2, '.', ',');

$rawDesc = $product ? trim(strip_tags((string)$product['description'])) : '';
if (empty($rawDesc) && $product) {
    $rawDesc = "Adquiere {$product['name']} en SmartISP Ecuador. Equipamiento tecnológico garantizado con envíos a todo el país.";
}
$metaDesc = htmlspecialchars(mb_substr($rawDesc, 0, 160), ENT_QUOTES, 'UTF-8');

$canonicalSlug = $product ? ($product['id'] . '-' . slugify($product['name'])) : 'catalogo';
$canonicalUrl = $siteUrl . '/producto/' . $canonicalSlug;

// Imagen absoluta para redes sociales y Googlebot
$imgSrc = $product ? $product['imageUrl'] : ($siteUrl . '/assets/favicons/favicon-512x512.png');
if (strpos($imgSrc, '/') === 0) {
    $imgSrc = $siteUrl . $imgSrc;
}

// Productos relacionados en la misma categoría
$relatedProducts = [];
if ($product && $pdo) {
    try {
        $stmtRel = $pdo->prepare("SELECT * FROM `$pTable` WHERE category = :cat AND id != :id AND visible = 1 LIMIT 4");
        $stmtRel->execute([':cat' => $product['category'], ':id' => $product['id']]);
        while ($r = $stmtRel->fetch(PDO::FETCH_ASSOC)) {
            $relatedProducts[] = normalizeProductRow($r);
        }
    } catch (Throwable $e) {}
}

// Enlace de WhatsApp preconfigurado con el producto actual
$waMessage = rawurlencode("Hola SmartISP, estoy interesado en comprar el producto:\n*{$productName}*\nPrecio: \${$formattedPrice}\nEnlace: {$canonicalUrl}");
$waUrl = "https://wa.me/593983576667?text={$waMessage}";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $product ? "{$productName} - Precio \${$formattedPrice} | SmartISP Ecuador" : "Producto no encontrado | SmartISP" ?></title>
    <meta name="description" content="<?= $metaDesc ?>">
    <link rel="canonical" href="<?= $canonicalUrl ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">

    <!-- Favicon -->
    <link rel="shortcut icon" type="image/x-icon" href="<?= $siteUrl ?>/assets/favicons/favicon.ico">
    <link rel="icon" type="image/svg+xml" href="<?= $siteUrl ?>/assets/favicons/favicon.svg">
    <link rel="icon" type="image/png" sizes="48x48" href="<?= $siteUrl ?>/assets/favicons/favicon-48x48.png">
    <link rel="icon" type="image/png" sizes="96x96" href="<?= $siteUrl ?>/assets/favicons/favicon-96x96.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= $siteUrl ?>/assets/favicons/favicon-192x192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $siteUrl ?>/assets/favicons/apple-touch-icon.png">
    <meta name="theme-color" content="#102a43">

    <!-- Open Graph / Redes Sociales (Facebook, WhatsApp, LinkedIn) -->
    <meta property="og:type" content="product">
    <meta property="og:site_name" content="SmartISP Ecuador">
    <meta property="og:title" content="<?= $productName ?> | SmartISP">
    <meta property="og:description" content="<?= $metaDesc ?>">
    <meta property="og:image" content="<?= htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:url" content="<?= $canonicalUrl ?>">
    <?php if ($product && $productPrice > 0): ?>
    <meta property="product:price:amount" content="<?= $productPrice ?>">
    <meta property="product:price:currency" content="USD">
    <meta property="product:availability" content="instock">
    <?php endif; ?>

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= $productName ?> | SmartISP">
    <meta name="twitter:description" content="<?= $metaDesc ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') ?>">

    <?php if ($product): ?>
    <!-- Microdatos Schema.org Product para Google Search & Google Shopping -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org/",
      "@type": "Product",
      "name": <?= json_encode($product['name'], JSON_UNESCAPED_UNICODE) ?>,
      "image": [<?= json_encode($imgSrc, JSON_UNESCAPED_SLASHES) ?>],
      "description": <?= json_encode($rawDesc, JSON_UNESCAPED_UNICODE) ?>,
      "sku": <?= json_encode($product['sku'] ?: $product['id'], JSON_UNESCAPED_UNICODE) ?>,
      "category": <?= json_encode($product['category'], JSON_UNESCAPED_UNICODE) ?>,
      "brand": {
        "@type": "Brand",
        "name": "SmartISP"
      },
      "offers": {
        "@type": "Offer",
        "url": <?= json_encode($canonicalUrl, JSON_UNESCAPED_SLASHES) ?>,
        "priceCurrency": "USD",
        "price": "<?= number_format($productPrice, 2, '.', '') ?>",
        "priceValidUntil": "2027-12-31",
        "itemCondition": "https://schema.org/NewCondition",
        "availability": "https://schema.org/InStock",
        "seller": {
          "@type": "Organization",
          "name": "SmartISP",
          "url": "https://smart-isp.com.ec"
        }
      }
    }
    </script>
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "BreadcrumbList",
      "itemListElement": [
        {
          "@type": "ListItem",
          "position": 1,
          "name": "Inicio",
          "item": "https://smart-isp.com.ec/"
        },
        {
          "@type": "ListItem",
          "position": 2,
          "name": "Tienda",
          "item": "https://smart-isp.com.ec/tienda.html"
        },
        {
          "@type": "ListItem",
          "position": 3,
          "name": <?= json_encode($product['category'], JSON_UNESCAPED_UNICODE) ?>,
          "item": "https://smart-isp.com.ec/tienda.html?categoria=<?= urlencode($product['category']) ?>"
        },
        {
          "@type": "ListItem",
          "position": 4,
          "name": <?= json_encode($product['name'], JSON_UNESCAPED_UNICODE) ?>,
          "item": <?= json_encode($canonicalUrl, JSON_UNESCAPED_SLASHES) ?>
        }
      ]
    }
    </script>
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        :root {
            --navy: #102a43;
            --navy-dark: #091a25;
            --blue: #1177c9;
            --blue-hover: #0d5fa0;
            --cyan: #00d2ff;
            --orange: #f36b21;
            --bg: #f8fafc;
            --card: #ffffff;
            --border: #e2e8f0;
            --text: #1e293b;
            --muted: #64748b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); line-height: 1.6; }
        
        /* Barra superior */
        .topbar { background: var(--navy-dark); color: #cbd5e1; font-size: 13px; padding: 8px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .topbar a { color: #38bdf8; text-decoration: none; font-weight: 500; }
        .topbar a:hover { text-decoration: underline; }

        /* Header */
        header { background: var(--card); border-bottom: 1px solid var(--border); padding: 14px 20px; position: sticky; top: 0; z-index: 50; }
        .header-wrap { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 20px; }
        .logo { display: flex; align-items: center; gap: 10px; text-decoration: none; color: var(--navy); font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 700; }
        .logo img { width: 38px; height: 38px; border-radius: 50%; }
        .logo span { color: var(--blue); }
        .header-nav { display: flex; align-items: center; gap: 14px; }
        .btn-nav { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 600; color: var(--navy); border: 1px solid var(--border); transition: all .2s; }
        .btn-nav:hover { background: #f1f5f9; border-color: #cbd5e1; }
        .btn-nav.primary { background: var(--blue); color: #fff; border-color: var(--blue); }
        .btn-nav.primary:hover { background: var(--blue-hover); }

        /* Contenedor principal */
        .container { max-width: 1200px; margin: 24px auto 60px; padding: 0 20px; }
        .breadcrumbs { font-size: 13px; color: var(--muted); margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .breadcrumbs a { color: var(--blue); text-decoration: none; }
        .breadcrumbs a:hover { text-decoration: underline; }
        .breadcrumbs span.current { color: var(--text); font-weight: 500; }

        /* Tarjeta de producto */
        .product-card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 32px; display: grid; grid-template-columns: 1fr 1.15fr; gap: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); }
        @media (max-width: 860px) {
            .product-card { grid-template-columns: 1fr; padding: 20px; gap: 24px; }
        }

        /* Galería e Imagen */
        .product-image-box { position: relative; background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 24px; display: grid; place-items: center; aspect-ratio: 1; overflow: hidden; }
        .product-image-box img { max-width: 100%; max-height: 100%; object-fit: contain; transition: transform .3s ease; }
        .product-image-box:hover img { transform: scale(1.04); }
        .tag-category { position: absolute; top: 16px; left: 16px; background: #e0f2fe; color: #0369a1; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 4px 10px; border-radius: 6px; letter-spacing: 0.5px; }

        /* Columna de detalles */
        .product-info h1 { font-family: 'Space Grotesk', sans-serif; font-size: 26px; line-height: 1.25; color: var(--navy); margin-bottom: 12px; }
        .sku-row { display: flex; align-items: center; gap: 12px; font-size: 13px; color: var(--muted); margin-bottom: 16px; }
        .stock-badge { background: #dcfce7; color: #15803d; font-weight: 700; padding: 2px 8px; border-radius: 4px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; }
        
        .price-box { background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; padding: 18px 22px; margin: 18px 0 24px; }
        .price-value { font-family: 'Space Grotesk', sans-serif; font-size: 34px; font-weight: 700; color: var(--navy); line-height: 1; }
        .price-tax { font-size: 12px; color: var(--muted); margin-top: 4px; font-weight: 500; }
        .price-quote { font-size: 22px; color: #c2410c; font-weight: 700; }

        .desc-title { font-size: 15px; font-weight: 700; color: var(--navy); margin-bottom: 8px; }
        .desc-text { color: #475569; font-size: 14.5px; line-height: 1.7; margin-bottom: 24px; white-space: pre-line; }

        /* Botones de acción */
        .actions-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
        @media (max-width: 520px) { .actions-grid { grid-template-columns: 1fr; } }
        
        .btn-action { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 14px 20px; border-radius: 10px; font-size: 15px; font-weight: 700; text-decoration: none; cursor: pointer; border: 0; transition: all .2s; }
        .btn-cart { background: var(--blue); color: #fff; }
        .btn-cart:hover { background: var(--blue-hover); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(17,119,201,0.3); }
        .btn-wa { background: #25d366; color: #fff; }
        .btn-wa:hover { background: #1eb855; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(37,211,102,0.3); }
        .btn-share { background: #fff; color: var(--navy); border: 1px solid var(--border); font-size: 13.5px; padding: 11px; }
        .btn-share:hover { background: #f8fafc; border-color: #cbd5e1; }
        .btn-catalog { background: #f1f5f9; color: var(--navy); border: 1px solid var(--border); font-size: 13.5px; padding: 11px; }
        .btn-catalog:hover { background: #e2e8f0; border-color: #cbd5e1; color: var(--blue); }

        /* Garantías */
        .guarantees { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }
        @media (max-width: 600px) { .guarantees { grid-template-columns: 1fr; } }
        .guarantee-item { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--muted); }
        .guarantee-item i { color: var(--blue); flex-shrink: 0; }

        /* Sección relacionados */
        .related-section { margin-top: 50px; }
        .related-title { font-family: 'Space Grotesk', sans-serif; font-size: 22px; color: var(--navy); margin-bottom: 20px; }
        .related-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px; }
        .rel-card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 14px; text-decoration: none; color: inherit; display: flex; flex-direction: column; transition: all .2s; }
        .rel-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.06); border-color: #cbd5e1; }
        .rel-img-box { background: #fff; aspect-ratio: 1.1; display: grid; place-items: center; overflow: hidden; border-radius: 8px; margin-bottom: 10px; }
        .rel-img-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .rel-name { font-size: 13.5px; font-weight: 600; color: var(--navy); line-height: 1.35; margin-bottom: 6px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .rel-price { font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 700; color: var(--blue); margin-top: auto; }

        /* Notificación toast */
        .toast { position: fixed; bottom: 24px; right: 24px; background: #0f172a; color: #fff; padding: 12px 20px; border-radius: 8px; font-size: 14px; box-shadow: 0 10px 30px rgba(0,0,0,0.25); display: none; align-items: center; gap: 8px; z-index: 100; animation: slideUp .3s ease; }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    </style>
</head>
<body>

    <!-- Barra de contacto -->
    <div class="topbar">
        <div>SmartISP Ecuador · Distribuidor Oficial de Equipamiento Tecnológico</div>
        <div>WhatsApp Comercial: <a href="https://wa.me/593983576667" target="_blank">+593 983 576 667</a></div>
    </div>

    <!-- Encabezado -->
    <header>
        <div class="header-wrap">
            <a href="/" class="logo">
                <img src="/assets/favicons/favicon-48x48.png" alt="SmartISP Logo">
                Smart<span>ISP</span>
            </a>
            <div class="header-nav">
                <a href="/tienda.html" class="btn-nav">
                    <i data-lucide="layout-grid" width="16"></i> Catálogo Completo
                </a>
                <a href="/tienda.html?openCart=1" class="btn-nav primary">
                    <i data-lucide="shopping-cart" width="16"></i> Ir al Carrito
                </a>
            </div>
        </div>
    </header>

    <main class="container">
        <?php if ($product): ?>
            <!-- Migas de pan (Breadcrumbs) para Google -->
            <nav class="breadcrumbs" aria-label="Migas de pan">
                <a href="/">Inicio</a>
                <span>›</span>
                <a href="/tienda.html">Tienda</a>
                <span>›</span>
                <a href="/tienda.html?categoria=<?= urlencode($product['category']) ?>"><?= $productCategory ?></a>
                <span>›</span>
                <span class="current"><?= mb_strimwidth($productName, 0, 45, '...') ?></span>
            </nav>

            <!-- Ficha de Producto Principal -->
            <article class="product-card">
                <!-- Imagen -->
                <div class="product-image-box">
                    <span class="tag-category"><?= $productCategory ?></span>
                    <img src="<?= htmlspecialchars($product['imageUrl'], ENT_QUOTES, 'UTF-8') ?>" 
                         alt="<?= $productName ?>" 
                         loading="eager"
                         fetchpriority="high"
                         onerror="this.src='/assets/favicons/favicon-512x512.png'">
                </div>

                <!-- Información y Compra -->
                <div class="product-info">
                    <div class="sku-row">
                        <?php if ($productSku): ?>
                            <span>SKU: <strong><?= $productSku ?></strong></span>
                        <?php endif; ?>
                        <span class="stock-badge"><i data-lucide="check" width="13"></i> Disponible en inventario</span>
                    </div>

                    <h1><?= $productName ?></h1>

                    <div class="price-box">
                        <?php if ($productPrice > 0): ?>
                            <div class="price-value">$<?= $formattedPrice ?> <span style="font-size:15px;font-weight:500;color:var(--muted);">USD</span></div>
                            <div class="price-tax">Precios incluye IVA (15% Ecuador) · Facturación oficial</div>
                        <?php else: ?>
                            <div class="price-quote">Precio disponible bajo cotización</div>
                            <div class="price-tax">Contáctanos por WhatsApp para recibir una cotización al instante</div>
                        <?php endif; ?>
                    </div>

                    <div class="desc-title">Detalles del Producto</div>
                    <div class="desc-text"><?= nl2br(htmlspecialchars($rawDesc, ENT_QUOTES, 'UTF-8')) ?></div>

                    <!-- Botones de Acción -->
                    <div class="actions-grid">
                        <button class="btn-action btn-cart" id="btnAddToCart">
                            <i data-lucide="shopping-cart" width="18"></i> Agregar al Carrito
                        </button>
                        <a href="<?= $waUrl ?>" target="_blank" rel="noopener noreferrer" class="btn-action btn-wa">
                            <i data-lucide="message-circle" width="18"></i> Pedir por WhatsApp
                        </a>
                        <button class="btn-action btn-share" id="btnShareProduct">
                            <i data-lucide="share-2" width="16"></i> Copiar enlace único
                        </button>
                        <a href="/tienda.html?p=<?= urlencode($product['id']) ?>" class="btn-action btn-catalog">
                            <i data-lucide="layout-grid" width="16"></i> Explorar en catálogo
                        </a>
                    </div>

                    <!-- Garantías oficiales -->
                    <div class="guarantees">
                        <div class="guarantee-item">
                            <i data-lucide="shield-check" width="16"></i>
                            <span>Garantía técnica oficial</span>
                        </div>
                        <div class="guarantee-item">
                            <i data-lucide="truck" width="16"></i>
                            <span>Envíos a todo el Ecuador</span>
                        </div>
                        <div class="guarantee-item">
                            <i data-lucide="file-text" width="16"></i>
                            <span>Factura autorizada SRI</span>
                        </div>
                    </div>
                </div>
            </article>

            <!-- Productos Relacionados -->
            <?php if (!empty($relatedProducts)): ?>
                <section class="related-section">
                    <h2 class="related-title">Otros productos en <?= $productCategory ?></h2>
                    <div class="related-grid">
                        <?php foreach ($relatedProducts as $rel): 
                            $relSlug = $rel['id'] . '-' . slugify($rel['name']);
                            $relPrice = (float)$rel['price'];
                        ?>
                            <a href="/producto/<?= $relSlug ?>" class="rel-card">
                                <div class="rel-img-box">
                                    <img src="<?= htmlspecialchars($rel['imageUrl'], ENT_QUOTES, 'UTF-8') ?>" 
                                         alt="<?= htmlspecialchars($rel['name'], ENT_QUOTES, 'UTF-8') ?>" 
                                         loading="lazy" 
                                         onerror="this.src='/assets/favicons/favicon-512x512.png'">
                                </div>
                                <div class="rel-name"><?= htmlspecialchars($rel['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="rel-price">
                                    <?= $relPrice > 0 ? ('$' . number_format($relPrice, 2, '.', ',')) : 'Cotizar' ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

        <?php else: ?>
            <!-- Vista de Producto No Encontrado -->
            <div style="text-align:center; padding: 60px 20px; background:#fff; border-radius:16px; border:1px solid var(--border);">
                <i data-lucide="alert-circle" width="48" style="color:var(--orange); margin-bottom:14px;"></i>
                <h1 style="font-family:'Space Grotesk',sans-serif; color:var(--navy); margin-bottom:10px;">Producto no encontrado</h1>
                <p style="color:var(--muted); max-width:500px; margin: 0 auto 24px;">El producto que estás buscando no existe o fue retirado del catálogo activo. Puedes explorar los más de 2,700 productos disponibles en nuestra tienda.</p>
                <a href="/tienda.html" class="btn-nav primary" style="padding: 12px 24px; font-size:15px;">
                    <i data-lucide="arrow-left" width="16"></i> Ver Catálogo Completo
                </a>
            </div>
        <?php endif; ?>
    </main>

    <!-- Notificación Toast -->
    <div id="toastMessage" class="toast">
        <i data-lucide="check-circle-2" width="18" style="color:#4ade80;"></i>
        <span id="toastText">¡Enlace copiado al portapapeles!</span>
    </div>

    <script>
        lucide.createIcons();

        const showToast = (msg) => {
            const toast = document.querySelector('#toastMessage');
            const text = document.querySelector('#toastText');
            if (!toast || !text) return;
            text.textContent = msg;
            toast.style.display = 'flex';
            setTimeout(() => { toast.style.display = 'none'; }, 3200);
        };

        // Copiar enlace al portapapeles
        const btnShare = document.querySelector('#btnShareProduct');
        if (btnShare) {
            btnShare.addEventListener('click', async () => {
                const url = window.location.href;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    try {
                        await navigator.clipboard.writeText(url);
                        showToast('¡Enlace del producto copiado con éxito!');
                        return;
                    } catch (e) {}
                }
                // Fallback manual
                const tempInput = document.createElement('input');
                tempInput.value = url;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);
                showToast('¡Enlace del producto copiado!');
            });
        }

        // Agregar al Carrito (guarda en localStorage compatible con tienda.html y checkout.html)
        const btnCart = document.querySelector('#btnAddToCart');
        if (btnCart) {
            btnCart.addEventListener('click', () => {
                const currentProduct = <?= $product ? json_encode([
                    'id' => (string)$product['id'],
                    'name' => (string)$product['name'],
                    'price' => (float)$product['price'],
                    'category' => (string)$product['category'],
                    'image' => (string)$product['imageUrl'],
                    'sku' => (string)($product['sku'] ?? '')
                ], JSON_UNESCAPED_UNICODE) : 'null' ?>;

                if (!currentProduct) return;

                try {
                    const cartKey = 'smartisp.cart';
                    const rawCart = JSON.parse(localStorage.getItem(cartKey) || '[]');
                    const existingIdx = rawCart.findIndex(item => String(item.id || item.name) === String(currentProduct.id || currentProduct.name));
                    
                    if (existingIdx >= 0) {
                        rawCart[existingIdx].quantity = Math.min(99, Number(rawCart[existingIdx].quantity || 1) + 1);
                    } else {
                        rawCart.push({
                            ...currentProduct,
                            quantity: 1
                        });
                    }

                    localStorage.setItem(cartKey, JSON.stringify(rawCart));
                    showToast('¡Producto agregado al carrito!');
                    
                    setTimeout(() => {
                        window.location.href = '/tienda.html?openCart=1';
                    }, 600);
                } catch (e) {
                    window.location.href = '/tienda.html?p=' + encodeURIComponent(currentProduct.id);
                }
            });
        }
    </script>
</body>
</html>
