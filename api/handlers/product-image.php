<?php
// Con token, el router lo atiende sin abrir la base: una página pide decenas de fotos a la vez y agotaba las conexiones (503).
if (!in_array($action ?? '', ['product-image', 'proxy-image'], true)) return;

$targetUrl = '';
$id = trim($_GET['id'] ?? ($_GET['sku'] ?? ''));
$token = trim($_GET['token'] ?? ($_GET['img'] ?? ($_GET['t'] ?? '')));
$rawUrl = trim($_GET['url'] ?? '');
$maxImageWidth = max(320, min(800, (int)($_GET['w'] ?? 640)));

// 1. Resolver por Token Base64Url (directo y de alto rendimiento)
if (!empty($token)) {
    $decoded = smartispSplitImageUrls((string)base64_decode(strtr($token, '-_', '+/')))[0] ?? '';
    if ($decoded && filter_var($decoded, FILTER_VALIDATE_URL)) {
        $targetUrl = $decoded;
    }
}

// 2. Resolver por ID de producto en base de datos si no vino token o falló
if (empty($targetUrl) && !empty($id)) {
    try {
        $pdo = $pdo ?: getDbConnection();
        $pTable = getProductsTableName($pdo);
        $stmt = $pdo->prepare("SELECT * FROM `$pTable` WHERE id = :id OR sku = :sku LIMIT 1");
        $stmt->execute([':id' => $id, ':sku' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $norm = normalizeProductRow($row);
            $foundUrl = smartispSplitImageUrls((string)($norm['rawImageUrl'] ?? ''))[0] ?? '';
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
