<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db.php';

$pdo = getDbConnection();
$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true) ?: $_POST;

$action = $_GET['action'] ?? ($_GET['route'] ?? '');
$action = trim(str_replace('auth/', '', $action), '/');
$method = $_SERVER['REQUEST_METHOD'];

// Diagnóstico de base de datos
if ($action === 'test-db' || $action === 'test-products' || $action === 'test-supabase') {
    if (!$pdo) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'rowsFound' => 0,
            'error' => 'No se pudo conectar a la base de datos MySQL en Hostinger.'
        ]);
        exit;
    }

    try {
        $pTable = getProductsTableName($pdo);
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `$pTable`");
        $count = (int)$stmt->fetchColumn();

        echo json_encode([
            'success' => true,
            'database' => 'mysql',
            'tableUsed' => $pTable,
            'rowsFound' => $count
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'rowsFound' => 0,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

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
// 1. CATÁLOGO PÚBLICO (/api/auth/catalog)
// -------------------------------------------------------------
if ($action === 'catalog' && $method === 'GET') {
    try {
        $pTable = getProductsTableName($pdo);
        
        // Obtener productos visibles
        $stmt = $pdo->query("SELECT * FROM `$pTable`");
        $rawProducts = $stmt->fetchAll();
        $products = [];
        foreach ($rawProducts as $p) {
            $norm = normalizeProductRow($p);
            if ($norm['visible']) {
                $products[] = $norm;
            }
        }

        // Obtener configuraciones del panel de control
        $stmtContent = $pdo->query("SELECT setting_key as `key`, setting_value as `value` FROM settings_rows");
        $content = $stmtContent->fetchAll();

        // Obtener categorías personalizadas
        $stmtCat = $pdo->query("SELECT * FROM categories_rows");
        $categories = $stmtCat->fetchAll();

        echo json_encode([
            'products'   => $products,
            'content'    => $content,
            'categories' => $categories
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'products' => [], 'content' => []]);
    }
    exit;
}

// -------------------------------------------------------------
// 2. GESTIÓN DE PRODUCTOS PARA EL EDITOR (/api/auth/admin-products)
// -------------------------------------------------------------
if ($action === 'admin-products') {
    $pTable = getProductsTableName($pdo);

    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT * FROM `$pTable` ORDER BY created_at DESC");
        $rows = $stmt->fetchAll();
        $products = array_map('normalizeProductRow', $rows);
        echo json_encode(['products' => $products]);
        exit;
    }

    if ($method === 'POST') {
        $id = $body['id'] ?? uniqid('prod_');
        $name = trim($body['name'] ?? '');
        $description = trim($body['description'] ?? '');
        $price = (float)($body['price'] ?? 0);
        $category = trim($body['category'] ?? 'General');
        $subcategory = trim($body['subcategory'] ?? '');
        $imageUrl = trim($body['imageUrl'] ?? ($body['image_url'] ?? ''));
        $externalUrl = trim($body['externalUrl'] ?? ($body['external_url'] ?? ''));
        $sku = trim($body['sku'] ?? '');
        $visible = isset($body['visible']) ? ($body['visible'] ? 1 : 0) : 1;

        if (!$name) {
            http_response_code(400);
            echo json_encode(['error' => 'El nombre del producto es obligatorio.']);
            exit;
        }

        $sql = "INSERT INTO `$pTable` (id, name, description, price, category, subcategory, image_url, external_url, sku, visible)
                VALUES (:id, :name, :description, :price, :category, :subcategory, :image_url, :external_url, :sku, :visible)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    description = VALUES(description),
                    price = VALUES(price),
                    category = VALUES(category),
                    subcategory = VALUES(subcategory),
                    image_url = VALUES(image_url),
                    external_url = VALUES(external_url),
                    sku = VALUES(sku),
                    visible = VALUES(visible),
                    updated_at = CURRENT_TIMESTAMP";

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

        echo json_encode(['ok' => true, 'id' => $id]);
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
// 3. IMPORTACIÓN MASIVA DE EXCEL / CSV (/api/auth/import-products)
// -------------------------------------------------------------
if ($action === 'import-products' || $action === 'import-excel') {
    $pTable = getProductsTableName($pdo);
    $products = $body['products'] ?? [];

    if (!is_array($products) || empty($products)) {
        http_response_code(400);
        echo json_encode(['error' => 'No se proporcionaron productos para importar.']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $sql = "INSERT INTO `$pTable` (id, name, description, price, category, subcategory, image_url, external_url, sku, visible)
                VALUES (:id, :name, :description, :price, :category, :subcategory, :image_url, :external_url, :sku, :visible)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    description = VALUES(description),
                    price = VALUES(price),
                    category = VALUES(category),
                    subcategory = VALUES(subcategory),
                    image_url = VALUES(image_url),
                    external_url = VALUES(external_url),
                    sku = VALUES(sku),
                    visible = VALUES(visible)";
        $stmt = $pdo->prepare($sql);

        $inserted = 0;
        foreach ($products as $p) {
            $id = $p['id'] ?? uniqid('prod_');
            $name = trim($p['name'] ?? '');
            if (!$name) continue;

            $stmt->execute([
                ':id'           => $id,
                ':name'         => $name,
                ':description'  => trim($p['description'] ?? ''),
                ':price'        => (float)($p['price'] ?? 0),
                ':category'     => trim($p['category'] ?? 'General'),
                ':subcategory'  => trim($p['subcategory'] ?? ''),
                ':image_url'    => trim($p['imageUrl'] ?? ($p['image_url'] ?? '')),
                ':external_url' => trim($p['externalUrl'] ?? ($p['external_url'] ?? '')),
                ':sku'          => trim($p['sku'] ?? ''),
                ':visible'      => isset($p['visible']) ? ($p['visible'] ? 1 : 0) : 1
            ]);
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

// -------------------------------------------------------------
// 4. CONFIGURACIONES DEL PANEL DE CONTROL (/api/auth/landing-content & /api/auth/site-content)
// -------------------------------------------------------------
if ($action === 'landing-content' || $action === 'site-content') {
    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT setting_key as `key`, setting_value as `value` FROM settings_rows");
        $rows = $stmt->fetchAll();
        echo json_encode(['content' => $rows]);
        exit;
    }

    if ($method === 'POST') {
        $items = $body['content'] ?? ($body['items'] ?? null);

        // Si es un objeto asociativo directo { key1: val1, key2: val2 }
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

// -------------------------------------------------------------
// 5. PEDIDOS Y COTIZACIONES (/api/auth/orders)
// -------------------------------------------------------------
if ($action === 'orders') {
    if ($method === 'POST') {
        $orderId = uniqid('ord_');
        $customerName = trim($body['customerName'] ?? ($body['name'] ?? ''));
        $customerEmail = trim($body['customerEmail'] ?? ($body['email'] ?? ''));
        $customerPhone = trim($body['customerPhone'] ?? ($body['phone'] ?? ''));
        $items = json_encode($body['items'] ?? []);
        $total = (float)($body['total'] ?? 0);

        $stmt = $pdo->prepare("INSERT INTO orders_rows (id, customer_name, customer_email, customer_phone, items, total, status)
                               VALUES (:id, :name, :email, :phone, :items, :total, 'pending')");
        $stmt->execute([
            ':id'    => $orderId,
            ':name'  => $customerName,
            ':email' => $customerEmail,
            ':phone' => $customerPhone,
            ':items' => $items,
            ':total' => $total
        ]);

        echo json_encode(['ok' => true, 'orderId' => $orderId]);
        exit;
    }

    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT * FROM orders_rows ORDER BY created_at DESC");
        echo json_encode(['orders' => $stmt->fetchAll()]);
        exit;
    }
}

// -------------------------------------------------------------
// 6. AUTENTICACIÓN (/api/auth/login, /api/auth/register, /api/auth/me)
// -------------------------------------------------------------
if ($action === 'login' && $method === 'POST') {
    $uTable = getUsersTableName($pdo);
    $email = trim(strtolower($body['email'] ?? ''));
    $password = (string)($body['password'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM `$uTable` WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        unset($user['password_hash']);
        echo json_encode(['user' => $user]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Correo o contraseña incorrectos.']);
    }
    exit;
}

if ($action === 'register' && $method === 'POST') {
    $uTable = getUsersTableName($pdo);
    $id = uniqid('usr_');
    $name = trim($body['name'] ?? '');
    $surname = trim($body['surname'] ?? '');
    $email = trim(strtolower($body['email'] ?? ''));
    $phone = trim($body['phone'] ?? '');
    $password = (string)($body['password'] ?? '');

    if (!$email || strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos incompletos o contraseña demasiado corta.']);
        exit;
    }

    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO `$uTable` (id, email, password_hash, name, surname, phone, role)
                               VALUES (:id, :email, :hash, :name, :surname, :phone, 'customer')");
        $stmt->execute([
            ':id'      => $id,
            ':email'   => $email,
            ':hash'    => $hash,
            ':name'    => $name,
            ':surname' => $surname,
            ':phone'   => $phone
        ]);

        echo json_encode(['user' => [
            'id'      => $id,
            'name'    => $name,
            'surname' => $surname,
            'email'   => $email,
            'phone'   => $phone,
            'role'    => 'customer'
        ]]);
    } catch (PDOException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'El correo electrónico ya está registrado.']);
    }
    exit;
}

// Acción no encontrada
http_response_code(404);
echo json_encode(['error' => 'Endpoint no encontrado: ' . $action]);
