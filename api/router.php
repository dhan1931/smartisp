<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Admin-Token, Idempotency-Key');
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

require_once __DIR__ . '/lib/db.php';

// Archivos subidos: se sirven antes de abrir sesión y base, cacheables (nombres únicos por subida).
if ($earlyAction === 'serve-upload' && in_array($requestMethod, ['GET', 'HEAD'], true)) {
    $category = (string)($_GET['category'] ?? '');
    $file = (string)($_GET['file'] ?? '');
    $uploadTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
    $path = in_array($category, ['campaigns', 'products', 'categories', 'brands'], true) && preg_match('/^[A-Za-z0-9._-]+$/', $file)
        && isset($uploadTypes[$ext])
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
    header('Content-Type: ' . $uploadTypes[$ext]);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . $size);
    if ($requestMethod !== 'HEAD') readfile($path);
    exit;
}

if (in_array($earlyAction, ['product-image', 'proxy-image'], true) && in_array($requestMethod, ['GET', 'HEAD'], true)
    && trim((string)($_GET['t'] ?? ($_GET['token'] ?? ($_GET['img'] ?? '')))) !== '') {
    $action = $earlyAction;
    $pdo = null;
    require __DIR__ . '/handlers/product-image.php';
}

// Archivo de verificación de IndexNow.
if ($earlyAction === 'indexnow-key') {
    require_once __DIR__ . '/lib/shop.php';
    $key = smartispIndexNowKey();
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    echo $key;
    exit;
}

// En GET la sesión se lee y se libera al instante para no serializar peticiones paralelas.
define('SMARTISP_SESSION_READONLY', in_array($requestMethod, ['GET', 'HEAD'], true) && $earlyAction !== 'logout');

