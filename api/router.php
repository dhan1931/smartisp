<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Admin-Token');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// Manejador global de excepciones para evitar cualquier error 500 vacío
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Error en el servidor: ' . $e->getMessage(),
        'file'  => basename($e->getFile()),
        'line'  => $e->getLine()
    ]);
    exit;
});

if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        @session_set_cookie_params([
            'lifetime' => 86400 * 30,
            'path'     => '/',
            'domain'   => '',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    @session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

$pdo = getDbConnection();

// El rol de administrador vive únicamente en users_rows.role, puesto por un login real
// (contraseña verificada) o, en el futuro, por una acción explícita de gestión de usuarios.
// Antes había aquí una lista de emails hardcodeada que: (a) en cada petición forzaba
// role='customer' para cualquier email fuera de la lista y role='admin' para los de la lista,
// sin importar lo que dijera la base; y (b) se usaba para otorgar una contraseña universal
// y para decidir el rol otra vez después de autenticar. Todo eso se eliminó.

function slugify(string $text): string {
    $clean = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    if (!$clean) $clean = $text;
    $clean = preg_replace('~[^\\pL\\d]+~u', '-', $clean);
    $clean = trim($clean, '-');
    $clean = preg_replace('~-+~', '-', $clean);
    $clean = strtolower($clean);
    return !empty($clean) ? substr($clean, 0, 80) : 'articulo';
}

function generateAdminAuthToken(array $user): string {
    $secretKey = getenv('SESSION_SECRET') ?: '';
    if ($secretKey === '') {
        throw new RuntimeException('Falta configurar SESSION_SECRET para firmar sesiones.');
    }
    $payload = [
        'id'    => $user['id'] ?? uniqid('usr_'),
        'email' => strtolower(trim((string)($user['email'] ?? ''))),
        'name'  => $user['name'] ?? '',
        'role'  => strtolower(trim((string)($user['role'] ?? 'customer'))),
        'exp'   => time() + (86400 * 30) // 30 días de vigencia
    ];
    $b64 = base64_encode(json_encode($payload));
    $sig = hash_hmac('sha256', $b64, $secretKey);
    return $b64 . '.' . $sig;
}

function getAuthUser(): ?array {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $user = $_SESSION['user'] ?? null;

    // Validar token Bearer o cabecera X-Admin-Token o parámetro si no hay usuario o si el usuario actual no es admin
    if (!is_array($user)) {
        $token = '';
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $token = $matches[1];
        }
        if (empty($token) && !empty($_SERVER['HTTP_X_ADMIN_TOKEN'])) {
            $token = $_SERVER['HTTP_X_ADMIN_TOKEN'];
        }
        if (empty($token) && function_exists('getallheaders')) {
            $hdrs = (array)getallheaders();
            foreach ($hdrs as $k => $v) {
                if (strtolower($k) === 'x-admin-token' && !empty($v)) {
                    $token = trim((string)$v);
                    break;
                }
                if (strtolower($k) === 'authorization' && preg_match('/Bearer\s+(\S+)/i', (string)$v, $hm)) {
                    $token = $hm[1];
                    break;
                }
            }
        }
        if (empty($token)) {
            $token = trim((string)($_REQUEST['admin_token'] ?? ($_REQUEST['token'] ?? '')));
        }

        if (!empty($token)) {
            $secretKey = getenv('SESSION_SECRET') ?: '';
            $parts = explode('.', $token);
            if ($secretKey !== '' && count($parts) === 2) {
                list($payloadB64, $sig) = $parts;
                $expectedSig = hash_hmac('sha256', $payloadB64, $secretKey);
                if (hash_equals($expectedSig, $sig)) {
                    $decoded = json_decode(base64_decode($payloadB64), true);
                    if (is_array($decoded) && !empty($decoded['id'])) {
                        if (empty($decoded['exp']) || $decoded['exp'] > time()) {
                            try {
                                $stmt = getDbConnection()->prepare('SELECT id, email, name, surname, phone, role FROM users_rows WHERE id = :id LIMIT 1');
                                $stmt->execute([':id' => $decoded['id']]);
                                $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
                                if ($currentUser) {
                                    $currentUser['role'] = strtolower(trim((string)($currentUser['role'] ?? 'customer')));
                                    $user = $currentUser;
                                    $_SESSION['user'] = $user;
                                }
                            } catch (Throwable $e) {
                                error_log('No se pudo validar el usuario del token: ' . $e->getMessage());
                            }
                        }
                    }
                }
            }
        }
    }

    if (is_array($user) && !empty($user['email'])) {
        return $user;
    }

    return null;
}

function isAdminUser(?array $user = null): bool {
    if ($user === null) {
        $user = getAuthUser();
    }
    if (!$user || !is_array($user)) {
        return false;
    }
    // El rol ya quedó fijado al autenticar (sesión real o token firmado); no se vuelve
    // a decidir aquí por email.
    return strtolower(trim((string)($user['role'] ?? ''))) === 'admin';
}

function requireAdminAuth(): void {
    if (!isAdminUser()) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'Acceso denegado: Se requieren permisos de administrador.',
            'unauthorized' => true
        ]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true) ?: $_POST;

