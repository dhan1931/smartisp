<?php
// Fuentes de reemplazo para fotos de producto que el proveedor ya no sirve. Lo elegido se descarga
// a uploads/products con smartispArchiveImageUrl: la tienda nunca vuelve a depender de la URL original.
require_once __DIR__ . '/image-archive.php';
require_once __DIR__ . '/brands.php';
require_once __DIR__ . '/product-name.php';

const SMARTISP_PHOTO_AUTO_TRIED_KEY = 'image_auto_tried';
const SMARTISP_PHOTO_WEB_TRIED_KEY = 'image_web_tried';
// Fotos puestas desde la búsqueda web, pendientes de que alguien confirme que corresponden.
const SMARTISP_PHOTO_WEB_FIXED_KEY = 'image_web_fixed';

// Prefijo del código de Siglo21 → marca. El código es prefijo de 9 caracteres + número de parte del
// fabricante (LOGTECWIR + 920-012278). Sacado del catálogo de Siglo21 de 2026-10-30.
const SMARTISP_S21_BRAND_PREFIXES = [
    '3NS' => '3nStar', 'ACE' => 'Acer', 'ADA' => 'ADATA', 'AMD' => 'AMD', 'ANT' => 'Antec', 'AOC' => 'AOC', 'APP' => 'Apple',
    'ASU' => 'ASUS', 'BRO' => 'Brother', 'CAN' => 'Canon', 'CDP' => 'CDP', 'COO' => 'Cooler Master', 'CRU' => 'Crucial',
    'DAT' => 'Datalogic', 'DEL' => 'Dell', 'DLI' => 'D-Link', 'EPS' => 'Epson', 'GEN' => 'Genius', 'GIG' => 'Gigabyte',
    'HON' => 'Honeywell', 'HPI' => 'HP', 'INT' => 'Intel', 'JBL' => 'JBL', 'KIN' => 'Kingston', 'LEN' => 'Lenovo',
    'LEX' => 'Lexmark', 'LG0' => 'LG', 'LOG' => 'Logitech', 'MER' => 'Mercusys', 'MIC' => 'Microsoft', 'SAM' => 'Samsung',
    'SON' => 'Sony', 'TER' => 'Teros', 'TPL' => 'TP-Link', 'TRI' => 'Tripp Lite', 'VER' => 'Verbatim',
    'WES' => 'Western Digital', 'XIA' => 'Xiaomi', 'ZEB' => 'Zebra',
];

const SMARTISP_PHOTO_EXTRA_BRANDS = ['Genius', '3nStar', 'Cooler Master', 'Antec', 'Verbatim', 'Tripp Lite', 'Teros', 'CDP', 'Mercusys', 'Brother', 'Lexmark', 'Crucial', 'Acer', 'AOC', 'Tenda', 'Redragon', 'Hisense', 'Philips', 'Panasonic', 'Tapo'];

function smartispPhotoBrand(string $name, string $sku = ''): string {
    $sku = strtoupper(trim($sku));
    if (!smartispIsIntcomexSku($sku) && strlen($sku) > 9 && isset(SMARTISP_S21_BRAND_PREFIXES[substr($sku, 0, 3)])) {
        return SMARTISP_S21_BRAND_PREFIXES[substr($sku, 0, 3)];
    }
    $brands = array_merge(SMARTISP_BRANDS, SMARTISP_PHOTO_EXTRA_BRANDS);
    usort($brands, static fn($a, $b) => strlen($b) <=> strlen($a));
    $low = ' ' . mb_strtolower($name) . ' ';
    foreach ($brands as $brand) {
        if (preg_match('/(?<![a-z0-9])' . preg_quote(mb_strtolower($brand), '/') . '(?![a-z0-9])/u', $low)) return $brand;
    }
    return '';
}

function smartispIsIntcomexSku(string $sku): bool {
    return (bool)preg_match('/^[A-Z]{2}\d{3}[A-Z0-9]{3}\d{2}$/', strtoupper(trim($sku)));
}

// Números de parte probables: el del código de Siglo21 primero, luego los del nombre con letras y dígitos.
function smartispPhotoPartNumbers(string $name, string $sku = ''): array {
    $out = [];
    $sku = strtoupper(trim($sku));
    if (!smartispIsIntcomexSku($sku) && strlen($sku) > 11 && isset(SMARTISP_S21_BRAND_PREFIXES[substr($sku, 0, 3)])) $out[] = substr($sku, 9);
    preg_match_all('/[A-Za-z0-9]+(?:[-\/][A-Za-z0-9]+)+|[A-Za-z]*\d[A-Za-z0-9]{4,}/', $name, $m);
    foreach ($m[0] as $token) {
        $token = strtoupper($token);
        if (strlen($token) < 5 || !preg_match('/\d/', $token) || !preg_match('/[A-Z]/', $token) && !str_contains($token, '-')) continue;
        if (preg_match('/^\d+(GB|TB|MB|MHZ|GHZ|W|MM|DPI|HZ|MAH|PULG|IN)$/', $token) || preg_match('/^\d+X\d+(MM)?$/', $token)) continue;
        if (!in_array($token, $out, true)) $out[] = $token;
    }
    return array_slice($out, 0, 4);
}

