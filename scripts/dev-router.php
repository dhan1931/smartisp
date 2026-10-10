<?php
// Enrutador de desarrollo para `php -S` (ver scripts/dev-php.ps1).
// Emula las reglas del .htaccess, no sirve archivos internos y, si SMARTISP_READONLY=1,
// bloquea por HTTP las escrituras de la API para no modificar una base real desde local.
declare(strict_types=1);

$root = dirname(__DIR__);
$uri = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$readonly = getenv('SMARTISP_READONLY') === '1';

// Nunca se sirven código, configuración ni archivos internos.
$deny = '#^/(?:\.|api/(?:config|db|mailer)\.php|src/|scripts/|ops/|docs/|data/|legacy/|node_modules/)'
    . '|^/(?:server\.js|db\.js|package(?:-lock)?\.json|vite\.config\.js|vercel\.json)$'
    . '|\.(?:env|token|yml|yaml|md|sql|gz|dump|bak|log)$#i';
if (preg_match($deny, $uri)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

// API: /api/router.php?action=X, /api/auth/X y /api/X llegan todas a api/router.php.
$action = null;
if ($uri === '/api/router.php' || $uri === '/api/router') {
    $action = (string)($_GET['action'] ?? '');
} elseif (preg_match('#^/api/(?:auth/)?(.+)$#', $uri, $m)) {
    $action = $m[1];
}
if ($action !== null) {
    $_GET['action'] = $action;
    $_REQUEST['action'] = $action;
    $_SERVER['QUERY_STRING'] = http_build_query($_GET);

    $safeActions = ['login', 'logout', 'me'];
    if ($readonly && !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !in_array($action, $safeActions, true)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Modo solo lectura: las escrituras están bloqueadas en este entorno local (base real). Use -Write para permitirlas.',
            'readonly' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    require $root . '/api/router.php';
    exit;
}

// Rutas amigables del .htaccess.
if ($uri === '/sitemap-products.xml') {
    require $root . '/sitemap-products.php';
    exit;
}
if ($uri === '/sitemap-categories.xml') {
    require $root . '/sitemap-categories.php';
    exit;
}
if ($uri === '/productos-destacados') {
    require $root . '/productos-destacados.php';
    exit;
}
if ($uri === '/categorias-destacadas') {
    // tienda.html detecta el modo catalogo por location.pathname (ver .htaccess).
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/tienda.html');
    exit;
}
if ($uri === '/servicios') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/servicios.html');
    exit;
}
if ($uri === '/nosotros') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/nosotros.html');
    exit;
}
if (preg_match('#^/categoria/([^/]+)/?$#', $uri, $m)) {
    $_GET['slug'] = $m[1];
    require $root . '/categoria.php';
    exit;
}
if (preg_match('#^/productos?/([^/]+)/?$#', $uri, $m)) {
    $_GET['slug'] = $m[1];
    require $root . '/producto.php';
    exit;
}
if (in_array(rtrim($uri, '/'), ['/productos', '/producto'], true)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/tienda.html');
    exit;
}
if ($uri === '/') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/index.html');
    exit;
}

// Archivos reales (html, imágenes, producto.php, etc.).
if (is_file($root . $uri)) {
    return false;
}

http_response_code(404);
echo 'Not found';
