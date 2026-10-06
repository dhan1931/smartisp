<?php
// Parte de api/router.php, dividido por dominio (DEV-20261005-015): catalogo, productos admin, import, categorias e imagenes.
// Comparte el scope de router.php via require (no es una funcion): $pdo, $body, $action,
// $method y los helpers (getAuthUser, isAdminUser, requireAdminAuth, etc.) ya estan definidos
// cuando este archivo se incluye. Cada bloque se autogestiona con 'if ($action === ...) { ... exit; }',
// igual que antes; solo cambio donde vive el codigo, no la logica.

// -------------------------------------------------------------
// 1. CATÁLOGO PÚBLICO (/api/auth/catalog)
// -------------------------------------------------------------
if ($action === 'catalog' && $method === 'GET') {
    try {
        $pTable = getProductsTableName($pdo);
        // Garantiza las columnas esperadas y un índice (visible, created_at) antes de filtrar/paginar en SQL.
        ensureProductTableColumns($pdo, $pTable);

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 36)));
        $offset = ($page - 1) * $limit;
        $search = trim((string)($_GET['q'] ?? ''));

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

        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM `$pTable` WHERE $where");
        $totalStmt->execute($params);
        $total = (int)$totalStmt->fetchColumn();

        // Solo las columnas que normalizeProductRow usa; sin created_at/updated_at ni SELECT *.
        $cols = 'id, name, description, price, category, subcategory, image_url, external_url, sku, visible';
        // id como desempate: en los datos reales created_at está vacío en todas las filas, así que sin un
        // criterio estable la paginación podía devolver un producto repetido o saltarse otro entre páginas.
        // ASC (no DESC): hay productos de prueba con id que empieza por "test_"/"prod_test_" e imagen rota;
        // con DESC ordenaban primero por el alfabeto y aparecían arriba del catálogo real.
        $stmt = $pdo->prepare("SELECT $cols FROM `$pTable` WHERE $where ORDER BY created_at DESC, id ASC LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $products = array_map('normalizeProductRow', $stmt->fetchAll());

        $stmtContent = $pdo->query("SELECT setting_key as `key`, setting_value as `value` FROM settings_rows");
        $allContent = $stmtContent ? $stmtContent->fetchAll() : [];
        $content = [];
        $hasLogoImage = false;
        foreach ($allContent as $item) {
            if (($item['key'] ?? '') === 'logo_image' && !empty($item['value'])) {
                $hasLogoImage = true;
                break;
            }
        }
        $excludePrefixes = [
            'solutions_grid_html', 'advantages_grid_html', 'about_visual_html', 'stats_grid_html',
            'landing_logo_dark_image', 'about_', 'contact_', 'mission_', 'vision_', 'value', 'sol', 'adv', 'stat'
        ];
        foreach ($allContent as $item) {
            $k = (string)($item['key'] ?? '');
            if ($k === 'landing_logo_image' && $hasLogoImage) {
                continue; // Evitar duplicar 1MB en la tienda
            }
            $shouldExclude = false;
            foreach ($excludePrefixes as $prefix) {
                if (str_starts_with($k, $prefix)) {
                    $shouldExclude = true;
                    break;
                }
            }
            if (!$shouldExclude) {
                $content[] = $item;
            }
        }

        $categories = getDynamicCategoriesList($pdo);

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
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'products' => [], 'content' => [], 'categories' => []]);
    }
    exit;
}