function smartispPhotoGetJson(string $url, array $headers = [], int $timeout = 8): ?array {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT      => 'SmartISP/1.0 (+https://smart-isp.com.ec)',
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
    ]);
    $res = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($res) || $status >= 500) return null;
    $data = json_decode($res, true);
    return is_array($data) ? $data : null;
}

// Open Icecat: fichas oficiales del fabricante por marca + número de parte. Cuenta gratuita en icecat.biz
// (SMARTISP_ICECAT_USER); sin ella se usa la cuenta pública de demostración de Open Icecat.
function smartispIcecatImages(string $brand, string $code): array {
    $user = trim((string)(getenv('SMARTISP_ICECAT_USER') ?: 'openIcecat-live'));
    $data = smartispPhotoGetJson('https://live.icecat.biz/api?' . http_build_query(['UserName' => $user, 'Language' => 'es', 'Brand' => $brand, 'ProductCode' => $code]));
    $info = $data['data'] ?? null;
    if (!is_array($info)) return [];
    $title = (string)($info['GeneralInfo']['Title'] ?? '');
    $out = [];
    $main = $info['Image'] ?? [];
    if (!empty($main['HighPic'])) $out[] = ['url' => $main['HighPic'], 'thumb' => $main['Pic500x500'] ?? ($main['LowPic'] ?? $main['HighPic']), 'source' => 'icecat', 'label' => $title];
    foreach ($info['Gallery'] ?? [] as $pic) {
        if (empty($pic['Pic']) || ($main['HighPic'] ?? '') === $pic['Pic']) continue;
        $out[] = ['url' => $pic['Pic'], 'thumb' => $pic['Pic500x500'] ?? ($pic['ThumbPic'] ?? $pic['Pic']), 'source' => 'icecat', 'label' => $title];
        if (count($out) >= 6) break;
    }
    return $out;
}

// Copia guardada en el Internet Archive de la URL que se rompió (o de sus miniaturas -300x300 de WordPress).
function smartispWaybackImages(string $url): array {
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url) || smartispIsPlaceholderImage($url)) return [];
    $bare = preg_replace('#^https?://(www\.)?#i', '', strtok($url, '?'));
    $stem = preg_replace('/\.[a-z0-9]{3,4}$/i', '', $bare);
    $rows = smartispPhotoGetJson('https://web.archive.org/cdx/search/cdx?' . http_build_query(['url' => $stem, 'matchType' => 'prefix', 'output' => 'json', 'limit' => 40]), [], 15);
    if (!$rows || count($rows) < 2) return [];
    $best = null;
    foreach (array_slice($rows, 1) as $row) {
        [$ts, $original, $mime] = [$row[1] ?? '', $row[2] ?? '', $row[3] ?? ''];
        if (($row[4] ?? '') !== '200' || !str_starts_with((string)$mime, 'image/')) continue;
        $rest = substr(preg_replace('#^https?://(www\.)?#i', '', $original), strlen($stem));
        if (!preg_match('/^(?:-(\d+)x(\d+))?\.[a-z0-9]{3,4}$/i', $rest, $m)) continue;
        $size = ($m[1] ?? '') !== '' ? (int)$m[1] : 99999;
        if ($best === null || $size > $best[0]) $best = [$size, $ts, $original];
    }
    if ($best === null) return [];
    $archived = 'https://web.archive.org/web/' . $best[1] . 'id_/' . $best[2];
    return [['url' => $archived, 'thumb' => $archived, 'source' => 'archivo', 'label' => $best[0] === 99999 ? 'Copia de la foto original' : 'Copia de la foto original (' . $best[0] . ' px)']];
}

