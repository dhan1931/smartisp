<?php
// Parte de api/router.php, dividido por dominio (DEV-20261005-015): catalogo, productos admin, import, categorias e imagenes.
// Comparte el scope de router.php via require (no es una funcion): $pdo, $body, $action,
// $method y los helpers (getAuthUser, isAdminUser, requireAdminAuth, etc.) ya estan definidos
// cuando este archivo se incluye. Cada bloque se autogestiona con 'if ($action === ...) { ... exit; }',
// igual que antes; solo cambio donde vive el codigo, no la logica.
//
// El costo (products_rows.cost, migración 041) es solo del panel: normalizeProductRow no lo
// incluye y las respuestas públicas se arman con ella.

const SMARTISP_BULK_MAX_IDS = 500;
const SMARTISP_BULK_SELECT_MAX = 5000;

// -------------------------------------------------------------
// 1. CATÁLOGO PÚBLICO (/api/auth/catalog)
// -------------------------------------------------------------
if ($action === 'catalog' && $method === 'GET') {
    try {
        $pTable = getProductsTableName($pdo);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 36)));
        $offset = ($page - 1) * $limit;
        $search = trim((string)($_GET['q'] ?? ''));
        $category = trim((string)($_GET['category'] ?? ''));
        $subcategory = trim((string)($_GET['subcategory'] ?? ''));
        $categoryListRaw = (string)($_GET['category_list'] ?? '');
        $minPrice = trim((string)($_GET['min_price'] ?? ''));
        $maxPrice = trim((string)($_GET['max_price'] ?? ''));

        $where = 'visible = 1';
        $params = [];
        if ($search !== '') {
            // Búsqueda directa por id/sku (enlaces de producto) o por nombre (DEV-20261005-022: antes el
            // backend ignoraba ?q= y devolvía el primer producto de la tabla en vez del buscado).
            // Placeholders distintos para el mismo valor: con PDO::ATTR_EMULATE_PREPARES=false (prepares
            // nativos) un parámetro nombrado repetido da "SQLSTATE[HY093]: Invalid parameter number".
            $where .= ' AND (id = :search_id OR sku = :search_sku OR name LIKE :search_like)';
            $params[':search_id'] = $search;
            $params[':search_sku'] = $search;
            $params[':search_like'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
        }
        // DEV-20261006-002: antes el filtro por categoria/subcategoria de la tienda publica era
        // 100% del lado del cliente -- forzaba a descargar el catalogo completo (2700+ productos)
        // solo para mostrar una subcategoria de 15. admin-products ya filtraba asi por SQL; se
        // repite el mismo patron aqui para el catalogo publico.
        if ($category !== '') {
            $where .= ' AND category = :category';
            $params[':category'] = $category;
        }
        if ($subcategory !== '') {
            $where .= ' AND subcategory = :subcategory';
            $params[':subcategory'] = $subcategory;
        }
        if ($minPrice !== '' && is_numeric($minPrice) && (float)$minPrice >= 0) {
            $where .= ' AND price >= :min_price';
            $params[':min_price'] = (float)$minPrice;
        }
        if ($maxPrice !== '' && is_numeric($maxPrice) && (float)$maxPrice >= 0) {
            $where .= ' AND price <= :max_price';
            $params[':max_price'] = (float)$maxPrice;
        }
        if ($categoryListRaw !== '') {
            $categoryList = json_decode($categoryListRaw, true);
            $categoryList = is_array($categoryList)
                ? array_values(array_unique(array_filter(array_map(static fn($value) => trim((string)$value), $categoryList), static fn($value) => $value !== '')))
                : [];
            if (!$categoryList) {
                $where .= ' AND 1 = 0';
            } else {
                $placeholders = [];
                foreach ($categoryList as $index => $categoryName) {
                    $placeholder = ':category_list_' . $index;
                    $placeholders[] = $placeholder;
                    $params[$placeholder] = $categoryName;
                }
                $where .= ' AND category IN (' . implode(', ', $placeholders) . ')';
            }
        }

        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM `$pTable` WHERE $where");
        $totalStmt->execute($params);
        $total = (int)$totalStmt->fetchColumn();

        // Las descripciones completas solo hacen falta al abrir una ficha, no en cada página.
        $includeDetails = (string)($_GET['details'] ?? '') === '1';
        $cols = 'id, name, price, category, subcategory, image_url, external_url, sku, visible';
        if ($includeDetails) $cols .= ', description';
        // id como desempate: en los datos reales created_at está vacío en todas las filas, así que sin un
        // criterio estable la paginación podía devolver un producto repetido o saltarse otro entre páginas.
        // ASC (no DESC): hay productos de prueba con id que empieza por "test_"/"prod_test_" e imagen rota;
        // con DESC ordenaban primero por el alfabeto y aparecían arriba del catálogo real.
        $orderBy = 'created_at DESC, id ASC';
        $sort = trim((string)($_GET['sort'] ?? ''));
        // DEV-20261006-002: antes el ordenar por precio era solo en el navegador sobre el catalogo
        // ya cargado; con paginacion real por filtro, el orden tiene que venir del propio SQL para
        // que tenga sentido entre paginas. (price <= 0) primero agrupa "a cotizar" al final siempre,
        // igual que ya hacia el comparador en JS.
        if ($sort === 'low') {
            $orderBy = '(price <= 0) ASC, price ASC, id ASC';
        } elseif ($sort === 'high') {
            $orderBy = '(price <= 0) ASC, price DESC, id ASC';
        }
        $stmt = $pdo->prepare("SELECT $cols FROM `$pTable` WHERE $where ORDER BY $orderBy LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $products = array_map('normalizeProductRow', $stmt->fetchAll());

        // Solo esta lista fija de ajustes públicos: ni logos pesados (van por site-content) ni
        // nada de correo. Antes había además un filtro por prefijos/claves sensibles que, con
        // esta lista, nunca descartaba nada.
        $content = $pdo->query("SELECT setting_key as `key`, setting_value as `value` FROM settings_rows
            WHERE setting_key IN ('hero_title', 'hero_text', 'primary_color', 'footer_text', 'catalog_visible', 'hero_visible', 'benefits_visible',
                'footer_whatsapp', 'footer_email', 'footer_address',
                'footer_social_instagram', 'footer_social_facebook', 'footer_social_youtube', 'footer_social_linkedin')")->fetchAll();

        $categories = $page === 1 ? getDynamicCategoriesList($pdo, true) : [];

        // Catálogo público: caché corta en el navegador/CDN en vez del no-store global (DEV-20261005-022).
        header('Cache-Control: public, max-age=30');

        echo json_encode([
            'products'   => $products,
            'total'      => $total,
            'page'       => $page,
            'limit'      => $limit,
            'totalPages' => (int)ceil($total / max(1, $limit)),
            'content'    => $content,
            'categories' => $categories
        ]);
    } catch (Exception $e) {
        error_log('SmartISP catalog error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo cargar el catálogo. Inténtalo nuevamente.', 'products' => [], 'content' => [], 'categories' => []]);
    }
    exit;
}

// -------------------------------------------------------------
// 2. GESTIÓN DE PRODUCTOS PARA EL EDITOR (/api/auth/admin-products)
// -------------------------------------------------------------

