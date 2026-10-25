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

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$earlyAction = trim(explode('?', str_replace('auth/', '', (string)($_GET['action'] ?? ($_GET['route'] ?? ''))))[0], '/');

require_once __DIR__ . '/db.php';

// -------------------------------------------------------------
// Archivos subidos (banners de campañas, fotos de producto) que viven fuera de public_html (ver
// getSmartispUploadsDir en db.php) y por eso pasan por PHP. Se atienden ANTES de abrir sesión y
// base: antes cada imagen de la portada abría ambas sin usarlas. Los nombres son aleatorios por
// subida (un archivo nuevo = nombre nuevo), así que se pueden cachear como inmutables, y con
// ETag/Last-Modified el navegador revalida con un 304 en vez de volver a bajar la imagen.
if ($earlyAction === 'serve-upload' && in_array($requestMethod, ['GET', 'HEAD'], true)) {
    $category = (string)($_GET['category'] ?? '');
    $file = (string)($_GET['file'] ?? '');
    $path = in_array($category, ['campaigns', 'products', 'categories', 'brands'], true) && preg_match('/^[A-Za-z0-9._-]+$/', $file)
        ? getSmartispUploadsDir($category) . '/' . $file
        : '';
    if ($path === '' || !is_file($path)) {
        http_response_code(404);
        echo json_encode(['error' => 'Imagen no encontrada.']);
        exit;
    }
    $mtime = (int)filemtime($path);
    $size = (int)filesize($path);
    $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
    header_remove('Pragma');
    header_remove('Expires');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    $ifModifiedSince = strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
    if (($ifNoneMatch !== '' && $ifNoneMatch === $etag) || ($ifNoneMatch === '' && $ifModifiedSince >= $mtime)) {
        http_response_code(304);
        exit;
    }
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    header('Content-Type: ' . (['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'][$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . $size);
    if ($requestMethod !== 'HEAD') readfile($path);
    exit;
}

// Las peticiones de lectura (GET) leen la sesión y sueltan su bloqueo al instante
// (read_and_close). Con sesiones en archivo, PHP atiende de a una las peticiones de un mismo
// usuario mientras alguna tenga la sesión abierta: el dashboard dispara 6 consultas en paralelo
// y la tienda varias, y esperaban en fila. Lo único que un GET escribía en la sesión era el
// refresco del usuario (se relee de la base en cada petición) y el carrito de deseos vacío
// inicial; login, registro, logout y la lista de deseos escriben por POST/DELETE.
define('SMARTISP_SESSION_READONLY', in_array($requestMethod, ['GET', 'HEAD'], true) && $earlyAction !== 'logout');

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
    @session_start(SMARTISP_SESSION_READONLY ? ['read_and_close' => true] : []);
}

require_once __DIR__ . '/shop.php';
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
    // Una petición llama esto varias veces (requireAdminAuth, isAdminUser, actor del pedido...):
    // se resuelve una vez. Login/logout no la vuelven a llamar después de cambiar la sesión.
    static $resolved = false, $cached = null;
    if ($resolved) return $cached;
    $resolved = true;
    return $cached = resolveAuthUser();
}

function resolveAuthUser(): ?array {
    // En modo lectura la sesión ya se leyó y se cerró a propósito: no reabrirla (volvería a bloquear).
    if (session_status() === PHP_SESSION_NONE && !SMARTISP_SESSION_READONLY) {
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
                            // La existencia del usuario se comprueba abajo con la misma consulta.
                            $userId = trim((string)$decoded['id']);
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
$method = $requestMethod;

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

if (in_array($action, ['store-campaigns', 'admin-store-campaigns', 'upload-campaign-image'], true)) {
    require __DIR__ . '/handlers/campaigns.php';
}

// 'categories' y 'categories-reset' vivian tambien aca arriba, duplicados y desactualizados
// (sin macro_group_id ni los campos nuevos de familia) -- como este bloque corria ANTES del
// require de abajo, tapaba por completo la version real/mantenida de handlers/products.php.
// Guardar categorias desde el admin pasaba por el duplicado viejo y borraba en silencio el
// macro_group_id de todas las categorias en cada guardado. Se elimino el duplicado.
// Lo mismo pasaba con admin-products, upload-image, la importacion y admin-content: su copia
// aqui tapaba la de los handlers. admin-content guardaba el SMTP en settings_rows, que el mailer
// ya no lee (vive en mail_settings). Cada accion vive ahora en un solo handler.
require __DIR__ . '/handlers/diagnostics.php';
require __DIR__ . '/handlers/products.php';
require __DIR__ . '/handlers/content.php';
require __DIR__ . '/handlers/orders.php';
require __DIR__ . '/handlers/customers.php';
require __DIR__ . '/handlers/quotes.php';
require __DIR__ . '/handlers/inventory.php';
require __DIR__ . '/handlers/analytics.php';
require __DIR__ . '/handlers/auth.php';

// Acción no encontrada
http_response_code(404);
echo json_encode(['error' => 'Endpoint no encontrado: ' . $action]);