// -------------------------------------------------------------
// 2. GESTIÓN DE PRODUCTOS PARA EL EDITOR (/api/auth/admin-products)
// -------------------------------------------------------------

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
        $products = array_map('normalizeProductRow', $stmt->fetchAll());

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
        $price = (float)($body['price'] ?? 0);
        $category = trim($body['category'] ?? 'General');
        if (empty($category)) $category = 'General';
        $subcategory = trim($body['subcategory'] ?? '');
        $imageUrl = trim($body['imageUrl'] ?? ($body['image_url'] ?? ''));

        // Si la imagen es un Data URL base64, guardarla automáticamente como archivo en uploads/products/
        if (strpos($imageUrl, 'data:image/') === 0 && preg_match('/^data:image\/(\w+);base64,(.+)$/', $imageUrl, $m)) {
            $ext = strtolower($m[1]) === 'png' ? 'png' : (strtolower($m[1]) === 'webp' ? 'webp' : 'jpg');
            $bData = base64_decode($m[2]);
            if ($bData && strlen($bData) < 15 * 1024 * 1024) {
                $uploadDir = __DIR__ . '/../uploads/products/';
                if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                $fn = 'prod_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (@file_put_contents($uploadDir . $fn, $bData) !== false) {
                    $imageUrl = '/uploads/products/' . $fn;
                    $pubDir = __DIR__ . '/../public/uploads/products/';
                    if (is_dir($pubDir)) {
                        @mkdir($pubDir, 0755, true);
                        @copy($uploadDir . $fn, $pubDir . $fn);
                    }
                }
            }
        }

        $externalUrl = trim($body['externalUrl'] ?? ($body['external_url'] ?? ''));
        $sku = trim($body['sku'] ?? '');
        $visible = isset($body['visible']) ? ($body['visible'] ? 1 : 0) : 1;

        if (!$name) {
            http_response_code(400);
            echo json_encode(['error' => 'El nombre del producto es obligatorio.']);
            exit;
        }

        // Si se especificó una nueva categoría, asegurar que esté registrada en categories_rows
        if (!empty($category)) {
            try {
                $chkCat = $pdo->prepare("SELECT id FROM `categories_rows` WHERE LOWER(name) = LOWER(:name) LIMIT 1");
                $chkCat->execute([':name' => $category]);
                if (!$chkCat->fetchColumn()) {
                    $catId = slugify($category);
                    $insCat = $pdo->prepare("INSERT INTO `categories_rows` (id, name, subcategories) VALUES (:id, :name, :subs)");
                    $subsJson = !empty($subcategory) ? json_encode([$subcategory], JSON_UNESCAPED_UNICODE) : '[]';
                    $insCat->execute([':id' => $catId, ':name' => $category, ':subs' => $subsJson]);
                }
            } catch (Throwable $e) {}
        }

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
            $sql = "UPDATE `$pTable` SET
                        name = :name,
                        description = :description,
                        price = :price,
                        category = :category,
                        subcategory = :subcategory,
                        image_url = :image_url,
                        external_url = :external_url,
                        sku = :sku,
                        visible = :visible,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':id'           => $existingId,
                ':name'         => $name,
                ':description'  => $description,
                ':price'        => $price,
                ':category'     => $category,
                ':subcategory'  => $subcategory,
                ':image_url'    => $imageUrl,
                ':external_url' => $externalUrl,
                ':sku'          => $sku,
                ':visible'      => $visible
            ]);
            $publicUrl = '/producto/' . $existingId . '-' . $prodSlug;
            echo json_encode([
                'ok'      => true,
                'id'      => $existingId,
                'name'    => $name,
                'url'     => $publicUrl,
                'message' => 'Producto actualizado con éxito.'
            ]);
        } else {
            $sql = "INSERT INTO `$pTable` (id, name, description, price, category, subcategory, image_url, external_url, sku, visible)
                    VALUES (:id, :name, :description, :price, :category, :subcategory, :image_url, :external_url, :sku, :visible)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':id'           => $id,
                ':name'         => $name,
                ':description'  => $description,
                ':price'        => $price,
                ':category'     => $category,
                ':subcategory'  => $subcategory,
                ':image_url'    => $imageUrl,
                ':external_url' => $externalUrl,
                ':sku'          => $sku,
                ':visible'      => $visible
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
// 2.1 SUBIDA DE IMÁGENES DE PRODUCTOS (/api/auth/upload-image)
// -------------------------------------------------------------

if ($action === 'upload-image' && $method === 'POST') {
    requireAdminAuth();

    $uploadDir = __DIR__ . '/../uploads/products/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

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
    $targetPath = $uploadDir . $filename;

    if (@file_put_contents($targetPath, $fileData) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo guardar la imagen en el servidor (permisos de carpeta).']);
        exit;
    }

    $publicUploadDir = __DIR__ . '/../public/uploads/products/';
    if (is_dir($publicUploadDir)) {
        @mkdir($publicUploadDir, 0755, true);
        @copy($targetPath, $publicUploadDir . $filename);
    }

    $publicUrl = '/uploads/products/' . $filename;
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

if ($action === 'import-products' || $action === 'import-excel' || $action === 'admin-products-bulk') {
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
        $checkStmt = $pdo->prepare("SELECT id FROM `$pTable` WHERE id = :id OR (name = :name AND name != '') LIMIT 1");

        $insertStmt = $pdo->prepare("INSERT INTO `$pTable` (id, name, description, price, category, subcategory, image_url, external_url, sku, visible)
                VALUES (:id, :name, :description, :price, :category, :subcategory, :image_url, :external_url, :sku, :visible)");

        $updateStmt = $pdo->prepare("UPDATE `$pTable` SET 
                name = :name,
                description = :description,
                price = :price,
                category = :category,
                subcategory = :subcategory,
                image_url = :image_url,
                external_url = :external_url,
                sku = :sku,
                visible = :visible,
                updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");

        $inserted = 0;
        foreach ($products as $p) {
            $id = $p['id'] ?? uniqid('prod_');
            $name = trim($p['name'] ?? '');
            if (!$name) continue;

            $desc = trim($p['description'] ?? '');
            $price = (float)($p['price'] ?? 0);
            $cat = trim($p['category'] ?? 'General');
            $subcat = trim($p['subcategory'] ?? '');
            $img = trim($p['imageUrl'] ?? ($p['image_url'] ?? ''));
            $ext = trim($p['externalUrl'] ?? ($p['external_url'] ?? ''));
            $sku = trim($p['sku'] ?? '');
            $vis = isset($p['visible']) ? ($p['visible'] ? 1 : 0) : 1;

            $checkStmt->execute([':id' => $id, ':name' => $name]);
            $existingId = $checkStmt->fetchColumn();

            if ($existingId) {
                $updateStmt->execute([
                    ':id'           => $existingId,
                    ':name'         => $name,
                    ':description'  => $desc,
                    ':price'        => $price,
                    ':category'     => $cat,
                    ':subcategory'  => $subcat,
                    ':image_url'    => $img,
                    ':external_url' => $ext,
                    ':sku'          => $sku,
                    ':visible'      => $vis
                ]);
            } else {
                $insertStmt->execute([
                    ':id'           => $id,
                    ':name'         => $name,
                    ':description'  => $desc,
                    ':price'        => $price,
                    ':category'     => $cat,
                    ':subcategory'  => $subcat,
                    ':image_url'    => $img,
                    ':external_url' => $ext,
                    ':sku'          => $sku,
                    ':visible'      => $vis
                ]);
            }
            $inserted++;
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'count' => $inserted]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Error en la importación: ' . $e->getMessage()]);
    }
    exit;
}


if ($action === 'admin-products-clear') {
    requireAdminAuth();
    $pTable = getProductsTableName($pdo);
    $pdo->exec("DELETE FROM `$pTable`");
    echo json_encode(['ok' => true, 'cleared' => true]);
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
        echo json_encode(['categories' => $cats]);
        exit;
    }

    if ($method === 'POST') {
        $cats = $body['categories'] ?? [];
        if (is_array($cats)) {
            $pdo->exec("DELETE FROM categories_rows");
            $stmt = $pdo->prepare("INSERT INTO categories_rows (id, name, subcategories) VALUES (:id, :name, :sub)");
            foreach ($cats as $c) {
                $cId = $c['id'] ?? uniqid('cat_');
                $cName = $c['name'] ?? 'General';
                $sub = is_array($c['subcategories'] ?? null) ? json_encode($c['subcategories']) : (string)($c['subcategories'] ?? '');
                $stmt->execute([':id' => $cId, ':name' => $cName, ':sub' => $sub]);
            }
        }
        echo json_encode(['ok' => true]);
        exit;
    }
}


if ($action === 'categories-reset' || $action === 'categories/reset') {
    requireAdminAuth();
    $pdo->exec("DELETE FROM categories_rows");
    echo json_encode(['ok' => true]);
    exit;
}


if ($action === 'categories-reassign' || $action === 'categories/reassign') {
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

    // Función auxiliar para servir placeholder SVG neutro de SmartISP
    $servePlaceholder = function() {
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400" fill="none"><rect width="400" height="400" fill="#f8fafc"/><rect x="70" y="70" width="260" height="260" rx="16" fill="#e2e8f0"/><path d="M130 270l50-60 40 45 45-55 45 70H130z" fill="#94a3b8"/><circle cx="170" cy="160" r="22" fill="#94a3b8"/><text x="200" y="318" text-anchor="middle" font-family="system-ui, -apple-system, sans-serif" font-size="14" font-weight="700" fill="#64748b">SmartISP</text></svg>';
        exit;
    };

    if (empty($targetUrl) || !filter_var($targetUrl, FILTER_VALIDATE_URL)) {
        $servePlaceholder();
    }

    // 4. Directorio de Caché persistente en disco
    $cacheDir = __DIR__ . '/../public/uploads/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        $cacheDir = sys_get_temp_dir() . '/smartisp_img_cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
    }

    $cacheHash = md5($targetUrl);
    $pathExt = pathinfo(parse_url($targetUrl, PHP_URL_PATH), PATHINFO_EXTENSION);
    $ext = in_array(strtolower($pathExt), ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg']) ? strtolower($pathExt) : 'jpg';
    $cacheFile = $cacheDir . '/' . $cacheHash . '.' . $ext;

    // Verificar si ya existe en caché (máximo 30 días)
    if (file_exists($cacheFile) && filesize($cacheFile) > 0 && (time() - filemtime($cacheFile) < 86400 * 30)) {
        $mime = function_exists('mime_content_type') ? @mime_content_type($cacheFile) : null;
        if (!$mime) $mime = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=2592000, immutable');
        header('ETag: "' . $cacheHash . '"');
        header('Content-Length: ' . filesize($cacheFile));
        header('Access-Control-Allow-Origin: *');
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $cacheHash) {
            http_response_code(304);
            exit;
        }
        readfile($cacheFile);
        exit;
    }

    // 5. Descarga segura del servidor proveedor mediante cURL
    $imgData = null;
    $contentType = null;
    $parsedHost = parse_url($targetUrl, PHP_URL_HOST);
    $parsedScheme = parse_url($targetUrl, PHP_URL_SCHEME) ?: 'https';
    $referer = $parsedScheme . '://' . $parsedHost . '/';

    if (function_exists('curl_init')) {
        $ch = curl_init($targetUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_REFERER        => $referer,
            CURLOPT_HTTPHEADER     => [
                'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Accept-Language: es-EC,es;q=0.9,en;q=0.8'
            ]
        ]);
        $imgData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($httpCode < 200 || $httpCode >= 300) {
            $imgData = null;
        }
    } else {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\nReferer: $referer\r\n",
                'timeout' => 8
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        $imgData = @file_get_contents($targetUrl, false, stream_context_create($opts));
    }

    if (!empty($imgData)) {
        if (!$contentType || strpos($contentType, 'image/') === false) {
            $contentType = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
        }
        @file_put_contents($cacheFile, $imgData);
        header('Content-Type: ' . $contentType);
        header('Cache-Control: public, max-age=2592000, immutable');
        header('ETag: "' . $cacheHash . '"');
        header('Content-Length: ' . strlen($imgData));
        header('Access-Control-Allow-Origin: *');
        echo $imgData;
        exit;
    }

    // Si falló la descarga, servir el placeholder de SmartISP
    $servePlaceholder();
}