if ($action === 'admin-dashboard-insights') {
    requireAdminAuth();

    $tableExists = static fn(string $table): bool => smartispTableExists($pdo, $table);
    $topProducts = smartispTopProducts($pdo, gmdate('Y-m-d H:i:s', time() - 30 * 86400), gmdate('Y-m-d H:i:s'), 5);

    $inventory = ['tracked_products' => 0, 'available_units' => 0, 'reserved_units' => 0, 'out_of_stock' => 0];
    if ($tableExists('product_inventory')) {
        $row = $pdo->query("SELECT COUNT(*) AS tracked_products,
                COALESCE(SUM(GREATEST(CAST(quantity_available AS SIGNED) - CAST(quantity_reserved AS SIGNED), 0)), 0) AS available_units,
                COALESCE(SUM(quantity_reserved), 0) AS reserved_units,
                COALESCE(SUM(quantity_available <= quantity_reserved), 0) AS out_of_stock
            FROM product_inventory")->fetch(PDO::FETCH_ASSOC) ?: [];
        $inventory = array_map('intval', $row);
    }

    echo json_encode([
        'period_days' => 30,
        'top_products' => $topProducts,
        'inventory' => $inventory,
        'inventory_configured' => $inventory['tracked_products'] > 0,
    ]);
    exit;
}

if ($action === 'admin-dashboard-stats') {
    requireAdminAuth();
    $pTable = getProductsTableName($pdo);
    $productStats = $pdo->query("SELECT COUNT(*) AS total, SUM(visible = 1) AS visible
        FROM `$pTable`")->fetch(PDO::FETCH_ASSOC) ?: [];
    $categoryCount = (int)$pdo->query('SELECT COUNT(*) FROM categories_rows')->fetchColumn();
    $customerCount = (int)$pdo->query("SELECT COUNT(*) FROM users_rows
        WHERE COALESCE(LOWER(TRIM(role)), 'customer') NOT IN ('admin', 'administrator', 'administrador')")->fetchColumn();

    echo json_encode([
        'products' => (int)($productStats['total'] ?? 0),
        'visible_products' => (int)($productStats['visible'] ?? 0),
        'categories' => $categoryCount,
        'customers' => $customerCount,
    ]);
    exit;
}

if ($action === 'admin-products-stats') {
    requireAdminAuth();
    $pTable = getProductsTableName($pdo);
    ensureProductTableColumns($pdo, $pTable);

    $totals = $pdo->query("SELECT COUNT(*) AS total,
        SUM(visible = 1) AS visible_count,
        SUM(image_url IS NOT NULL AND image_url != '') AS with_photo,
        SUM(image_url IS NULL OR image_url = '') AS without_photo
        FROM `$pTable`")->fetch(PDO::FETCH_ASSOC) ?: [];

    $byCategory = $pdo->query("SELECT COALESCE(NULLIF(TRIM(category), ''), 'General') AS category, COUNT(*) AS total
        FROM `$pTable` GROUP BY category ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'total'         => (int)($totals['total'] ?? 0),
        'visible'       => (int)($totals['visible_count'] ?? 0),
        'with_photo'    => (int)($totals['with_photo'] ?? 0),
        'without_photo' => (int)($totals['without_photo'] ?? 0),
        'by_category'   => array_map(fn($r) => ['category' => $r['category'], 'total' => (int)$r['total']], $byCategory),
    ]);
    exit;
}


if ($action === 'admin-products') {
    requireAdminAuth();
    $pTable = getProductsTableName($pdo);

    if ($method === 'GET') {
        // Paginado y filtrado real en SQL (DEV-20261005-022, panel admin): la tabla llegó a
        // 2773 filas y crece; cargar todo de una sin límite no se sostiene. Mismos filtros
        // que ya aplicaba el front en memoria (getFilteredProducts), movidos al servidor.
        ensureProductTableColumns($pdo, $pTable);

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(200, max(1, (int)($_GET['limit'] ?? 50))); // empezar chico (50) y permitir subir, no saltar a 500
        $offset = ($page - 1) * $limit;

        $where = ['1=1'];
        $params = [];

        $category = trim((string)($_GET['category'] ?? ''));
        if ($category !== '' && $category !== 'all') {
            $where[] = 'category = :category';
            $params[':category'] = $category;
        }
        $family = trim((string)($_GET['family'] ?? ''));
        if ($family !== '' && $family !== 'all') {
            // Las tablas importadas pueden tener collations distintas. Comparar en la
            // misma evita el error 1267 al enlazar los nombres de categoria.
            $sameCategory = "CONVERT(c.name USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(`$pTable`.category USING utf8mb4) COLLATE utf8mb4_unicode_ci";
            if ($family === 'ungrouped') {
                $where[] = "NOT EXISTS (SELECT 1 FROM categories_rows c WHERE $sameCategory AND c.macro_group_id IS NOT NULL AND c.macro_group_id <> '')";
            } else {
                $where[] = "EXISTS (SELECT 1 FROM categories_rows c WHERE $sameCategory AND c.macro_group_id = :family)";
                $params[':family'] = $family;
            }
        }
        $subcategory = trim((string)($_GET['subcategory'] ?? ''));
        if ($subcategory !== '' && $subcategory !== 'all') {
            $where[] = 'subcategory = :subcategory';
            $params[':subcategory'] = $subcategory;
        }
        $visible = trim((string)($_GET['visible'] ?? ''));
        if ($visible === 'visible') $where[] = 'visible = 1';
        elseif ($visible === 'hidden') $where[] = 'visible = 0';

        $photo = trim((string)($_GET['photo'] ?? ''));
        if ($photo === 'with_photo') $where[] = "(image_url IS NOT NULL AND image_url != '')";
        elseif ($photo === 'without_photo') $where[] = "(image_url IS NULL OR image_url = '')";

        $price = trim((string)($_GET['price'] ?? ''));
        if ($price === 'with_price') $where[] = '(price IS NOT NULL AND price > 0)';
        elseif ($price === 'quote') $where[] = '(price IS NULL OR price <= 0)';

        $search = trim((string)($_GET['q'] ?? ''));
        if ($search !== '') {
            $where[] = '(name LIKE :q_name OR sku LIKE :q_sku OR category LIKE :q_cat OR subcategory LIKE :q_sub OR description LIKE :q_desc)';
            $likeSearch = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $params[':q_name'] = $likeSearch;
            $params[':q_sku'] = $likeSearch;
            $params[':q_cat'] = $likeSearch;
            $params[':q_sub'] = $likeSearch;
            $params[':q_desc'] = $likeSearch;
        }

        $whereSql = implode(' AND ', $where);
        $hasCost = in_array('cost', smartispTableColumns($pdo, $pTable), true);

        // compact=1: todas las filas que cumplen el filtro, con pocos campos, para "seleccionar todos
        // los filtrados" en las acciones masivas.
        if ((string)($_GET['compact'] ?? '') === '1') {
            $stmt = $pdo->prepare("SELECT id, name, sku, category, subcategory, price, visible" . ($hasCost ? ', cost' : '') . " FROM `$pTable` WHERE $whereSql ORDER BY created_at DESC, id ASC LIMIT " . SMARTISP_BULK_SELECT_MAX);
            $stmt->execute($params);
            $rows = array_map(static fn($r) => ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'sku' => (string)($r['sku'] ?? ''), 'category' => (string)($r['category'] ?? ''),
                'subcategory' => (string)($r['subcategory'] ?? ''), 'price' => (float)$r['price'], 'visible' => (int)$r['visible'] === 1,
                'cost' => isset($r['cost']) ? (float)$r['cost'] : null], $stmt->fetchAll(PDO::FETCH_ASSOC));
            echo json_encode(['products' => $rows, 'truncated' => count($rows) >= SMARTISP_BULK_SELECT_MAX, 'max' => SMARTISP_BULK_SELECT_MAX]);
            exit;
        }

        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM `$pTable` WHERE $whereSql");
        $totalStmt->execute($params);
        $total = (int)$totalStmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM `$pTable` WHERE $whereSql ORDER BY created_at DESC, id ASC LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rawRows = $stmt->fetchAll();
        $products = array_map('normalizeProductRow', $rawRows);

        // Solo para el panel: costo y stock de la página (normalizeProductRow es lo público).
        $stock = [];
        $ids = array_column($products, 'id');
        if ($ids && smartispTableExists($pdo, 'product_inventory')) {
            $invStmt = $pdo->prepare('SELECT product_id, CAST(quantity_available AS SIGNED) AS available, CAST(quantity_reserved AS SIGNED) AS reserved
                FROM product_inventory WHERE product_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $invStmt->execute($ids);
            foreach ($invStmt->fetchAll(PDO::FETCH_ASSOC) as $inv) $stock[(string)$inv['product_id']] = $inv;
        }
        foreach ($products as $i => &$product) {
            $product['cost'] = $hasCost && isset($rawRows[$i]['cost']) ? (float)$rawRows[$i]['cost'] : null;
            $inv = $stock[$product['id']] ?? null;
            $product['stock'] = $inv ? ['available' => (int)$inv['available'], 'reserved' => (int)$inv['reserved'], 'free' => max(0, (int)$inv['available'] - (int)$inv['reserved'])] : null;
        }
        unset($product);

        echo json_encode([
            'products'   => $products,
            'total'      => $total,
            'page'       => $page,
            'limit'      => $limit,
            'totalPages' => (int)ceil($total / max(1, $limit)),
        ]);
        exit;
    }

    if ($method === 'POST') {
        ensureProductTableColumns($pdo, $pTable);

        $id = trim((string)($body['id'] ?? ''));
        if (empty($id) || $id === 'modal-draft') {
            $id = 'prod_' . bin2hex(random_bytes(7));
        }

        $name = trim($body['name'] ?? '');
        $description = trim($body['description'] ?? '');
        $rawPrice = $body['price'] ?? 0;
        if ($rawPrice === '' || $rawPrice === null) $rawPrice = 0;
        if (!is_numeric($rawPrice) || (float)$rawPrice < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'El precio debe ser un número mayor o igual a 0.']);
            exit;
        }
        $price = (float)$rawPrice;
        $category = trim($body['category'] ?? 'General');
        if (empty($category)) $category = 'General';
        $subcategory = trim($body['subcategory'] ?? '');
        $imageUrl = trim($body['imageUrl'] ?? ($body['image_url'] ?? ''));

        // Si la imagen es un Data URL base64, guardarla automáticamente como archivo en uploads/products/
        if (strpos($imageUrl, 'data:image/') === 0 && preg_match('/^data:image\/(\w+);base64,(.+)$/', $imageUrl, $m)) {
            $ext = strtolower($m[1]) === 'png' ? 'png' : (strtolower($m[1]) === 'webp' ? 'webp' : 'jpg');
            $bData = base64_decode($m[2]);
            if ($bData && strlen($bData) < 15 * 1024 * 1024) {
                $uploadDir = getSmartispUploadsDir('products');
                $fn = 'prod_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (@file_put_contents($uploadDir . '/' . $fn, $bData) !== false) {
                    $imageUrl = '/api/auth/serve-upload?category=products&file=' . rawurlencode($fn);
                }
            }
        }

        $externalUrl = trim($body['externalUrl'] ?? ($body['external_url'] ?? ''));
        $sku = trim($body['sku'] ?? '');
        $visible = isset($body['visible']) ? ($body['visible'] ? 1 : 0) : 1;
        // Specs estructuradas (clave/valor), contenido del paquete y garantia: opcionales, solo
        // se guardan si el admin las completo. No se inventan ni se derivan de nada.
        $specsInput = $body['specs'] ?? null;
        if (is_string($specsInput)) {
            $decoded = json_decode($specsInput, true);
            $specsInput = is_array($decoded) ? $decoded : null;
        }
        $specsJson = null;
        if (is_array($specsInput)) {
            $cleanSpecs = [];
            foreach ($specsInput as $spec) {
                $label = trim((string)($spec['label'] ?? ''));
                $value = trim((string)($spec['value'] ?? ''));
                if ($label !== '' && $value !== '') $cleanSpecs[] = ['label' => $label, 'value' => $value];
            }
            if ($cleanSpecs) $specsJson = json_encode($cleanSpecs, JSON_UNESCAPED_UNICODE);
        }
        $contentText = trim((string)($body['content_text'] ?? ($body['contentText'] ?? ''))) ?: null;
        $warrantyText = trim((string)($body['warranty_text'] ?? ($body['warrantyText'] ?? ''))) ?: null;
        // Costo opcional: solo se toca si viene en el cuerpo; vacío lo borra (NULL = sin costo).
        $hasCost = in_array('cost', smartispTableColumns($pdo, $pTable), true);
        $costSent = $hasCost && array_key_exists('cost', $body);
        $cost = null;
        if ($costSent) {
            $rawCost = is_string($body['cost']) ? trim($body['cost']) : $body['cost'];
            if ($rawCost !== '' && $rawCost !== null) {
                if (!is_numeric($rawCost) || (float)$rawCost < 0 || (float)$rawCost > 99999999) {
                    http_response_code(400);
                    echo json_encode(['error' => 'El costo debe ser un número mayor o igual a 0.']);
                    exit;
                }
                $cost = round((float)$rawCost, 2);
            }
        }

        if (!$name) {
            http_response_code(400);
            echo json_encode(['error' => 'El nombre del producto es obligatorio.']);
            exit;
        }

        smartispEnsureCategory($pdo, (string)$category, (string)$subcategory);

        // Comprobar si ya existe por id o por SKU
        $checkStmt = $pdo->prepare("SELECT id FROM `$pTable` WHERE id = :id LIMIT 1");
        $checkStmt->execute([':id' => $id]);
        $existingId = $checkStmt->fetchColumn();

        if (!$existingId && !empty($sku)) {
            $chkSku = $pdo->prepare("SELECT id FROM `$pTable` WHERE sku = :sku AND sku != '' LIMIT 1");
            $chkSku->execute([':sku' => $sku]);
            $existingId = $chkSku->fetchColumn();
        }

        // Si la imagen enviada es la URL del proxy, conservar la URL real original
        if (strpos($imageUrl, '/api/auth/product-image') !== false || strpos($imageUrl, '/api/auth/proxy-image') !== false) {
            $extractedReal = null;
            if (preg_match('/[?&]t=([A-Za-z0-9_-]+)/', $imageUrl, $m)) {
                $decoded = base64_decode(strtr($m[1], '-_', '+/'));
                if ($decoded && filter_var($decoded, FILTER_VALIDATE_URL)) {
                    $extractedReal = $decoded;
                }
            }
            if ($extractedReal) {
                $imageUrl = $extractedReal;
            } elseif ($existingId) {
                $curImgStmt = $pdo->prepare("SELECT * FROM `$pTable` WHERE id = :id LIMIT 1");
                $curImgStmt->execute([':id' => $existingId]);
                $curRow = $curImgStmt->fetch(PDO::FETCH_ASSOC);
                if ($curRow) {
                    $curImg = (string)($curRow['image_url'] ?? ($curRow['imageUrl'] ?? ($curRow['imagen'] ?? ($curRow['foto'] ?? ''))));
                    if (!empty($curImg) && strpos($curImg, '/api/auth/') !== 0) {
                        $imageUrl = $curImg;
                    }
                }
            }
        }

        $prodSlug = slugify($name);

        if ($existingId) {
            // Los campos opcionales solo se tocan si vienen en el cuerpo: guardados parciales
            // (p. ej. el interruptor de visibilidad) no deben borrar specs, garantía ni enlace.
            $sent = static fn(string ...$keys): bool => (bool)array_filter($keys, static fn($k) => array_key_exists($k, $body));
            $optional = [
                'external_url'  => [$sent('externalUrl', 'external_url'), $externalUrl],
                'specs_json'    => [$sent('specs'), $specsJson],
                'content_text'  => [$sent('content_text', 'contentText'), $contentText],
                'warranty_text' => [$sent('warranty_text', 'warrantyText'), $warrantyText],
                'cost'          => [$costSent, $cost],
            ];
            $sets = ['name = :name', 'description = :description', 'price = :price', 'category = :category',
                'subcategory = :subcategory', 'image_url = :image_url', 'sku = :sku', 'visible = :visible'];
            $params = [
                ':id'           => $existingId,
                ':name'         => $name,
                ':description'  => $description,
                ':price'        => $price,
                ':category'     => $category,
                ':subcategory'  => $subcategory,
                ':image_url'    => $imageUrl,
                ':sku'          => $sku,
                ':visible'      => $visible,
            ];
            foreach ($optional as $column => [$present, $value]) {
                if (!$present) continue;
                $sets[] = "$column = :$column";
                $params[":$column"] = $value;
            }
            $stmt = $pdo->prepare("UPDATE `$pTable` SET " . implode(', ', $sets) . ", updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute($params);
            $publicUrl = '/producto/' . $existingId . '-' . $prodSlug;
            echo json_encode([
                'ok'      => true,
                'id'      => $existingId,
                'name'    => $name,
                'url'     => $publicUrl,
                'message' => 'Producto actualizado con éxito.'
            ]);
        } else {
            $sql = "INSERT INTO `$pTable` (id, name, description, price, category, subcategory, image_url, external_url, sku, visible, specs_json, content_text, warranty_text" . ($hasCost ? ', cost' : '') . ")
                    VALUES (:id, :name, :description, :price, :category, :subcategory, :image_url, :external_url, :sku, :visible, :specs_json, :content_text, :warranty_text" . ($hasCost ? ', :cost' : '') . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(($hasCost ? [':cost' => $cost] : []) + [
                ':id'           => $id,
                ':name'         => $name,
                ':description'  => $description,
                ':price'        => $price,
                ':category'     => $category,
                ':subcategory'  => $subcategory,
                ':image_url'    => $imageUrl,
                ':external_url' => $externalUrl,
                ':sku'          => $sku,
                ':visible'      => $visible,
                ':specs_json'   => $specsJson,
                ':content_text' => $contentText,
                ':warranty_text' => $warrantyText
            ]);
            $publicUrl = '/producto/' . $id . '-' . $prodSlug;
            echo json_encode([
                'ok'      => true,
                'id'      => $id,
                'name'    => $name,
                'url'     => $publicUrl,
                'message' => 'Producto registrado y publicado con éxito.'
            ]);
        }
        smartispNotifySearchEngines([$publicUrl]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? ($body['id'] ?? '');
        if ($id) {
            $stmt = $pdo->prepare("DELETE FROM `$pTable` WHERE id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'ID requerido.']);
        }
        exit;
    }
}

// -------------------------------------------------------------
// 2.0b GALERÍA DE IMÁGENES POR PRODUCTO (/api/auth/product-images)
// -------------------------------------------------------------
if ($action === 'product-images') {
    if ($method === 'GET') {
        $productId = trim((string)($_GET['product_id'] ?? ''));
        if ($productId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'product_id requerido.']);
            exit;
        }
        $stmt = $pdo->prepare('SELECT id, image_url, image_alt, sort_order, is_primary FROM product_images WHERE product_id = :pid ORDER BY sort_order, id');
        $stmt->execute([':pid' => $productId]);
        echo json_encode(['images' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    requireAdminAuth();

    if ($method === 'POST') {
        $productId = trim((string)($body['product_id'] ?? ''));
        $imageUrl = trim((string)($body['image_url'] ?? ''));
        if ($productId === '' || $imageUrl === '') {
            http_response_code(400);
            echo json_encode(['error' => 'product_id e image_url son obligatorios.']);
            exit;
        }
        $pTable = getProductsTableName($pdo);
        $chk = $pdo->prepare("SELECT id FROM `$pTable` WHERE id = :id LIMIT 1");
        $chk->execute([':id' => $productId]);
        if (!$chk->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['error' => 'Producto no encontrado.']);
            exit;
        }
        $orderStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = :pid');
        $orderStmt->execute([':pid' => $productId]);
        $nextOrder = (int)$orderStmt->fetchColumn();
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = :pid');
        $countStmt->execute([':pid' => $productId]);
        $isFirst = (int)$countStmt->fetchColumn() === 0;
        $ins = $pdo->prepare('INSERT INTO product_images (product_id, image_url, image_alt, sort_order, is_primary) VALUES (:pid, :url, :alt, :sort, :primary)');
        $ins->execute([
            ':pid' => $productId,
            ':url' => substr($imageUrl, 0, 500),
            ':alt' => trim((string)($body['image_alt'] ?? '')) ?: null,
            ':sort' => $nextOrder,
            ':primary' => $isFirst ? 1 : 0
        ]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($method === 'DELETE') {
        $imageId = (int)($_GET['id'] ?? ($body['id'] ?? 0));
        if (!$imageId) {
            http_response_code(400);
            echo json_encode(['error' => 'id requerido.']);
            exit;
        }
        $pdo->prepare('DELETE FROM product_images WHERE id = :id')->execute([':id' => $imageId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($method === 'PUT') {
        // Reordenar / marcar portada: body = { ids: [id_en_orden...], primary_id: id }
        $ids = $body['ids'] ?? null;
        if (is_array($ids)) {
            $upd = $pdo->prepare('UPDATE product_images SET sort_order = :sort WHERE id = :id');
            foreach ($ids as $index => $imgId) {
                $upd->execute([':sort' => $index, ':id' => (int)$imgId]);
            }
        }
        $primaryId = (int)($body['primary_id'] ?? 0);
        if ($primaryId && is_array($ids) && in_array($primaryId, array_map('intval', $ids), true)) {
            $productId = trim((string)($body['product_id'] ?? ''));
            if ($productId !== '') {
                $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = :pid')->execute([':pid' => $productId]);
                $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = :id')->execute([':id' => $primaryId]);
            }
        }
        echo json_encode(['ok' => true]);
        exit;
    }
}

// -------------------------------------------------------------
// 2.1 SUBIDA DE IMÁGENES DE PRODUCTOS (/api/auth/upload-image)
// -------------------------------------------------------------
if ($action === 'upload-image' && $method === 'POST') {
    requireAdminAuth();

    // Igual que uploads/campaigns (ver getSmartispUploadsDir): fuera de public_html a proposito,
    // cada deploy reconstruye dist/ desde cero y borraba estas fotos cuando vivian dentro del repo.
    $uploadDir = getSmartispUploadsDir('products');

    $fileData = null;
    $ext = 'jpg';

    // 1. Caso archivo subido por multipart/form-data
    if (!empty($_FILES['image']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $fileInfo = @getimagesize($_FILES['image']['tmp_name']);
        if (!$fileInfo) {
            http_response_code(400);
            echo json_encode(['error' => 'El archivo subido no es una imagen válida.']);
            exit;
        }
        $mime = $fileInfo['mime'] ?? '';
        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg'
        };
        $fileData = file_get_contents($_FILES['image']['tmp_name']);
    }
    // 2. Caso Data URL / Base64 enviado por JSON o POST
    elseif (!empty($body['image']) && is_string($body['image'])) {
        $raw = $body['image'];
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $raw, $m)) {
            $ext = strtolower($m[1]) === 'png' ? 'png' : (strtolower($m[1]) === 'webp' ? 'webp' : 'jpg');
            $fileData = base64_decode($m[2]);
        }
    }

    if (!$fileData) {
        http_response_code(400);
        echo json_encode(['error' => 'No se recibió ninguna imagen para subir.']);
        exit;
    }

    if (strlen($fileData) > 8 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'La imagen supera el límite de 8 MB.']);
        exit;
    }

    $filename = 'prod_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = $uploadDir . '/' . $filename;

    if (@file_put_contents($targetPath, $fileData) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo guardar la imagen en el servidor (permisos de carpeta).']);
        exit;
    }

    $publicUrl = '/api/auth/serve-upload?category=products&file=' . rawurlencode($filename);
    echo json_encode([
        'ok' => true,
        'url' => $publicUrl,
        'filename' => $filename
    ]);
    exit;
}

// -------------------------------------------------------------
// 3. IMPORTACIÓN MASIVA Y LOTES (/api/auth/admin-products-bulk, /api/auth/import-products)
// -------------------------------------------------------------
if ($action === 'import-products' || $action === 'import-excel' || $action === 'admin-products-bulk' || $action === 'import-catalog' || $action === 'save-products-batch') {
    smartispRequirePost();
    requireAdminAuth();
    $pTable = getProductsTableName($pdo);
    ensureProductTableColumns($pdo, $pTable);
    $products = $body['products'] ?? ($body['items'] ?? ($_POST['products'] ?? []));

    // Si products vino como cadena JSON (por ejemplo enviado mediante multipart/FormData)
    if (is_string($products) && !empty($products)) {
        $parsed = json_decode($products, true);
        if (is_array($parsed)) {
            $products = $parsed['products'] ?? ($parsed['items'] ?? $parsed);
        }
    }

    // Procesamiento de datos en lote si se enviaron codificados
    $rawPayload = $body['payload'] ?? ($_POST['payload'] ?? '');
    if ((!is_array($products) || empty($products)) && !empty($rawPayload) && is_string($rawPayload)) {
        $decoded = @base64_decode($rawPayload);
        if ($decoded !== false) {
            $unpacked = json_decode($decoded, true);
            if (is_array($unpacked)) {
                $products = $unpacked['products'] ?? ($unpacked['items'] ?? []);
                if (empty($products) && isset($unpacked[0]) && is_array($unpacked[0])) {
                    $products = $unpacked;
                }
            }
        }
    }

    if (!is_array($products) || empty($products)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'No se proporcionaron productos para importar.',
            'keys' => is_array($body) ? array_keys($body) : gettype($body)
        ]);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $checkStmtId = $pdo->prepare("SELECT id FROM `$pTable` WHERE id = :id LIMIT 1");
        $checkStmtSku = $pdo->prepare("SELECT id FROM `$pTable` WHERE sku = :sku AND sku != '' LIMIT 1");
        // Por nombre solo si es único: con dos productos homónimos se actualizaba uno cualquiera.
        $checkStmtName = $pdo->prepare("SELECT id FROM `$pTable` WHERE name = :name AND name != '' LIMIT 2");

        $hasCost = in_array('cost', smartispTableColumns($pdo, $pTable), true);
        $insertStmt = $pdo->prepare("INSERT INTO `$pTable` (id, name, description, price, category, subcategory, image_url, external_url, sku, visible" . ($hasCost ? ', cost' : '') . ")
                VALUES (:id, :name, :description, :price, :category, :subcategory, :image_url, :external_url, :sku, :visible" . ($hasCost ? ', :cost' : '') . ")");

        // Columna -> claves aceptadas en la fila. Al actualizar solo se tocan las presentes, y un
        // texto vacío (celda vacía en el archivo) no borra lo que ya había.
        $importColumns = [
            'description'  => ['description'],
            'price'        => ['price'],
            'category'     => ['category'],
            'subcategory'  => ['subcategory'],
            'image_url'    => ['imageUrl', 'image_url'],
            'external_url' => ['externalUrl', 'external_url'],
            'sku'          => ['sku'],
            'visible'      => ['visible'],
        ];
        if ($hasCost) $importColumns['cost'] = ['cost', 'costo'];

        $inserted = 0;
        $errors = [];
        $changedPaths = [];
        foreach (array_values($products) as $index => $p) {
            $rowNumber = $index + 1;
            $name = is_array($p) ? trim((string)($p['name'] ?? '')) : '';
            if ($name === '') {
                $errors[] = ['row' => $rowNumber, 'name' => '', 'error' => 'Falta el nombre del producto.'];
                continue;
            }

            $values = [];
            foreach ($importColumns as $column => $keys) {
                foreach ($keys as $key) {
                    if (array_key_exists($key, $p)) {
                        $values[$column] = $p[$key];
                        break;
                    }
                }
            }
            if (array_key_exists('price', $values)) {
                $rawPrice = is_string($values['price']) ? trim($values['price']) : $values['price'];
                if ($rawPrice === '' || $rawPrice === null) {
                    unset($values['price']);
                } elseif (!is_numeric($rawPrice) || (float)$rawPrice < 0) {
                    $errors[] = ['row' => $rowNumber, 'name' => $name, 'error' => 'Precio inválido: debe ser un número mayor o igual a 0.'];
                    continue;
                } else {
                    $values['price'] = (float)$rawPrice;
                }
            }
            if (array_key_exists('cost', $values)) {
                $rawCost = is_string($values['cost']) ? trim($values['cost']) : $values['cost'];
                if ($rawCost === '' || $rawCost === null) {
                    unset($values['cost']);
                } elseif (!is_numeric($rawCost) || (float)$rawCost < 0 || (float)$rawCost > 99999999) {
                    $errors[] = ['row' => $rowNumber, 'name' => $name, 'error' => 'Costo inválido: debe ser un número mayor o igual a 0.'];
                    continue;
                } else {
                    $values['cost'] = round((float)$rawCost, 2);
                }
            }
            foreach (['description', 'category', 'subcategory', 'image_url', 'external_url', 'sku'] as $column) {
                if (!array_key_exists($column, $values)) continue;
                $values[$column] = trim((string)$values[$column]);
                if ($values[$column] === '') unset($values[$column]);
            }
            if (array_key_exists('visible', $values)) $values['visible'] = $values['visible'] ? 1 : 0;

            $rawId = trim((string)($p['id'] ?? ''));
            $id = (!empty($rawId) && $rawId !== 'modal-draft') ? $rawId : ('prod_' . bin2hex(random_bytes(7)));
            $cat = $values['category'] ?? 'General';
            $subcat = $values['subcategory'] ?? '';
            $sku = $values['sku'] ?? '';

            smartispEnsureCategory($pdo, (string)$cat, (string)$subcat);

            // Identificar si el producto ya existe (por id, sku o nombre único)
            $existingId = null;
            if (!empty($rawId)) {
                $checkStmtId->execute([':id' => $rawId]);
                $existingId = $checkStmtId->fetchColumn();
            }
            if (!$existingId && $sku !== '') {
                $checkStmtSku->execute([':sku' => $sku]);
                $existingId = $checkStmtSku->fetchColumn();
            }
            if (!$existingId) {
                $checkStmtName->execute([':name' => $name]);
                $sameName = $checkStmtName->fetchAll(PDO::FETCH_COLUMN);
                $existingId = count($sameName) === 1 ? $sameName[0] : null;
            }

            try {
                if ($existingId) {
                    $sets = ['name = :name'];
                    $params = [':id' => $existingId, ':name' => $name];
                    foreach ($values as $column => $value) {
                        $sets[] = "$column = :$column";
                        $params[":$column"] = $value;
                    }
                    $pdo->prepare("UPDATE `$pTable` SET " . implode(', ', $sets) . ", updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute($params);
                } else {
                    $insertStmt->execute(($hasCost ? [':cost' => $values['cost'] ?? null] : []) + [
                        ':id'           => $id,
                        ':name'         => $name,
                        ':description'  => $values['description'] ?? '',
                        ':price'        => $values['price'] ?? 0,
                        ':category'     => $cat,
                        ':subcategory'  => $subcat,
                        ':image_url'    => $values['image_url'] ?? '',
                        ':external_url' => $values['external_url'] ?? '',
                        ':sku'          => $sku,
                        ':visible'      => $values['visible'] ?? 1
                    ]);
                }
            } catch (PDOException $e) {
                error_log('SmartISP import row error: ' . $e->getMessage());
                $errors[] = ['row' => $rowNumber, 'name' => $name, 'error' => 'No se pudo guardar la fila.'];
                continue;
            }
            $inserted++;
            $changedPaths[] = '/producto/' . ($existingId ?: $id) . '-' . slugify($name);
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'count' => $inserted, 'errors' => $errors]);
        smartispNotifySearchEngines(array_slice($changedPaths, 0, 10000));
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('SmartISP import error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Error en la importación. Revisa el archivo e inténtalo nuevamente.']);
    }
    exit;
}

if ($action === 'admin-products-clear') {
    smartispRequirePost();
    requireAdminAuth();
    $pTable = getProductsTableName($pdo);
    $pdo->exec("DELETE FROM `$pTable`");
    echo json_encode(['ok' => true, 'cleared' => true]);
    exit;
}

// -------------------------------------------------------------
// 3b. ACCIONES MASIVAS (POST /api/auth/admin-products-bulk-update)
// -------------------------------------------------------------
// {ids, op, ...}. Cada operación toca solo sus columnas:
//   visibility  {visible}
//   category    {category, subcategory}
//   price       {mode: percent|amount|set, value}; percent y amount no tocan productos a cotizar
//   track_stock {track}
// dry_run devuelve el antes/después del precio sin guardar.
if ($action === 'admin-products-bulk-update') {
    smartispRequirePost();
    requireAdminAuth();
    $pTable = getProductsTableName($pdo);
    $fail = static function (int $status, string $message) use ($pdo): void {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code($status);
        echo json_encode(['error' => $message]);
        exit;
    };
    $ids = $body['ids'] ?? [];
    if (is_string($ids)) $ids = json_decode($ids, true);
    $ids = is_array($ids) ? array_values(array_unique(array_filter(array_map(static fn($v) => is_scalar($v) ? trim((string)$v) : '', $ids), static fn($v) => $v !== ''))) : [];
    if (!$ids) $fail(400, 'Selecciona al menos un producto.');
    if (count($ids) > SMARTISP_BULK_MAX_IDS) $fail(400, 'Máximo ' . SMARTISP_BULK_MAX_IDS . ' productos por operación.');
    $op = (string)($body['op'] ?? '');
    if (!in_array($op, ['visibility', 'category', 'price', 'track_stock'], true)) $fail(400, 'Operación no permitida.');
    $dryRun = !empty($body['dry_run']) && $body['dry_run'] !== '0';

    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name, price FROM `$pTable` WHERE id IN ($in)");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $foundIds = array_map('strval', array_column($rows, 'id'));
    $skipped = array_map(static fn($id) => ['id' => $id, 'name' => '', 'reason' => 'El producto ya no existe.'], array_values(array_diff($ids, $foundIds)));
    $updated = 0;
    $changes = [];

    $pdo->beginTransaction();
    try {
        if ($op === 'visibility') {
            $visible = !empty($body['visible']) && $body['visible'] !== '0' ? 1 : 0;
            if (!$dryRun && $foundIds) {
                $upd = $pdo->prepare("UPDATE `$pTable` SET visible = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN (" . implode(',', array_fill(0, count($foundIds), '?')) . ')');
                $upd->execute(array_merge([$visible], $foundIds));
            }
            $updated = count($foundIds);
        } elseif ($op === 'category') {
            $category = trim((string)($body['category'] ?? ''));
            $subcategory = trim((string)($body['subcategory'] ?? ''));
            if ($category === '' || mb_strlen($category) > 100 || mb_strlen($subcategory) > 100) $fail(400, 'Elige una categoría válida.');
            if (!$dryRun && $foundIds) {
                smartispEnsureCategory($pdo, $category, $subcategory);
                $upd = $pdo->prepare("UPDATE `$pTable` SET category = ?, subcategory = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN (" . implode(',', array_fill(0, count($foundIds), '?')) . ')');
                $upd->execute(array_merge([$category, $subcategory], $foundIds));
            }
            $updated = count($foundIds);
        } elseif ($op === 'price') {
            $mode = (string)($body['mode'] ?? '');
            $value = $body['value'] ?? null;
            if (!in_array($mode, ['percent', 'amount', 'set'], true) || !is_numeric($value)) $fail(400, 'Indica el tipo de ajuste y un valor numérico.');
            $value = (float)$value;
            if ($mode === 'percent' && ($value <= -100 || $value > 1000)) $fail(400, 'El porcentaje debe estar entre -99 y 1000.');
            if (abs($value) > 1000000 || ($mode === 'set' && $value < 0)) $fail(400, 'El valor del ajuste no es válido.');
            foreach ($rows as $r) {
                $before = round((float)$r['price'], 2);
                if ($mode !== 'set' && $before <= 0) {
                    $skipped[] = ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'reason' => 'Sin precio (a cotizar).'];
                    continue;
                }
                $after = round(max(0, $mode === 'set' ? $value : ($mode === 'percent' ? $before * (1 + $value / 100) : $before + $value)), 2);
                if ($mode !== 'set' && $after <= 0) {
                    $skipped[] = ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'reason' => 'El precio quedaría en 0 o menos.'];
                    continue;
                }
                $changes[] = ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'before' => $before, 'after' => $after];
            }
            if (!$dryRun) {
                $upd = $pdo->prepare("UPDATE `$pTable` SET price = :price, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                foreach ($changes as $c) $upd->execute([':price' => $c['after'], ':id' => $c['id']]);
            }
            $updated = count($changes);
        } else {
            if (!smartispTableExists($pdo, 'product_inventory')) $fail(409, 'Falta la tabla product_inventory: aplica las migraciones (php scripts/migrate.php).');
            $track = !empty($body['track']) && $body['track'] !== '0';
            sort($foundIds, SORT_STRING);
            if ($track) {
                $ins = $pdo->prepare('INSERT IGNORE INTO product_inventory (product_id, quantity_available) VALUES (:id, 0)');
                foreach ($foundIds as $id) {
                    if ($dryRun) continue;
                    $ins->execute([':id' => $id]);
                    $updated += $ins->rowCount();
                }
            } else {
                $names = array_column($rows, 'name', 'id');
                $lock = $pdo->prepare('SELECT CAST(quantity_available AS SIGNED) AS available, CAST(quantity_reserved AS SIGNED) AS reserved FROM product_inventory WHERE product_id = :id FOR UPDATE');
                $del = $pdo->prepare('DELETE FROM product_inventory WHERE product_id = :id AND quantity_reserved = 0');
                foreach ($foundIds as $id) {
                    $lock->execute([':id' => $id]);
                    $inv = $lock->fetch(PDO::FETCH_ASSOC);
                    if (!$inv) continue;
                    if ((int)$inv['reserved'] > 0) {
                        $skipped[] = ['id' => $id, 'name' => (string)($names[$id] ?? ''), 'reason' => 'Tiene ' . (int)$inv['reserved'] . ' unidad(es) reservada(s) por pedidos en curso.'];
                        continue;
                    }
                    if (!$dryRun) {
                        $del->execute([':id' => $id]);
                        smartispRecordStockMovement($pdo, $id, [(int)$inv['available'], 0], [0, 0], 'untracked', null, 'Acción masiva');
                    }
                    $updated++;
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('SmartISP admin-products-bulk-update: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo aplicar la acción masiva. No se guardó ningún cambio.']);
        exit;
    }

    echo json_encode(['ok' => true, 'op' => $op, 'dryRun' => $dryRun, 'requested' => count($ids), 'updated' => $updated,
        'skipped' => $skipped, 'changes' => array_slice($changes, 0, 200)]);
    exit;
}

// -------------------------------------------------------------
// 4. CONFIGURACIONES DEL PANEL DE CONTROL (/api/auth/admin-content)
// -------------------------------------------------------------

// -------------------------------------------------------------
if ($action === 'categories') {
    requireAdminAuth();

    if ($method === 'GET') {
        $cats = getDynamicCategoriesList($pdo);
        echo json_encode(['categories' => $cats, 'macroGroups' => getCategoryMacroGroups($pdo)]);
        exit;
    }

    if ($method === 'POST') {
        require_once __DIR__ . '/../lib/category-admin.php';
        $cats = isset($body['category']) ? [$body['category']] : ($body['categories'] ?? null);
        if (!is_array($cats) || !$cats) {
            http_response_code(400);
            echo json_encode(['error' => 'Selecciona una categoría para guardar.']);
            exit;
        }
        try {
            saveCategoryChanges($pdo, $cats);
        } catch (InvalidArgumentException $error) {
            http_response_code(400);
            echo json_encode(['error' => $error->getMessage()]);
            exit;
        }
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($method === 'DELETE') {
        $id = trim((string)($_GET['id'] ?? ''));
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare('SELECT name FROM categories_rows WHERE id = :id FOR UPDATE');
            $find->execute([':id' => $id]);
            $name = $find->fetchColumn();
            $table = getProductsTableName($pdo);
            $count = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE category = :name");
            $count->execute([':name' => $name ?: '']);
            if ($count->fetchColumn()) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['error' => 'Esta categoría tiene productos. Muévelos a otra categoría antes de eliminarla.']);
                exit;
            }
            $pdo->prepare('UPDATE categories_rows SET parent_category_id = NULL WHERE parent_category_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM categories_rows WHERE id = :id')->execute([':id' => $id]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        echo json_encode(['ok' => true]);
        exit;
    }
}


// Familias visuales del sidebar (antes hardcodeadas en tienda.html, ver migracion 017).
if ($action === 'macro-groups') {
    requireAdminAuth();

    if ($method === 'GET') {
        echo json_encode(['macroGroups' => getCategoryMacroGroups($pdo)]);
        exit;
    }

    if ($method === 'POST') {
        $groupId = trim((string)($body['id'] ?? ''));
        $name = trim((string)($body['name'] ?? ''));
        $icon = trim((string)($body['icon'] ?? '')) ?: 'package';
        $sortOrder = (int)($body['sortOrder'] ?? 0);
        $imageUrl = trim((string)($body['imageUrl'] ?? ''));
        $visible = array_key_exists('visible', $body) ? (!empty($body['visible']) ? 1 : 0) : 1;
        if ($imageUrl !== '' && !storefrontCampaignSafeUrl($imageUrl, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'La imagen de la familia no tiene una ruta válida.']);
            exit;
        }
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'El nombre de la familia es obligatorio.']);
            exit;
        }
        if ($groupId === '') {
            $groupId = preg_replace('/[^a-z0-9_-]/', '-', strtolower($name));
            $groupId = trim(preg_replace('/-+/', '-', $groupId), '-') ?: uniqid('grp_');
        }
        $stmt = $pdo->prepare("INSERT INTO category_macro_groups (id, name, icon, sort_order, image_url, visible) VALUES (:id, :name, :icon, :sort, :image, :visible) ON DUPLICATE KEY UPDATE name = VALUES(name), icon = VALUES(icon), sort_order = VALUES(sort_order), image_url = VALUES(image_url), visible = VALUES(visible)");
        $stmt->execute([':id' => $groupId, ':name' => $name, ':icon' => $icon, ':sort' => $sortOrder, ':image' => $imageUrl ?: null, ':visible' => $visible]);
        echo json_encode(['ok' => true, 'id' => $groupId]);
        exit;
    }

    if ($method === 'DELETE') {
        $groupId = trim((string)($_GET['id'] ?? ($body['id'] ?? '')));
        if ($groupId === '' || $groupId === 'otros') {
            http_response_code(400);
            echo json_encode(['error' => 'No se puede eliminar esa familia.']);
            exit;
        }
        // Las categorias que tenian esta familia quedan sin asignar, no se borran ni se mueven
        // a otra familia en silencio -- el admin decide a donde van desde el panel.
        $pdo->prepare('UPDATE categories_rows SET macro_group_id = NULL WHERE macro_group_id = :id')->execute([':id' => $groupId]);
        $pdo->prepare('DELETE FROM category_macro_groups WHERE id = :id')->execute([':id' => $groupId]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// Imagen de una familia (mini-categorias de portada) o logo de una marca. Mismo flujo de
// validacion/limite que upload-campaign-image; se diferencian solo por el directorio destino.
if ($action === 'upload-merch-image' && $method === 'POST') {
    requireAdminAuth();
    $category = (string)($_GET['category'] ?? ($_POST['category'] ?? ''));
    if (!in_array($category, ['categories', 'brands'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Categoría de imagen inválida.']);
        exit;
    }
    if (empty($_FILES['image']['tmp_name']) || (int)($_FILES['image']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Selecciona una imagen para subir.']);
        exit;
    }
    $file = $_FILES['image'];
    if ((int)($file['size'] ?? 0) < 1 || (int)($file['size'] ?? 0) > 8 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'La imagen supera el límite de 8 MB.']);
        exit;
    }
    $info = @getimagesize($file['tmp_name']);
    $mime = $info['mime'] ?? '';
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime]) || (int)($info[0] ?? 0) < 1 || (int)($info[1] ?? 0) < 1 || (int)$info[0] * (int)$info[1] > 40000000) {
        http_response_code(400);
        echo json_encode(['error' => 'Usa una imagen JPG, PNG o WebP válida de hasta 40 megapíxeles.']);
        exit;
    }
    $dir = getSmartispUploadsDir($category);
    if (!is_dir($dir)) {
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo preparar el almacenamiento de imágenes.']);
        exit;
    }
    $filename = rtrim($category, 's') . '_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo guardar la imagen.']);
        exit;
    }
    echo json_encode(['ok' => true, 'url' => '/api/auth/serve-upload?category=' . $category . '&file=' . rawurlencode($filename)]);
    exit;
}

