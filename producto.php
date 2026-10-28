<?php
/**
 * SmartISP - Página Individual de Producto para SEO y Enlaces Únicos
 * 
 * Genera URLs únicas indexables por Google con microdatos Schema.org Product,
 * etiquetas Open Graph, Twitter Cards y soporte para compra/carrito directo.
 */

require_once __DIR__ . '/api/lib/db.php';

$pdo = getDbConnection();
$pTable = $pdo ? getProductsTableName($pdo) : 'products_rows';

$slug = trim($_GET['slug'] ?? ($_GET['id'] ?? ($_GET['p'] ?? '')));

// Función para limpiar texto y convertirlo en slug SEO amigable
function slugify(string $text): string {
    return smartispSlug($text, 80, 'articulo');
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

// Cualquier variante de la URL (slug viejo, fallback por nombre, ?id=) redirige a la canónica:
// sin esto el fallback LIKE respondía 200 a infinitas URLs con el mismo contenido.
if ($product && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    $canonicalPathSlug = $product['id'] . '-' . slugify($product['name']);
    if ($slug !== $canonicalPathSlug) {
        $query = $_GET;
        unset($query['slug'], $query['id'], $query['p']);
        $location = '/producto/' . str_replace('%3A', ':', rawurlencode((string)$product['id'])) . '-' . slugify($product['name']);
        header('Location: ' . $location . ($query ? '?' . http_build_query($query) : ''), true, 301);
        exit;
    }
}

// Datos calculados para SEO
$siteUrl = 'https://smart-isp.com.ec';
$productName = $product ? htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') : 'Producto no encontrado';
$productCategory = $product ? htmlspecialchars($product['category'] ?: 'Equipos TI', ENT_QUOTES, 'UTF-8') : 'Catálogo';
$productCategoryUrl = $product && trim((string)$product['category']) !== ''
    ? '/categoria/' . smartispSlug(trim((string)$product['category']), 90, 'categoria') . '/'
    : '/categorias-destacadas';
$productSku = $product ? htmlspecialchars($product['sku'] ?: $product['id'], ENT_QUOTES, 'UTF-8') : '';
$productPrice = $product ? (float)$product['price'] : 0.0;
$formattedPrice = number_format($productPrice, 2, '.', ',');

$rawDesc = $product ? trim(strip_tags((string)$product['description'])) : '';
if (empty($rawDesc) && $product) {
    $rawDesc = "Adquiere {$product['name']} en SmartISP Ecuador. Equipamiento tecnológico garantizado con envíos a todo el país.";
}
$metaDesc = htmlspecialchars(mb_substr($rawDesc, 0, 160), ENT_QUOTES, 'UTF-8');

$canonicalSlug = $product ? ($product['id'] . '-' . slugify($product['name'])) : '';
$canonicalUrl = $product ? ($siteUrl . '/producto/' . $canonicalSlug) : ($siteUrl . '/tienda.html');
$robotsMeta = $product ? 'index, follow, max-image-preview:large' : 'noindex, follow';

// Imagen absoluta para redes sociales y Googlebot
$imgSrc = $product ? $product['imageUrl'] : ($siteUrl . '/assets/favicons/favicon-512x512.png');
if (strpos($imgSrc, '/') === 0) {
    $imgSrc = $siteUrl . $imgSrc;
}
$productSchema = null;
if ($product) {
    $productSchema = [
        '@context' => 'https://schema.org/',
        '@type' => 'Product',
        'name' => $product['name'],
        'image' => [$imgSrc],
        'description' => $rawDesc,
        'sku' => $product['sku'] ?: $product['id'],
        'category' => $product['category'],
    ];
    if ($productPrice > 0) {
        // Search Console (Datos estructurados de Fichas de comerciantes) marcó 'availability'
        // como campo faltante en 'offers'. Se calcula igual que en categoria.php/productos-
        // destacados.php: si existe product_inventory y tiene fila para este producto, se usa el
        // stock real; si no hay tabla o no hay fila, se asume InStock (mismo criterio que ya usa
        // el resto del sitio para productos sin inventario registrado: visible = a la venta).
        $availability = 'https://schema.org/InStock';
        try {
            $hasInventoryTable = smartispTableExists($pdo, 'product_inventory');
            if ($hasInventoryTable) {
                $invStmt = $pdo->prepare('SELECT GREATEST(CAST(quantity_available AS SIGNED) - CAST(quantity_reserved AS SIGNED), 0) AS available_qty FROM product_inventory WHERE product_id = :id LIMIT 1');
                $invStmt->execute([':id' => $product['id']]);
                $availableQty = $invStmt->fetchColumn();
                if ($availableQty !== false && (int)$availableQty <= 0) {
                    $availability = 'https://schema.org/OutOfStock';
                }
            }
        } catch (Throwable $e) {}

        $productSchema['offers'] = [
            '@type' => 'Offer',
            'url' => $canonicalUrl,
            'priceCurrency' => 'USD',
            'price' => number_format($productPrice, 2, '.', ''),
            'availability' => $availability,
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller' => [
                '@type' => 'Organization',
                'name' => 'SmartISP',
                'url' => $siteUrl,
            ],
        ];
    }
}