// Búsqueda web de imágenes (Brave Search API, SMARTISP_BRAVE_SEARCH_KEY). Sin clave no busca.
function smartispWebImageSearch(string $query, int $limit = 12): array {
    $key = trim((string)(getenv('SMARTISP_BRAVE_SEARCH_KEY') ?: ''));
    $query = trim($query);
    if ($key === '' || $query === '') return [];
    $data = smartispPhotoGetJson('https://api.search.brave.com/res/v1/images/search?' . http_build_query(['q' => $query, 'count' => max(1, min(30, $limit)), 'safesearch' => 'strict']), ['X-Subscription-Token: ' . $key]);
    $out = [];
    foreach ($data['results'] ?? [] as $r) {
        $url = (string)($r['properties']['url'] ?? '');
        if (!preg_match('#^https?://#i', $url)) continue;
        $out[] = ['url' => $url, 'thumb' => (string)($r['thumbnail']['src'] ?? $url), 'source' => 'web', 'label' => (string)($r['source'] ?? ($r['title'] ?? ''))];
    }
    return $out;
}

function smartispPhotoWebSearchEnabled(): bool {
    return trim((string)(getenv('SMARTISP_BRAVE_SEARCH_KEY') ?: '')) !== '';
}

// Candidatas para un producto: fabricante (Icecat), copia archivada de su foto rota y, si se pide, la web.
function smartispPhotoCandidates(array $product, bool $includeWeb = true, ?string $query = null, bool $firstSourceOnly = false): array {
    $name = (string)($product['name'] ?? '');
    $sku = (string)($product['sku'] ?? '');
    $out = [];
    $brand = smartispPhotoBrand($query ?? $name, $sku);
    if ($brand !== '') {
        foreach (array_slice(smartispPhotoPartNumbers($query ?? $name, $query === null ? $sku : ''), 0, 3) as $code) {
            $found = smartispIcecatImages($brand, $code);
            if ($found) { $out = array_merge($out, $found); break; }
        }
    }
    $parts = array_values(array_filter(array_map('trim', explode('|', (string)($product['image_url'] ?? ''))), 'strlen'));
    if ($firstSourceOnly && $out) return $out;
    if ($parts && smartispIsExternalImage($parts[0])) $out = array_merge($out, smartispWaybackImages($parts[0]));
    if ($includeWeb) $out = array_merge($out, smartispWebImageSearch($query ?? smartispPhotoWebQuery($product)));
    $seen = [];
    return array_values(array_filter($out, static function ($c) use (&$seen) {
        if (isset($seen[$c['url']])) return false;
        return $seen[$c['url']] = true;
    }));
}

function smartispPhotoMap(PDO $pdo, string $key): array {
    $stmt = $pdo->prepare('SELECT setting_value FROM settings_rows WHERE setting_key = :k');
    $stmt->execute([':k' => $key]);
    $map = json_decode((string)$stmt->fetchColumn(), true);
    return is_array($map) ? $map : [];
}

// Consulta para buscar la foto en la web: nombre limpio, con la marca y el número de parte si faltan.
function smartispPhotoWebQuery(array $product): string {
    $name = smartispDisplayName((string)($product['name'] ?? ''));
    $sku = (string)($product['sku'] ?? '');
    $words = preg_split('/\s+/u', trim(preg_replace('/[\/|]+/u', ' ', $name)));
    $query = implode(' ', array_slice($words, 0, 9));
    $brand = smartispPhotoBrand($name, $sku);
    if ($brand !== '' && stripos($query, $brand) === false) $query = $brand . ' ' . $query;
    $codes = smartispPhotoPartNumbers($name, $sku);
    if ($codes && stripos($query, $codes[0]) === false) $query .= ' ' . $codes[0];
    return trim($query);
}

function smartispPhotoForget(PDO $pdo, string $key, array $ids): void {
    $map = smartispPhotoMap($pdo, $key);
    $before = count($map);
    foreach ($ids as $id) unset($map[$id]);
    if (count($map) !== $before) smartispPhotoSaveMap($pdo, $key, $map);
}

function smartispPhotoSaveMap(PDO $pdo, string $key, array $map): void {
    $pdo->prepare('INSERT INTO settings_rows (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([':k' => $key, ':v' => json_encode(array_slice($map, -5000, null, true))]);
}

// Deja el producto con la foto ya guardada en el servidor y lo saca de las listas de fallidas.
function smartispPhotoSetLocal(PDO $pdo, string $pTable, string $id, string $localUrl): void {
    $pdo->prepare("UPDATE `$pTable` SET image_url = :url WHERE id = :id")->execute([':url' => $localUrl, ':id' => $id]);
    $failed = smartispArchiveFailed($pdo);
    if (isset($failed[$id])) { unset($failed[$id]); smartispArchiveSaveFailed($pdo, $failed); }
}

function smartispIsLocalProductUpload(string $url): bool {
    return (bool)preg_match('#^/api/auth/serve-upload\?category=products&file=[A-Za-z0-9._-]+$#', $url);
}