if (session_status() === PHP_SESSION_NONE) {
    // Rechaza ids de sesión que el servidor no emitió (fijación de sesión).
    ini_set('session.use_strict_mode', '1');
    if (!headers_sent()) {
        // LiteSpeed/Hostinger puede terminar TLS en un proxy y dejar HTTPS vacío.
        $isHttps = (isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) === 'on')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        @session_set_cookie_params([
            'lifetime' => 86400 * 30,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    @session_start(SMARTISP_SESSION_READONLY ? ['read_and_close' => true] : []);
}

require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/roles.php';
require_once __DIR__ . '/lib/audit.php';

$pdo = getDbConnection();

// El rol de administrador vive únicamente en users_rows.role, puesto por un login real
// (contraseña verificada) o, en el futuro, por una acción explícita de gestión de usuarios.
// Antes había aquí una lista de emails hardcodeada que: (a) en cada petición forzaba
// role='customer' para cualquier email fuera de la lista y role='admin' para los de la lista,
// sin importar lo que dijera la base; y (b) se usaba para otorgar una contraseña universal
// y para decidir el rol otra vez después de autenticar. Todo eso se eliminó.

function slugify(string $text): string {
    return smartispSlug($text, 80, 'articulo');
}

// Acciones que escriben o destruyen: solo por POST, para que un enlace o una imagen de otro
// sitio (GET con la cookie de sesión) no las dispare.
function smartispRequirePost(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
        exit;
    }
}

// Sin la migración 038 la columna no existe y la revocación de sesiones queda inactiva.
function smartispUsersHaveTokenVersion(PDO $pdo): bool {
    return smartispUsersHaveColumn($pdo, 'token_version');
}

// active y last_login_at llegan con la migración 044.
function smartispUsersHaveColumn(PDO $pdo, string $column): bool {
    try {
        return in_array($column, smartispTableColumns($pdo, 'users_rows'), true);
    } catch (Throwable $e) {
        return false;
    }
}

function smartispAuthTokenVersion(?int $set = null): int {
    static $version = 0;
    if ($set !== null) $version = $set;
    return $version;
}

// IP para los límites de intentos. Solo REMOTE_ADDR: X-Forwarded-For y CF-Connecting-IP los
// escribe el cliente, y con cambiarlos en cada petición el límite no se aplicaba nunca.
function smartispClientIp(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
}

// Ventana deslizante guardada en el tmp del servidor; $key es la IP salvo que se indique otra
// (p. ej. un correo). Devuelve true si ya se alcanzó el límite; si no y $record, registra el
// intento. El flock evita que dos peticiones simultáneas lean el mismo conteo y ambas pasen.
function smartispRateLimited(string $bucket, int $maxAttempts, int $windowSeconds, ?string $key = null, bool $record = true): bool {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $bucket . '_' . md5($key ?? smartispClientIp()) . '.json';
    $handle = @fopen($file, 'c+');
    if (!$handle) return false; // Sin tmp escribible no se bloquea; queda en el log.
    flock($handle, LOCK_EX);
    $now = time();
    $attempts = json_decode((string)stream_get_contents($handle), true);
    $attempts = array_values(array_filter(is_array($attempts) ? $attempts : [], fn($t) => ($now - (int)$t) < $windowSeconds));
    $limited = count($attempts) >= $maxAttempts;
    if (!$limited && $record) {
        $attempts[] = $now;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($attempts));
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    return $limited;
}

function smartispRespondRateLimited(int $windowSeconds, string $message): void {
    http_response_code(429);
    header('Retry-After: ' . $windowSeconds);
    echo json_encode(['ok' => false, 'error' => $message, 'retry_after' => $windowSeconds]);
    exit;
}

// Corta la petición con 429 si ya se alcanzó el límite; si no, registra este intento.
function smartispEnforceRateLimit(string $bucket, int $maxAttempts, int $windowSeconds, string $message, ?string $key = null): void {
    if (smartispRateLimited($bucket, $maxAttempts, $windowSeconds, $key)) {
        smartispRespondRateLimited($windowSeconds, $message);
    }
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
        'tv'    => (int)($user['token_version'] ?? smartispAuthTokenVersion()),
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
    $claimedVersion = (int)($_SESSION['tv'] ?? 0);
    $fromSession = $userId !== '';

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
                            $claimedVersion = (int)($decoded['tv'] ?? 0);
                        }
                    }
                }
            }
        }
    }

    if ($userId === '') return null;

    // La sesión conserva identidad, no permisos: recargar rol y perfil desde la fuente
    // autorizada en cada petición invalida permisos revocados y corrige sesiones antiguas.
    $pdo = getDbConnection();
    $hasVersion = false;
    $freshUser = false;
    foreach ([', token_version, active', ', token_version', ''] as $extra) {
        try {
            $stmt = $pdo->prepare('SELECT id, email, name, surname, phone, role' . $extra . ' FROM users_rows WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $freshUser = $stmt->fetch(PDO::FETCH_ASSOC);
            $hasVersion = $extra !== '';
            break;
        } catch (PDOException $e) {
            // 1054: columna inexistente (migraciones 038/044 sin aplicar); se sigue sin revocación o sin estado.
            if (($e->errorInfo[1] ?? null) !== 1054 || $extra === '') throw $e;
        }
    }
    // token_version sube al cambiar o restablecer la contraseña o al cerrar sesiones desde Equipo.
    $currentVersion = (int)($freshUser['token_version'] ?? 0);
    $disabled = $freshUser && isset($freshUser['active']) && (int)$freshUser['active'] === 0;
    if (!$freshUser || $disabled || ($hasVersion && $currentVersion !== $claimedVersion)) {
        if ($fromSession) unset($_SESSION['user'], $_SESSION['tv']);
        return null;
    }
    unset($freshUser['token_version'], $freshUser['active']);
    smartispAuthTokenVersion($currentVersion);
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

// Registro central de actividad: toda escritura hecha por un admin, con el código HTTP final.
// Login, contraseñas y cierres de sesión los registra auth.php con su propio detalle; las acciones
// de la tienda (comprar, favoritos, cotizar) no son administración aunque las haga un admin.
const SMARTISP_AUDIT_SKIP_ACTIONS = ['login', 'logout', 'register', 'change-password', 'change_password', 'reset-password', 'request-password-reset', 'revoke-other-sessions', 'orders', 'customer-wishlist', 'service-quote-requests'];
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $action !== '' && !in_array($action, SMARTISP_AUDIT_SKIP_ACTIONS, true)) {
    $auditQuery = $_GET;
    $auditBody = is_array($body) ? $body : [];
    register_shutdown_function(static function () use ($pdo, $action, $method, $auditQuery, $auditBody) {
        try {
            $note = smartispAuditNote();
            if (!empty($note['skip'])) return;
            $user = getAuthUser();
            if (!$user || !isAdminUser($user)) return;
            unset($auditQuery['action'], $auditQuery['route']);
            smartispAuditWrite($pdo, [
                'user_id' => $user['id'] ?? null,
                'user_email' => $user['email'] ?? null,
                'action' => $action,
                'method' => $method,
                'target' => $note['target'] ?? smartispAuditTarget($auditQuery, $auditBody),
                'summary' => $note['summary'] ?? smartispAuditSummary($auditQuery + $auditBody, $_FILES ?? []),
                'status' => http_response_code() ?: 200,
            ]);
        } catch (Throwable $e) {
            error_log('SmartISP audit: ' . $e->getMessage());
        }
    });
}

// Los avisos de la barra lateral suman los intentos fallidos de acceso al panel sin tocar insights.php.
if ($action === 'admin-insights-alerts' && isAdminUser()) {
    $loginAlerts = smartispAuditLoginAlerts($pdo);
    ob_start(static function (string $buffer) use ($loginAlerts) {
        $data = json_decode($buffer, true);
        return is_array($data) && !isset($data['error']) ? json_encode($data + $loginAlerts) : $buffer;
    });
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
require __DIR__ . '/handlers/insights.php';
require __DIR__ . '/handlers/auth.php';
require __DIR__ . '/handlers/team.php';

// Acción no encontrada
http_response_code(404);
echo json_encode(['error' => 'Endpoint no encontrado: ' . $action]);