// Productos relacionados en la misma categoría
$relatedProducts = [];
if ($product && $pdo) {
    try {
        $stmtRel = $pdo->prepare("SELECT * FROM `$pTable` WHERE category = :cat AND id != :id AND visible = 1 ORDER BY (subcategory = :subcat) DESC, CRC32(CONCAT(id, :seed)) LIMIT 4");
        $stmtRel->execute([':cat' => $product['category'], ':id' => $product['id'], ':subcat' => $product['subcategory'] ?? '', ':seed' => $product['id'] . gmdate('Y-m-d')]);
        while ($r = $stmtRel->fetch(PDO::FETCH_ASSOC)) {
            $relatedProducts[] = normalizeProductRow($r);
        }
    } catch (Throwable $e) {}
}

// Numero de WhatsApp real configurado en admin (configuracion.html -> footer_whatsapp); sin eso
// configurado, el boton "Pedir por WhatsApp" simplemente no se muestra (nada de numero inventado).
$waNumber = '';
if ($pdo) {
    try {
        $waStmt = $pdo->query("SELECT setting_value FROM settings_rows WHERE setting_key = 'footer_whatsapp' LIMIT 1");
        $waNumber = trim((string)($waStmt ? $waStmt->fetchColumn() : ''));
    } catch (Throwable $e) {}
}
$waUrl = '';
if ($product && $waNumber !== '') {
    $waDigits = smartispWhatsappNumbers($waNumber)[0]['digits'] ?? '';
    $waPriceLine = $productPrice > 0 ? "Precio: \${$formattedPrice}" : 'Precio: a cotizar';
    $waMessage = rawurlencode("Hola SmartISP, estoy interesado en comprar el producto:\n*{$product['name']}*\n{$waPriceLine}\nEnlace: {$canonicalUrl}");
    $waUrl = "https://wa.me/{$waDigits}?text={$waMessage}";
}

// Disponibilidad real (product_inventory): solo se muestra una insignia de stock si existe una
// fila real para este producto. Sin fila, no se afirma nada sobre existencias.
$stockStatus = null; // null = sin dato real; 'in' = disponible; 'out' = agotado
if ($product && $pdo) {
    try {
        $hasInventoryTable = smartispTableExists($pdo, 'product_inventory');
        if ($hasInventoryTable) {
            $invStmt = $pdo->prepare('SELECT GREATEST(CAST(quantity_available AS SIGNED) - CAST(quantity_reserved AS SIGNED), 0) AS available_qty FROM product_inventory WHERE product_id = :id LIMIT 1');
            $invStmt->execute([':id' => $product['id']]);
            $availableQty = $invStmt->fetchColumn();
            if ($availableQty !== false) {
                $stockStatus = (int)$availableQty > 0 ? 'in' : 'out';
            }
        }
    } catch (Throwable $e) {}
}

