<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Admin-Token');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// Manejador global de excepciones para evitar cualquier error 500 vacío
set_exception_handler(function (Throwable $e) {
    error_log('SmartISP API exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Ocurrió un error interno. Inténtalo nuevamente.']);
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
require_once __DIR__ . '/roles.php';

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
    if (smartispNormalizeRole($user['role'] ?? '') !== 'admin') {
        throw new RuntimeException('Solo se emiten tokens administrativos a usuarios autorizados.');
    }
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
    $userId = is_array($user) ? trim((string)($user['id'] ?? '')) : '';

    // Aceptar tokens heredados solo por cabeceras; nunca en URL/body donde acabarían en logs.
    if ($userId === '') {
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
                                if ($currentUser) $userId = trim((string)$currentUser['id']);
                            } catch (Throwable $e) {
                                error_log('No se pudo validar el usuario del token: ' . $e->getMessage());
                            }
                        }
                    }
                }
            }
        }
    }

    if ($userId === '') return null;

    // La sesión conserva identidad, no permisos: recargar rol y perfil desde la fuente
    // autorizada en cada petición invalida permisos revocados y corrige sesiones antiguas.
    $stmt = getDbConnection()->prepare('SELECT id, email, name, surname, phone, role FROM users_rows WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $freshUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$freshUser) {
        unset($_SESSION['user']);
        return null;
    }
    $freshUser['role'] = smartispNormalizeRole($freshUser['role'] ?? 'customer');
    $_SESSION['user'] = $freshUser;
    return $freshUser;
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
    return smartispNormalizeRole($user['role'] ?? '') === 'admin';
}