// Procesamiento seguro de payload codificado en lote
if (is_array($body) && !empty($body['payload']) && is_string($body['payload'])) {
    $decoded = @base64_decode($body['payload']);
    if ($decoded !== false) {
        $unpacked = json_decode($decoded, true);
        if (is_array($unpacked)) {
            $body = array_merge($body, $unpacked);
        }
    }
    // El 'payload' original (base64 del body completo, incluidas contraseñas como smtp_pass)
    // quedaba en $body después de desempacarlo. Los handlers que guardan "cada clave del body
    // como un ajuste" (p. ej. admin-content POST) lo guardaban tal cual bajo una clave llamada
    // literalmente "payload", exponiendo la contraseña SMTP real sin enmascarar.
    unset($body['payload']);
}

$action = $_GET['action'] ?? ($_GET['route'] ?? ($body['action'] ?? ($_POST['action'] ?? '')));
$action = trim(str_replace('auth/', '', $action), '/');
if (strpos($action, '?') !== false) {
    list($actionPart, $queryPart) = explode('?', $action, 2);
    $action = $actionPart;
    parse_str($queryPart, $extraGet);
    $_GET = array_merge($_GET, $extraGet);
}
$method = $_SERVER['REQUEST_METHOD'];


// -------------------------------------------------------------
// Handlers por dominio (DEV-20261005-015). Cada require comprueba $action internamente y
// hace exit si le corresponde; si ninguno aplica, sigue hasta el 404 final de este archivo.
// -------------------------------------------------------------
if (!$pdo) {
    http_response_code(503);
    echo json_encode([
        'error' => 'Base de datos temporalmente no disponible.',
        'products' => [],
        'content' => []
    ]);
    exit;
}