// Galeria real de imagenes (product_images); si no hay ninguna, se usa solo la foto principal.
$galleryImages = [];
if ($product && $pdo) {
    try {
        $galStmt = $pdo->prepare('SELECT image_url, image_alt, is_primary FROM product_images WHERE product_id = :id ORDER BY sort_order, id');
        $galStmt->execute([':id' => $product['id']]);
        $galleryImages = $galStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
if (!$galleryImages && $product) {
    foreach (($product['images'] ?: [$product['imageUrl']]) as $index => $url) {
        $galleryImages[] = ['image_url' => $url, 'image_alt' => $productName, 'is_primary' => $index === 0 ? 1 : 0];
    }
}

// Pestanas de la ficha: Caracteristicas siempre (es la descripcion real); Especificaciones,
// Contenido y Garantia solo aparecen si el admin cargo ese dato para este producto especifico.
$productSpecs = $product ? ($product['specs'] ?? []) : [];
$productContentText = $product ? trim((string)($product['contentText'] ?? '')) : '';
$productWarrantyText = $product ? trim((string)($product['warrantyText'] ?? '')) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $product ? ($productPrice > 0 ? "{$productName} - Precio \${$formattedPrice} | SmartISP Ecuador" : "{$productName} | SmartISP Ecuador") : "Producto no encontrado | SmartISP" ?></title>
    <meta name="description" content="<?= $metaDesc ?>">
    <link rel="canonical" href="<?= $canonicalUrl ?>">
    <meta name="robots" content="<?= $robotsMeta ?>">

    <!-- Favicon -->
    <link rel="shortcut icon" type="image/x-icon" href="<?= $siteUrl ?>/assets/favicons/favicon.ico">
    <link rel="icon" type="image/svg+xml" href="<?= $siteUrl ?>/assets/favicons/favicon.svg">
    <link rel="icon" type="image/png" sizes="48x48" href="<?= $siteUrl ?>/assets/favicons/favicon-48x48.png">
    <link rel="icon" type="image/png" sizes="96x96" href="<?= $siteUrl ?>/assets/favicons/favicon-96x96.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= $siteUrl ?>/assets/favicons/favicon-192x192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $siteUrl ?>/assets/favicons/apple-touch-icon.png">
    <meta name="theme-color" content="#102a43">
    <script src="/assets/js/store/public-brand.js?v=seo2-20261025" defer></script>

    <!-- Open Graph / Redes Sociales (Facebook, WhatsApp, LinkedIn) -->
    <meta property="og:type" content="product">
    <meta property="og:site_name" content="SmartISP Ecuador">
    <meta property="og:locale" content="es_EC">
    <meta property="og:title" content="<?= $productName ?> | SmartISP">
    <meta property="og:description" content="<?= $metaDesc ?>">
    <meta property="og:image" content="<?= htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:url" content="<?= $canonicalUrl ?>">
    <?php if ($product && $productPrice > 0): ?>
    <meta property="product:price:amount" content="<?= $productPrice ?>">
    <meta property="product:price:currency" content="USD">
    <?php endif; ?>

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= $productName ?> | SmartISP">
    <meta name="twitter:description" content="<?= $metaDesc ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') ?>">

    <?php if ($product && isset($productSchema['offers'])): ?>
    <!-- Microdatos Schema.org Product para Google Search & Google Shopping. Sin 'offers' (producto
    sin precio valido) Search Console lo marca como error critico "offers/review/aggregateRating" --
    mejor no emitir el schema que emitirlo incompleto. -->
    <script type="application/ld+json">
    <?= json_encode($productSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    </script>
    <?php endif; ?>
    <?php if ($product): ?>
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
          "name": <?= json_encode($product['category'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
          "item": <?= json_encode($siteUrl . $productCategoryUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>
        },
        {
          "@type": "ListItem",
          "position": 4,
          "name": <?= json_encode($product['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
          "item": <?= json_encode($canonicalUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>
        }
      ]
    }
    </script>
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet"></noscript>
    <script src="/assets/vendor/lucide-icons.min.js?v=68df8f2b" defer onload="window.lucide && window.lucide.createIcons()"></script>

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
        .product-image-box { position: relative; background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 18px; display: grid; place-items: center; aspect-ratio: 1; overflow: hidden; }
        .product-image-box img { display: block; width: 100%; height: 100%; max-width: 100%; max-height: 100%; padding: 8px; object-fit: contain; transition: transform .3s ease; }
        .product-image-box:hover img { transform: scale(1.04); }
        @media (max-width: 860px) { .product-image-box { min-height: clamp(280px, 78vw, 420px); aspect-ratio: 1.08; padding: 12px; } .product-image-box img { padding: 4px; } }
        .tag-category { position: absolute; top: 16px; left: 16px; background: #e0f2fe; color: #0369a1; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 4px 10px; border-radius: 6px; letter-spacing: 0.5px; }
        .product-gallery { display: flex; flex-direction: column; gap: 10px; }
        .product-thumbs { display: flex; gap: 8px; flex-wrap: wrap; }
        .product-thumb { width: 60px; height: 60px; padding: 0; border: 2px solid var(--border); border-radius: 8px; overflow: hidden; background: #fff; cursor: pointer; display: grid; place-items: center; }
        .product-thumb img { width: 100%; height: 100%; object-fit: contain; padding: 4px; }
        .product-thumb.is-active, .product-thumb:hover { border-color: var(--blue); }

        /* Columna de detalles */
        .product-info h1 { font-family: 'Space Grotesk', sans-serif; font-size: 26px; line-height: 1.25; color: var(--navy); margin-bottom: 12px; }
        .sku-row { display: flex; align-items: center; gap: 12px; font-size: 13px; color: var(--muted); margin-bottom: 16px; }
        .stock-badge { background: #dcfce7; color: #15803d; font-weight: 700; padding: 2px 8px; border-radius: 4px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; }
        .stock-badge-out { background: #fee2e2; color: #b91c1c; }

        /* Pestañas de detalles */
        .product-tabs { margin-top: 24px; background: var(--card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.03); }
        .product-tabs-nav { display: flex; flex-wrap: wrap; gap: 4px; padding: 10px 16px 0; border-bottom: 1px solid var(--border); }
        .product-tab-btn { padding: 10px 16px; border: 0; background: transparent; color: var(--muted); font-size: 14px; font-weight: 700; cursor: pointer; border-bottom: 2px solid transparent; }
        .product-tab-btn.is-active { color: var(--blue); border-bottom-color: var(--blue); }
        .product-tab-btn:hover { color: var(--navy); }
        .product-tab-panel { display: none; padding: 20px 24px 24px; }
        .product-tab-panel.is-active { display: block; }
        .product-specs-table { width: 100%; border-collapse: collapse; }
        .product-specs-table tr { border-bottom: 1px solid var(--border); }
        .product-specs-table tr:last-child { border-bottom: 0; }
        .product-specs-table th { text-align: left; padding: 10px 12px 10px 0; width: 40%; color: var(--muted); font-size: 13px; font-weight: 700; }
        .product-specs-table td { padding: 10px 0; color: var(--text); font-size: 13.5px; font-weight: 600; }
        @media (max-width: 600px) { .product-specs-table th, .product-specs-table td { display: block; padding: 2px 0; width: auto; } .product-specs-table tr { padding: 8px 0; } }
        
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
        .btn-cart:disabled { background: #94a3b8; cursor: not-allowed; transform: none; box-shadow: none; }
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
    <link rel="stylesheet" href="/assets/css/store/storefront-discovery.css?v=iva-20261027">
    <style>header { position: relative !important; top: auto !important; }.storefront-logo img { height: auto; max-height: 140px; border-radius: 0; }</style>
</head>
<body>
    <?php $storefrontActivePage = 'catalog'; require __DIR__ . '/includes/storefront-discovery-nav.php'; ?>

    <main class="container">
        <?php if ($product): ?>
            <!-- Migas de pan (Breadcrumbs) para Google -->
            <nav class="breadcrumbs" aria-label="Migas de pan">
                <a href="/">Inicio</a>
                <span>›</span>
                <a href="/tienda.html">Tienda</a>
                <span>›</span>
                <a href="<?= htmlspecialchars($productCategoryUrl, ENT_QUOTES, 'UTF-8') ?>"><?= $productCategory ?></a>
                <span>›</span>
                <span class="current"><?= mb_strimwidth($productName, 0, 45, '...') ?></span>
            </nav>

            <!-- Ficha de Producto Principal -->
            <article class="product-card">
                <!-- Imagen / Galería -->
                <div class="product-gallery">
                    <div class="product-image-box" id="mainImageBox">
                        <span class="tag-category"><?= $productCategory ?></span>
                        <img id="mainProductImage" src="<?= htmlspecialchars($galleryImages[0]['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($galleryImages[0]['image_alt'] ?: $productName, ENT_QUOTES, 'UTF-8') ?>"
                             width="640" height="640"
                             loading="eager"
                             fetchpriority="high"
                             onerror="this.src='/assets/favicons/favicon-512x512.png'">
                    </div>
                    <?php if (count($galleryImages) > 1): ?>
                    <div class="product-thumbs">
                        <?php foreach ($galleryImages as $index => $gImg): ?>
                            <button type="button" class="product-thumb<?= $index === 0 ? ' is-active' : '' ?>" data-thumb-src="<?= htmlspecialchars($gImg['image_url'], ENT_QUOTES, 'UTF-8') ?>" data-thumb-alt="<?= htmlspecialchars($gImg['image_alt'] ?: $productName, ENT_QUOTES, 'UTF-8') ?>" aria-label="Ver imagen <?= $index + 1 ?>">
                                <img src="<?= htmlspecialchars($gImg['image_url'], ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Información y Compra -->
                <div class="product-info">
                    <div class="sku-row">
                        <?php if ($productSku): ?>
                            <span>SKU: <strong><?= $productSku ?></strong></span>
                        <?php endif; ?>
                        <?php if ($stockStatus === 'in'): ?>
                            <span class="stock-badge"><i data-lucide="check" width="13"></i> Disponible en inventario</span>
                        <?php elseif ($stockStatus === 'out'): ?>
                            <span class="stock-badge stock-badge-out"><i data-lucide="x" width="13"></i> Agotado</span>
                        <?php endif; ?>
                    </div>

                    <h1><?= $productName ?></h1>

                    <div class="price-box">
                        <?php if ($productPrice > 0): ?>
                            <div class="price-value">$<?= $formattedPrice ?> <span style="font-size:15px;font-weight:500;color:var(--muted);">USD</span></div>
                            <div class="price-tax">Precios incluyen IVA (15% Ecuador) · Facturación oficial</div>
                        <?php else: ?>
                            <div class="price-quote">Precio disponible bajo cotización</div>
                            <div class="price-tax">Contáctanos por WhatsApp para recibir una cotización al instante</div>
                        <?php endif; ?>
                    </div>

                    <!-- Botones de Acción -->
                    <div class="actions-grid">
                        <?php if ($stockStatus === 'out'): ?>
                        <button class="btn-action btn-cart" id="btnAddToCart" type="button" disabled>
                            <i data-lucide="ban" width="18"></i> Agotado
                        </button>
                        <?php else: ?>
                        <button class="btn-action btn-cart" id="btnAddToCart" type="button" data-open-cart-after data-add-to-cart="<?= htmlspecialchars(base64_encode(json_encode(['id' => (string)$product['id'], 'name' => (string)$product['name'], 'price' => $productPrice, 'category' => (string)$product['category'], 'image' => (string)$product['imageUrl'], 'sku' => (string)($product['sku'] ?? '')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), ENT_QUOTES, 'UTF-8') ?>">
                            <i data-lucide="shopping-cart" width="18"></i> Agregar al Carrito
                        </button>
                        <?php endif; ?>
                        <?php if ($waUrl !== ''): ?>
                        <a href="<?= htmlspecialchars($waUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn-action btn-wa">
                            <i data-lucide="message-circle" width="18"></i> <?= $stockStatus === 'out' ? 'Consultar por WhatsApp' : 'Pedir por WhatsApp' ?>
                        </a>
                        <?php endif; ?>
                        <button class="btn-action btn-share" id="btnShareProduct">
                            <i data-lucide="share-2" width="16"></i> Copiar enlace único
                        </button>
                        <a href="<?= htmlspecialchars($productCategoryUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-action btn-catalog">
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

            <!-- Pestañas: Características / Especificaciones / Contenido / Garantía -->
            <?php
                $tabs = ['caracteristicas' => 'Características'];
                if (!empty($productSpecs)) $tabs['especificaciones'] = 'Especificaciones';
                if ($productContentText !== '') $tabs['contenido'] = 'Contenido';
                if ($productWarrantyText !== '') $tabs['garantia'] = 'Garantía';
            ?>
            <section class="product-tabs" aria-label="Detalles del producto">
                <div class="product-tabs-nav" role="tablist">
                    <?php foreach ($tabs as $tabKey => $tabLabel): ?>
                        <button type="button" class="product-tab-btn<?= $tabKey === 'caracteristicas' ? ' is-active' : '' ?>" id="tabbtn-<?= $tabKey ?>" data-tab-target="tab-<?= $tabKey ?>" role="tab" aria-controls="tab-<?= $tabKey ?>" aria-selected="<?= $tabKey === 'caracteristicas' ? 'true' : 'false' ?>"<?= $tabKey === 'caracteristicas' ? '' : ' tabindex="-1"' ?>><?= $tabLabel ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="product-tab-panel is-active" id="tab-caracteristicas" role="tabpanel" aria-labelledby="tabbtn-caracteristicas">
                    <div class="desc-text"><?= nl2br(htmlspecialchars($rawDesc, ENT_QUOTES, 'UTF-8')) ?></div>
                </div>
                <?php if (!empty($productSpecs)): ?>
                <div class="product-tab-panel" id="tab-especificaciones" role="tabpanel" aria-labelledby="tabbtn-especificaciones" hidden>
                    <table class="product-specs-table">
                        <?php foreach ($productSpecs as $spec): ?>
                            <tr><th><?= htmlspecialchars((string)($spec['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></th><td><?= htmlspecialchars((string)($spec['value'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <?php endif; ?>
                <?php if ($productContentText !== ''): ?>
                <div class="product-tab-panel" id="tab-contenido" role="tabpanel" aria-labelledby="tabbtn-contenido" hidden>
                    <div class="desc-text"><?= nl2br(htmlspecialchars($productContentText, ENT_QUOTES, 'UTF-8')) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($productWarrantyText !== ''): ?>
                <div class="product-tab-panel" id="tab-garantia" role="tabpanel" aria-labelledby="tabbtn-garantia" hidden>
                    <div class="desc-text"><?= nl2br(htmlspecialchars($productWarrantyText, ENT_QUOTES, 'UTF-8')) ?></div>
                </div>
                <?php endif; ?>
            </section>

            <!-- Productos Relacionados -->
            <?php if (!empty($relatedProducts)): ?>
                <section class="related-section">
                    <h2 class="related-title">Otros productos en <?= $productCategory ?></h2>
                    <div class="store-product-grid">
                        <?php foreach ($relatedProducts as $index => $rel): ?>
                            <?php $storeProduct = $rel; $storeProductUrl = '/producto/' . str_replace('%3A', ':', rawurlencode((string)$rel['id'])) . '-' . slugify($rel['name']); $storeProductIndex = $index; require __DIR__ . '/includes/storefront-product-card.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

        <?php else: ?>
            <!-- Vista de Producto No Encontrado -->
            <div style="text-align:center; padding: 60px 20px; background:#fff; border-radius:16px; border:1px solid var(--border);">
                <i data-lucide="alert-circle" width="48" style="color:var(--orange); margin-bottom:14px;"></i>
                <h1 style="font-family:'Space Grotesk',sans-serif; color:var(--navy); margin-bottom:10px;">Producto no encontrado</h1>
                <p style="color:var(--muted); max-width:500px; margin: 0 auto 24px;">El producto que estás buscando no existe o fue retirado del catálogo activo. Puedes explorar el resto de productos disponibles en nuestra tienda.</p>
                <a href="/tienda.html" class="btn-nav primary" style="padding: 12px 24px; font-size:15px;">
                    <i data-lucide="arrow-left" width="16"></i> Ver Catálogo Completo
                </a>
            </div>
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
  <link rel="stylesheet" href="/assets/css/store/site-footer.css?v=iva-20261027">
  <script src="/assets/js/store/site-footer.js?v=iva-20261027" defer></script>

    <!-- Notificación Toast -->
    <div id="toastMessage" class="toast">
        <i data-lucide="check-circle-2" width="18" style="color:#4ade80;"></i>
        <span id="toastText">¡Enlace copiado al portapapeles!</span>
    </div>

    <script>
        // Galería: clic en una miniatura cambia la imagen principal
        document.querySelectorAll('.product-thumb').forEach(thumb => {
            thumb.addEventListener('click', () => {
                const mainImg = document.querySelector('#mainProductImage');
                if (mainImg) {
                    mainImg.src = thumb.dataset.thumbSrc;
                    mainImg.alt = thumb.dataset.thumbAlt || '';
                }
                document.querySelectorAll('.product-thumb').forEach(t => t.classList.remove('is-active'));
                thumb.classList.add('is-active');
            });
        });

        // Pestañas de detalles del producto
        const tabButtons = Array.from(document.querySelectorAll('.product-tab-btn'));
        const selectTab = btn => {
            tabButtons.forEach(b => {
                const active = b === btn;
                b.classList.toggle('is-active', active);
                b.setAttribute('aria-selected', String(active));
                b.tabIndex = active ? 0 : -1;
            });
            document.querySelectorAll('.product-tab-panel').forEach(p => { p.classList.remove('is-active'); p.hidden = true; });
            const panel = document.querySelector('#' + btn.dataset.tabTarget);
            if (panel) { panel.classList.add('is-active'); panel.hidden = false; }
        };
        tabButtons.forEach((btn, index) => {
            btn.addEventListener('click', () => selectTab(btn));
            btn.addEventListener('keydown', event => {
                if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
                const next = tabButtons[(index + (event.key === 'ArrowRight' ? 1 : -1) + tabButtons.length) % tabButtons.length];
                selectTab(next);
                next.focus();
            });
        });

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
    </script>
    <script src="/assets/js/store/storefront-discovery.js?v=subcat-20261028" defer></script>
</body>
</html>
