<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Admin-Token, X-Admin-Email');
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
    $secretKey = 'smartisp_admin_jwt_secret_key_2026';
    $payload = [
        'id'    => $user['id'] ?? uniqid('usr_'),
        'email' => strtolower(trim((string)($user['email'] ?? ''))),
        'name'  => $user['name'] ?? '',
        'role'  => $user['role'] ?? 'admin',
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
    $isAdminSession = (is_array($user) && !empty($user['role']) && $user['role'] === 'admin');
    if (!$isAdminSession) {
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
            $secretKey = 'smartisp_admin_jwt_secret_key_2026';
            $parts = explode('.', $token);
            if (count($parts) === 2) {
                list($payloadB64, $sig) = $parts;
                $expectedSig = hash_hmac('sha256', $payloadB64, $secretKey);
                if (hash_equals($expectedSig, $sig)) {
                    $decoded = json_decode(base64_decode($payloadB64), true);
                    if (is_array($decoded) && !empty($decoded['email'])) {
                        if (empty($decoded['exp']) || $decoded['exp'] > time()) {
                            $user = $decoded;
                            $_SESSION['user'] = $user;
                            $isAdminSession = true;
                        }
                    }
                }
            }
        }
    }

    // SEC-001 (corregido): antes había aquí un "respaldo" que otorgaba sesión de administrador
    // solo con la cabecera X-Admin-Email o ?admin_email=, sin contraseña ni token. Se eliminó:
    // la única forma de llegar a este punto como admin es sesión real o token firmado válido (arriba).

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
    return ($user['role'] ?? '') === 'admin';
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

require __DIR__ . '/handlers/diagnostics.php';
require __DIR__ . '/handlers/products.php';
require __DIR__ . '/handlers/content.php';
require __DIR__ . '/handlers/orders.php';
require __DIR__ . '/handlers/auth.php';


// Acción no encontrada
http_response_code(404);
echo json_encode(['error' => 'Endpoint no encontrado: ' . $action]);