function requireAdminAuth(): void {
    $user = getAuthUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Inicia sesión para continuar.']);
        exit;
    }
    if (!isAdminUser($user)) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'Esta cuenta no tiene permisos para administrar la tienda.'
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
function getDynamicCategoriesList(PDO $pdo, bool $visibleOnly = false): array {
    $pTable = getProductsTableName($pdo);
    // 1. Obtener todas las macrocategorías, subcategorías y conteo real de productos
    $visibilityFilter = $visibleOnly ? 'visible = 1 AND ' : '';
    $prodCatsStmt = $pdo->query("SELECT category, subcategory, COUNT(*) as p_count FROM `$pTable` WHERE $visibilityFilter category IS NOT NULL AND TRIM(category) != '' GROUP BY category, subcategory");
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
            `banner_image_url` VARCHAR(500) NULL,
            `banner_alt` VARCHAR(200) NULL,
            `description` VARCHAR(500) NULL,
            `icon` VARCHAR(64) NOT NULL DEFAULT 'package',
            `keywords` LONGTEXT NULL,
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
            'bannerImageUrl' => (string)($row['banner_image_url'] ?? ''),
            'bannerAlt' => (string)($row['banner_alt'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'icon' => (string)($row['icon'] ?? 'package'),
            'keywords' => is_array($decodedKeywords = json_decode((string)($row['keywords'] ?? ''), true))
                ? $decodedKeywords
                : array_values(array_filter(array_map('trim', explode(',', (string)($row['keywords'] ?? ''))))),
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
                'bannerImageUrl' => '',
                'bannerAlt' => '',
                'description' => '',
                'icon' => 'package',
                'keywords' => [],
                'productCount'  => $info['count'],
                'updated_at'    => date('Y-m-d H:i:s')
            ];
            $cats[] = $newCat;
            $toInsert[] = $newCat;
        }
    }

    if (!empty($toInsert)) {
        try {
            $ins = $pdo->prepare("INSERT INTO categories_rows (id, name, subcategories, banner_image_url, banner_alt, description) VALUES (:id, :name, :sub, :banner, :alt, :description) ON DUPLICATE KEY UPDATE name = VALUES(name), subcategories = VALUES(sub)");
            foreach ($toInsert as $tc) {
                $ins->execute([
                    ':id'   => $tc['id'],
                    ':name' => $tc['name'],
                    ':sub'  => json_encode($tc['subcategories'], JSON_UNESCAPED_UNICODE),
                    ':banner' => '',
                    ':alt' => '',
                    ':description' => ''
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
    require __DIR__ . '/handlers/products.php';
}

// -------------------------------------------------------------
// 2. GESTIÓN DE PRODUCTOS PARA EL EDITOR (/api/auth/admin-products)
// -------------------------------------------------------------
if ($action === 'admin-products') {
    requireAdminAuth();
    $pTable = getProductsTableName($pdo);

    if ($method === 'GET') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
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
            foreach (['name', 'sku', 'cat', 'sub', 'desc'] as $field) {
                $params[':q_' . $field] = $likeSearch;
            }
        }

        $whereSql = implode(' AND ', $where);
        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM `$pTable` WHERE $whereSql");
        $totalStmt->execute($params);
        $total = (int)$totalStmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM `$pTable` WHERE $whereSql ORDER BY created_at DESC, id ASC LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) $stmt->bindValue($key, $value);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $products = array_map('normalizeProductRow', $stmt->fetchAll());

        echo json_encode([
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
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
            $savedVisuals = [];
            $existingVisuals = $pdo->query('SELECT id, name, banner_image_url, banner_alt, description, icon, keywords FROM categories_rows');
            foreach ($existingVisuals->fetchAll(PDO::FETCH_ASSOC) as $existing) {
                $visual = [
                    'bannerImageUrl' => (string)($existing['banner_image_url'] ?? ''),
                    'bannerAlt' => (string)($existing['banner_alt'] ?? ''),
                    'description' => (string)($existing['description'] ?? ''),
                    'icon' => (string)($existing['icon'] ?? 'package'),
                    'keywords' => (string)($existing['keywords'] ?? '[]')
                ];
                $savedVisuals['id:' . (string)$existing['id']] = $visual;
                $savedVisuals['name:' . strtolower(trim((string)$existing['name']))] = $visual;
            }
            $pdo->exec("DELETE FROM categories_rows");
            $stmt = $pdo->prepare("INSERT INTO categories_rows (id, name, subcategories, banner_image_url, banner_alt, description, icon, keywords) VALUES (:id, :name, :sub, :banner, :alt, :description, :icon, :keywords)");
            $clipCategoryText = static function (string $value, int $limit): string {
                if (function_exists('mb_substr')) return mb_substr($value, 0, $limit, 'UTF-8');
                if (preg_match('/^.{0,' . $limit . '}/us', $value, $match)) return $match[0];
                return substr($value, 0, $limit);
            };
            $knownCategoryIcons = ['laptop','network','cable','server','shield-check','zap','monitor','hard-drive','headphones','printer','phone-call','file-code','package','cpu','wifi','camera'];
            foreach ($cats as $c) {
                $cId = $c['id'] ?? uniqid('cat_');
                $cName = $c['name'] ?? 'General';
                $sub = is_array($c['subcategories'] ?? null) ? json_encode($c['subcategories']) : (string)($c['subcategories'] ?? '');
                $previousVisual = $savedVisuals['id:' . (string)$cId] ?? $savedVisuals['name:' . strtolower(trim((string)$cName))] ?? [];
                $banner = trim((string)($c['bannerImageUrl'] ?? $previousVisual['bannerImageUrl'] ?? ''));
                $safeBanner = str_starts_with($banner, '/') && !str_starts_with($banner, '//') && !str_contains($banner, '..') && !str_contains($banner, '\\');
                $safeBanner = $safeBanner || (filter_var($banner, FILTER_VALIDATE_URL) !== false && strtolower((string)parse_url($banner, PHP_URL_SCHEME)) === 'https');
                if (!$safeBanner || preg_match('/[\\x00-\\x1F\\x7F]/', $banner)) $banner = '';
                $stmt->execute([
                    ':id' => $cId,
                    ':name' => $cName,
                    ':sub' => $sub,
                    ':banner' => $clipCategoryText($banner, 500),
                    ':alt' => $clipCategoryText(trim((string)($c['bannerAlt'] ?? $previousVisual['bannerAlt'] ?? '')), 200),
                    ':description' => $clipCategoryText(trim((string)($c['description'] ?? $previousVisual['description'] ?? '')), 500),
                    ':icon' => in_array((string)($c['icon'] ?? $previousVisual['icon'] ?? 'package'), $knownCategoryIcons, true) ? (string)($c['icon'] ?? $previousVisual['icon'] ?? 'package') : 'package',
                    ':keywords' => json_encode(array_values(array_unique(array_slice(array_filter(array_map(static function ($keyword) {
                        $keyword = trim((string)$keyword);
                        return function_exists('mb_strtolower') ? mb_strtolower($keyword, 'UTF-8') : strtolower($keyword);
                    }, is_array($c['keywords'] ?? null) ? $c['keywords'] : [])), 0, 100))), JSON_UNESCAPED_UNICODE)
                ]);
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

if (in_array($action, ['store-campaigns', 'admin-store-campaigns', 'upload-campaign-image'], true)) {
    require __DIR__ . '/handlers/campaigns.php';
}

require __DIR__ . '/handlers/diagnostics.php';
require __DIR__ . '/handlers/products.php';
require __DIR__ . '/handlers/content.php';
require __DIR__ . '/handlers/orders.php';
require __DIR__ . '/handlers/auth.php';

// Acción no encontrada
http_response_code(404);
echo json_encode(['error' => 'Endpoint no encontrado: ' . $action]);