// Destacador de marcas (logos + orden + activo) para la portada de la tienda.
if ($action === 'brands') {
    if ($method === 'GET' && empty($_GET['admin'])) {
        // Publico: solo marcas activas, en el orden que eligio el admin.
        $stmt = $pdo->query('SELECT id, name, logo_url FROM storefront_brands WHERE is_active = 1 ORDER BY sort_order, name');
        $brands = array_map(static fn($b) => ['id' => (int)$b['id'], 'name' => (string)$b['name'], 'logoUrl' => (string)$b['logo_url']], $stmt ? $stmt->fetchAll() : []);
        header('Cache-Control: public, max-age=60');
        echo json_encode(['brands' => $brands]);
        exit;
    }

    requireAdminAuth();

    if ($method === 'GET') {
        $stmt = $pdo->query('SELECT id, name, logo_url, sort_order, is_active FROM storefront_brands ORDER BY sort_order, name');
        $brands = array_map(static fn($b) => [
            'id' => (int)$b['id'], 'name' => (string)$b['name'], 'logoUrl' => (string)$b['logo_url'],
            'sortOrder' => (int)$b['sort_order'], 'isActive' => (string)$b['is_active'] === '1',
        ], $stmt ? $stmt->fetchAll() : []);
        echo json_encode(['brands' => $brands]);
        exit;
    }

    if ($method === 'POST') {
        $id = (int)($body['id'] ?? 0);
        $name = trim((string)($body['name'] ?? ''));
        $logoUrl = trim((string)($body['logoUrl'] ?? ''));
        $sortOrder = (int)($body['sortOrder'] ?? 0);
        $isActive = !empty($body['isActive']) ? 1 : 0;
        if ($name === '' || strlen($name) > 150) {
            http_response_code(400);
            echo json_encode(['error' => 'El nombre de la marca es obligatorio (máximo 150 caracteres).']);
            exit;
        }
        if ($logoUrl === '' || !storefrontCampaignSafeUrl($logoUrl, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Sube un logo válido para la marca.']);
            exit;
        }
        if ($id > 0) {
            $pdo->prepare('UPDATE storefront_brands SET name = :name, logo_url = :logo, sort_order = :sort, is_active = :active WHERE id = :id')
                ->execute([':name' => $name, ':logo' => $logoUrl, ':sort' => $sortOrder, ':active' => $isActive, ':id' => $id]);
        } else {
            $pdo->prepare('INSERT INTO storefront_brands (name, logo_url, sort_order, is_active) VALUES (:name, :logo, :sort, :active)')
                ->execute([':name' => $name, ':logo' => $logoUrl, ':sort' => $sortOrder, ':active' => $isActive]);
            $id = (int)$pdo->lastInsertId();
        }
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? ($body['id'] ?? 0));
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Marca inválida.']);
            exit;
        }
        $pdo->prepare('DELETE FROM storefront_brands WHERE id = :id')->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

if ($action === 'categories-reset' || $action === 'categories/reset') {
    smartispRequirePost();
    requireAdminAuth();
    $pdo->exec("DELETE FROM categories_rows");
    echo json_encode(['ok' => true]);
    exit;
}


if ($action === 'categories-reassign' || $action === 'categories/reassign') {
    smartispRequirePost();
    requireAdminAuth();
    $fromCategory = trim($body['fromCategory'] ?? '');
    $toCategory = trim($body['toCategory'] ?? '');
    $fromSubcategory = trim($body['fromSubcategory'] ?? '');
    $toSubcategory = trim($body['toSubcategory'] ?? '');

    if (!$fromCategory || !$toCategory) {
        http_response_code(400);
        echo json_encode(['error' => 'Debes especificar la categoría origen y destino.']);
        exit;
    }

    $pTable = getProductsTableName($pdo);
    if ($fromSubcategory && $toSubcategory !== '') {
        $stmt = $pdo->prepare("UPDATE `$pTable` SET category = :toCat, subcategory = :toSub WHERE category = :fromCat AND subcategory = :fromSub");
        $stmt->execute([':toCat' => $toCategory, ':toSub' => $toSubcategory, ':fromCat' => $fromCategory, ':fromSub' => $fromSubcategory]);
    } else {
        $stmt = $pdo->prepare("UPDATE `$pTable` SET category = :toCat WHERE category = :fromCat");
        $stmt->execute([':toCat' => $toCategory, ':fromCat' => $fromCategory]);
    }
    echo json_encode(['ok' => true, 'updated' => $stmt->rowCount()]);
    exit;
}

// -------------------------------------------------------------
// 5. PEDIDOS Y COTIZACIONES (/api/auth/orders, /api/auth/customer-orders)
// -------------------------------------------------------------

// -------------------------------------------------------------
// BÚSQUEDA Y PROXY DE IMÁGENES (/api/auth/search-product-image, /api/auth/proxy-image)
// -------------------------------------------------------------
if ($action === 'search-product-image' || $action === 'search-images') {
    $q = trim($_GET['q'] ?? '');
    if (!$q) {
        echo json_encode(['images' => []]);
        exit;
    }
    $wikiUrl = 'https://en.wikipedia.org/w/api.php?action=query&format=json&prop=pageimages&generator=search&gsrsearch=' . urlencode($q) . '&gsrlimit=6&piprop=thumbnail|original&pithumbsize=600';
    $ch = curl_init($wikiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SmartISP/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    $res = curl_exec($ch);
    curl_close($ch);

    $images = [];
    if ($res) {
        $data = json_decode($res, true);
        $pages = $data['query']['pages'] ?? [];
        foreach ($pages as $p) {
            $url = $p['original']['source'] ?? ($p['thumbnail']['source'] ?? '');
            if ($url) {
                $images[] = [
                    'url' => $url,
                    'thumb' => $url,
                    'thumbnail' => $url,
                    'title' => $q,
                    'source' => 'wiki',
                    'store' => 'Wiki',
                    'storeName' => 'Wiki'
                ];
            }
        }
    }
    echo json_encode(['images' => $images]);
    exit;
}

// -------------------------------------------------------------
// PROXY SEGURO DE IMÁGENES (/api/auth/product-image, /api/auth/proxy-image)
// Oculta completamente el dominio de los proveedores (siglo21.net) y sirve las fotos bajo el dominio de SmartISP

// -------------------------------------------------------------
if ($action === 'product-image' || $action === 'proxy-image') {
    $targetUrl = '';
    $id = trim($_GET['id'] ?? ($_GET['sku'] ?? ''));
    $token = trim($_GET['token'] ?? ($_GET['img'] ?? ($_GET['t'] ?? '')));
    $rawUrl = trim($_GET['url'] ?? '');
    $maxImageWidth = max(320, min(800, (int)($_GET['w'] ?? 640)));

    // 1. Resolver por Token Base64Url (directo y de alto rendimiento)
    if (!empty($token)) {
        $decoded = base64_decode(strtr($token, '-_', '+/'));
        if ($decoded && filter_var($decoded, FILTER_VALIDATE_URL)) {
            $targetUrl = $decoded;
        }
    }

    // 2. Resolver por ID de producto en base de datos si no vino token o falló
    if (empty($targetUrl) && !empty($id)) {
        try {
            $pTable = getProductsTableName($pdo);
            $stmt = $pdo->prepare("SELECT * FROM `$pTable` WHERE id = :id OR sku = :sku LIMIT 1");
            $stmt->execute([':id' => $id, ':sku' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $norm = normalizeProductRow($row);
                $foundUrl = trim($norm['rawImageUrl'] ?? ($norm['imageUrl'] ?? ''));
                if (!empty($foundUrl) && strpos($foundUrl, '/api/auth/') !== 0) {
                    $targetUrl = $foundUrl;
                }
            }
        } catch (Throwable $e) {}
    }

    // 3. Fallback a URL directa si es válida
    if (empty($targetUrl) && !empty($rawUrl) && filter_var($rawUrl, FILTER_VALIDATE_URL)) {
        $targetUrl = $rawUrl;
    }

    // Placeholder propio (SVG estático, sin scripts). Las imágenes de terceros nunca se sirven como SVG.
    $servePlaceholder = function() {
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
        header('Cache-Control: public, max-age=86400');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400" fill="none"><rect width="400" height="400" fill="#f8fafc"/><rect x="70" y="70" width="260" height="260" rx="16" fill="#e2e8f0"/><path d="M130 270l50-60 40 45 45-55 45 70H130z" fill="#94a3b8"/><circle cx="170" cy="160" r="22" fill="#94a3b8"/><text x="200" y="318" text-anchor="middle" font-family="system-ui, -apple-system, sans-serif" font-size="14" font-weight="700" fill="#64748b">SmartISP</text></svg>';
        exit;
    };

    require_once __DIR__ . '/../lib/image-proxy.php';
    $scheme = strtolower((string)parse_url($targetUrl, PHP_URL_SCHEME));
    if (empty($targetUrl) || !filter_var($targetUrl, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
        $servePlaceholder();
    }

    // 4. Directorio de Caché persistente en disco
    $cacheDir = __DIR__ . '/../../uploads/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        $cacheDir = sys_get_temp_dir() . '/smartisp_img_cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
    }

    $acceptsWebp = strpos(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'image/webp') !== false
        && function_exists('imagecreatefromstring') && function_exists('imagewebp');
    $cacheHash = md5($targetUrl . '|w=' . $maxImageWidth . '|webp=' . ($acceptsWebp ? '1' : '0'));
    $sendImage = static function (string $mime, int $length, string $cacheHash): void {
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=2592000, immutable');
        header('ETag: "' . $cacheHash . '"');
        header('Content-Length: ' . $length);
        header('Access-Control-Allow-Origin: *');
    };

    // En caché solo hay archivos con extensión de un tipo verificado al descargarlos (máximo 30 días).
    foreach (SMARTISP_PROXY_IMAGE_TYPES as $mime => $ext) {
        $cacheFile = $cacheDir . '/' . $cacheHash . '.' . $ext;
        if (!is_file($cacheFile) || filesize($cacheFile) <= 0 || (time() - filemtime($cacheFile) >= 86400 * 30)) continue;
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $cacheHash) {
            http_response_code(304);
            exit;
        }
        $sendImage($mime, (int)filesize($cacheFile), $cacheHash);
        readfile($cacheFile);
        exit;
    }

    // 5. Descarga validando cada salto (sin IPs internas) y el tipo real de la imagen.
    $imgData = smartispFetchRemoteImage($targetUrl);
    $contentType = $imgData !== null ? smartispDetectImageType($imgData) : null;

    if ($imgData !== null && $contentType !== null) {
        if ($acceptsWebp && in_array($contentType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $imageInfo = @getimagesizefromstring($imgData);
            $width = (int)($imageInfo[0] ?? 0);
            $height = (int)($imageInfo[1] ?? 0);
            if ($width > 0 && $height > 0 && $width * $height <= 20000000) {
                $source = @imagecreatefromstring($imgData);
                if ($source !== false) {
                    $outWidth = min($width, $maxImageWidth);
                    $outHeight = max(1, (int)round($height * ($outWidth / $width)));
                    $output = imagecreatetruecolor($outWidth, $outHeight);
                    if ($output !== false) {
                        imagealphablending($output, false);
                        imagesavealpha($output, true);
                        imagecopyresampled($output, $source, 0, 0, 0, 0, $outWidth, $outHeight, $width, $height);
                        ob_start();
                        $encoded = imagewebp($output, null, 78);
                        $optimizedData = ob_get_clean();
                        imagedestroy($output);
                        if ($encoded && is_string($optimizedData) && strlen($optimizedData) < strlen($imgData)) {
                            $imgData = $optimizedData;
                            $contentType = 'image/webp';
                        }
                    }
                    imagedestroy($source);
                }
            }
        }

        @file_put_contents($cacheDir . '/' . $cacheHash . '.' . SMARTISP_PROXY_IMAGE_TYPES[$contentType], $imgData);
        smartispPruneImageCache($cacheDir);
        $sendImage($contentType, strlen($imgData), $cacheHash);
        echo $imgData;
        exit;
    }

    // Si falló la descarga o no era una imagen admitida, servir el placeholder de SmartISP
    $servePlaceholder();
}