// -------------------------------------------------------------
function getDynamicCategoriesList(PDO $pdo): array {
    $pTable = getProductsTableName($pdo);
    // 1. Obtener todas las macrocategorías, subcategorías y conteo real de productos
    $prodCatsStmt = $pdo->query("SELECT category, subcategory, COUNT(*) as p_count FROM `$pTable` WHERE category IS NOT NULL AND TRIM(category) != '' GROUP BY category, subcategory");
    $prodCatRows = $prodCatsStmt ? $prodCatsStmt->fetchAll() : [];

    $catMap = [];
    foreach ($prodCatRows as $r) {
        $cName = trim((string)$r['category']);
        if (!$cName) continue;
        if (!isset($catMap[$cName])) {
            $catMap[$cName] = [
                'count'         => 0,
                'subcategories' => []
            ];
        }
        $catMap[$cName]['count'] += (int)($r['p_count'] ?? 1);
        $sName = trim((string)($r['subcategory'] ?? ''));
        if ($sName && !in_array($sName, $catMap[$cName]['subcategories'], true)) {
            $catMap[$cName]['subcategories'][] = $sName;
        }
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `categories_rows` (
            `id` VARCHAR(100) NOT NULL PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `subcategories` LONGTEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    $stmt = $pdo->query("SELECT * FROM categories_rows");
    $dbCats = $stmt ? $stmt->fetchAll() : [];
    $existingNames = [];

    $cats = [];
    foreach ($dbCats as $row) {
        $name = trim((string)$row['name']);
        if (!$name) continue;
        $existingNames[strtolower($name)] = true;
        $subs = [];
        if (!empty($row['subcategories'])) {
            $decoded = json_decode($row['subcategories'], true);
            if (is_array($decoded)) {
                $subs = $decoded;
            } else {
                $subs = array_filter(array_map('trim', explode(',', $row['subcategories'])));
            }
        }
        if (isset($catMap[$name])) {
            foreach ($catMap[$name]['subcategories'] as $ps) {
                if (!in_array($ps, $subs, true)) {
                    $subs[] = $ps;
                }
            }
        }
        $cats[] = [
            'id'            => (string)($row['id'] ?? uniqid('cat_')),
            'name'          => $name,
            'subcategories' => array_values($subs),
            'productCount'  => $catMap[$name]['count'] ?? 0,
            'updated_at'    => $row['updated_at'] ?? null
        ];
    }

    $toInsert = [];
    foreach ($catMap as $cName => $info) {
        if (!isset($existingNames[strtolower($cName)])) {
            $cId = preg_replace('/[^a-z0-9_-]/', '-', strtolower($cName));
            $cId = trim(preg_replace('/-+/', '-', $cId), '-');
            if (!$cId) $cId = uniqid('cat_');
            $newCat = [
                'id'            => $cId,
                'name'          => $cName,
                'subcategories' => array_values($info['subcategories']),
                'productCount'  => $info['count'],
                'updated_at'    => date('Y-m-d H:i:s')
            ];
            $cats[] = $newCat;
            $toInsert[] = $newCat;
        }
    }

    if (!empty($toInsert)) {
        try {
            $ins = $pdo->prepare("INSERT INTO categories_rows (id, name, subcategories) VALUES (:id, :name, :sub) ON DUPLICATE KEY UPDATE name = VALUES(name), subcategories = VALUES(sub)");
            foreach ($toInsert as $tc) {
                $ins->execute([
                    ':id'   => $tc['id'],
                    ':name' => $tc['name'],
                    ':sub'  => json_encode($tc['subcategories'], JSON_UNESCAPED_UNICODE)
                ]);
            }
        } catch (Throwable $e) {}
    }

    usort($cats, function($a, $b) {
        return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
    });

    return $cats;
}

// -------------------------------------------------------------
// 1. CATÁLOGO PÚBLICO (/api/auth/catalog)
// -------------------------------------------------------------
if ($action === 'catalog' && $method === 'GET') {
    try {
        $pTable = getProductsTableName($pdo);
        
        $stmt = $pdo->query("SELECT * FROM `$pTable`");
        $rawProducts = $stmt->fetchAll();
        $products = [];
        foreach ($rawProducts as $p) {
            $norm = normalizeProductRow($p);
            if ($norm['visible']) {
                $products[] = $norm;
            }
        }

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
        $total = count($products);

        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
        $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 36;

        $returnProducts = $products;
        if ($page !== null) {
            $offset = ($page - 1) * $limit;
            $returnProducts = array_slice($products, $offset, $limit);
        }

        echo json_encode([
            'products'   => $returnProducts,
            'total'      => $total,
            'page'       => $page ?: 1,
            'limit'      => $limit,
            'totalPages' => ceil($total / $limit),
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
if ($action === 'admin-products') {
    requireAdminAuth();
    $pTable = getProductsTableName($pdo);

    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT * FROM `$pTable` ORDER BY created_at DESC");
        $rows = $stmt->fetchAll();
        $products = array_map('normalizeProductRow', $rows);
        echo json_encode(['products' => $products]);
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
if ($action === 'import-products' || $action === 'import-excel' || $action === 'admin-products-bulk' || $action === 'import-catalog' || $action === 'save-products-batch') {
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
        $checkStmtName = $pdo->prepare("SELECT id FROM `$pTable` WHERE name = :name AND name != '' LIMIT 1");

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
            $name = trim($p['name'] ?? '');
            if (!$name) continue;

            $rawId = trim((string)($p['id'] ?? ''));
            $id = (!empty($rawId) && $rawId !== 'modal-draft') ? $rawId : ('prod_' . bin2hex(random_bytes(7)));

            $desc = trim($p['description'] ?? '');
            $price = (float)($p['price'] ?? 0);
            $cat = trim($p['category'] ?? 'General');
            if (empty($cat)) $cat = 'General';
            $subcat = trim($p['subcategory'] ?? '');
            $img = trim($p['imageUrl'] ?? ($p['image_url'] ?? ''));
            $ext = trim($p['externalUrl'] ?? ($p['external_url'] ?? ''));
            $sku = trim($p['sku'] ?? '');
            $vis = isset($p['visible']) ? ($p['visible'] ? 1 : 0) : 1;

            // Auto-crear categoría en categories_rows si no existe
            if (!empty($cat)) {
                try {
                    $chkCat = $pdo->prepare("SELECT id FROM `categories_rows` WHERE LOWER(name) = LOWER(:name) LIMIT 1");
                    $chkCat->execute([':name' => $cat]);
                    if (!$chkCat->fetchColumn()) {
                        $catId = slugify($cat);
                        $insCat = $pdo->prepare("INSERT INTO `categories_rows` (id, name, subcategories) VALUES (:id, :name, :subs)");
                        $subsJson = !empty($subcat) ? json_encode([$subcat], JSON_UNESCAPED_UNICODE) : '[]';
                        $insCat->execute([':id' => $catId, ':name' => $cat, ':subs' => $subsJson]);
                    }
                } catch (Throwable $e) {}
            }

            // Identificar si el producto ya existe (por id, sku o nombre idéntico)
            $existingId = null;
            if (!empty($rawId)) {
                $checkStmtId->execute([':id' => $rawId]);
                $existingId = $checkStmtId->fetchColumn();
            }
            if (!$existingId && !empty($sku)) {
                $checkStmtSku->execute([':sku' => $sku]);
                $existingId = $checkStmtSku->fetchColumn();
            }
            if (!$existingId && !empty($name)) {
                $checkStmtName->execute([':name' => $name]);
                $existingId = $checkStmtName->fetchColumn();
            }

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
if ($action === 'admin-content') {
    requireAdminAuth();

    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT setting_key as `key`, setting_value as `value` FROM settings_rows");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $maskedRows = [];
        $hasSmtpPass = false;
        $hasResendKey = false;
        foreach ($rows as $r) {
            $k = $r['key'] ?? '';
            $v = $r['value'] ?? '';
            if ($k === 'smtp_pass') {
                if (!empty($v)) $hasSmtpPass = true;
                $v = !empty($v) ? '••••••••' : '';
            } elseif ($k === 'resend_api_key') {
                if (!empty($v)) $hasResendKey = true;
                $v = !empty($v) ? '••••••••' : '';
            }
            $maskedRows[] = ['key' => $k, 'value' => $v];
        }
        $maskedRows[] = ['key' => 'smtp_has_pass', 'value' => $hasSmtpPass ? 'true' : 'false'];
        $maskedRows[] = ['key' => 'resend_has_key', 'value' => $hasResendKey ? 'true' : 'false'];
        echo json_encode(['content' => $maskedRows]);
        exit;
    }

    if ($method === 'POST') {
        $items = $body['content'] ?? ($body['items'] ?? null);

        if (!is_array($items) && is_array($body)) {
            $items = [];
            foreach ($body as $k => $v) {
                if ($k === 'content' || $k === 'items') continue;
                $items[] = ['key' => $k, 'value' => is_string($v) ? $v : json_encode($v)];
            }
        }

        if (is_array($items)) {
            $stmt = $pdo->prepare("INSERT INTO settings_rows (setting_key, setting_value)
                                   VALUES (:key, :value)
                                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP");
            foreach ($items as $item) {
                $k = $item['key'] ?? '';
                $v = $item['value'] ?? '';
                if ($k) {
                    // Si el valor de contraseña es viñetas '••••••••' o vacío al enviar sin cambios, NO sobreescribir la contraseña existente
                    if (($k === 'smtp_pass' || $k === 'resend_api_key') && ($v === '••••••••' || $v === '')) {
                        continue;
                    }
                    $stmt->execute([':key' => $k, ':value' => (string)$v]);
                }
            }
        }

        echo json_encode(['ok' => true]);
        exit;
    }
}

// -------------------------------------------------------------
// CONTENIDO PÚBLICO DE PORTADA Y TIENDA (/api/auth/landing-content, /api/auth/site-content)
// -------------------------------------------------------------
if ($action === 'landing-content' || $action === 'site-content') {
    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT setting_key as `key`, setting_value as `value` FROM settings_rows");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        // SANITIZACIÓN ESTRICTA DE SEGURIDAD (QA-024 / QA-025):
        // Jamás devolver secretos o credenciales en endpoints públicos
        $sensitiveKeys = [
            'smtp_pass', 'smtp_user', 'smtp_host', 'smtp_port', 'smtp_secure',
            'smtp_provider', 'smtp_from', 'resend_api_key', 'admin_email', 'email_from'
        ];
        $safeRows = [];
        $hasLandingLogo = false;
        foreach ($rows as $r) {
            $k = $r['key'] ?? '';
            if (in_array($k, $sensitiveKeys, true) || stripos($k, 'pass') !== false || stripos($k, 'secret') !== false) {
                continue;
            }
            if ($k === 'landing_logo_image' && !empty($r['value'])) {
                $hasLandingLogo = true;
            }
            $safeRows[] = $r;
        }

        if ($action === 'landing-content' && $hasLandingLogo) {
            $safeRows = array_values(array_filter($safeRows, fn($r) => ($r['key'] ?? '') !== 'logo_image'));
        }

        echo json_encode(['content' => $safeRows]);
        exit;
    }

    if ($method === 'POST') {
        requireAdminAuth();
        $items = $body['content'] ?? ($body['items'] ?? null);

        if (!is_array($items) && is_array($body)) {
            $items = [];
            foreach ($body as $k => $v) {
                if ($k === 'content' || $k === 'items') continue;
                $items[] = ['key' => $k, 'value' => is_string($v) ? $v : json_encode($v)];
            }
        }

        if (is_array($items)) {
            $stmt = $pdo->prepare("INSERT INTO settings_rows (setting_key, setting_value)
                                   VALUES (:key, :value)
                                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP");
            foreach ($items as $item) {
                $k = $item['key'] ?? '';
                $v = $item['value'] ?? '';
                if ($k) {
                    $stmt->execute([':key' => $k, ':value' => (string)$v]);
                }
            }
        }

        echo json_encode(['ok' => true]);
        exit;
    }
}

if ($action === 'landing-content-reset' || $action === 'landing-content/reset') {
    requireAdminAuth();
    $pdo->exec("DELETE FROM settings_rows WHERE setting_key LIKE 'landing_%' OR setting_key LIKE 'hero_%'");
    echo json_encode(['ok' => true]);
    exit;
}

// -------------------------------------------------------------
// GESTIÓN DE CATEGORÍAS (/api/auth/categories, /api/auth/categories-reassign)
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

require __DIR__ . '/handlers/diagnostics.php';
require __DIR__ . '/handlers/products.php';
require __DIR__ . '/handlers/content.php';
require __DIR__ . '/handlers/orders.php';
require __DIR__ . '/handlers/auth.php';

// Acción no encontrada
http_response_code(404);
echo json_encode(['error' => 'Endpoint no encontrado: ' . $action]);
