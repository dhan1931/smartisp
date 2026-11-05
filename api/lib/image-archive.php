<?php
// Copia propia de las fotos de proveedor: el producto deja de depender de que el proveedor mantenga la
// URL. La caché del proxy borra lo que tiene más de 30 días, así que no sirve como respaldo.
require_once __DIR__ . '/image-proxy.php';

const SMARTISP_ARCHIVE_FAILED_KEY = 'image_archive_failed';
const SMARTISP_ARCHIVE_MAX_SIDE = 1200;

// Imagen genérica de «sin imagen» de la tienda del proveedor (woocommerce-placeholder.png): no es una foto
// del producto, así que no se copia ni se busca su copia archivada.
function smartispIsPlaceholderImage(string $url): bool {
    return (bool)preg_match('/placeholder|no[-_]?image|sin[-_]?imagen/i', basename((string)parse_url($url, PHP_URL_PATH)));
}

function smartispIsExternalImage(string $url): bool {
    return (bool)preg_match('#^https?://#i', trim($url));
}

function smartispArchiveFailed(PDO $pdo): array {
    $stmt = $pdo->prepare('SELECT setting_value FROM settings_rows WHERE setting_key = :k');
    $stmt->execute([':k' => SMARTISP_ARCHIVE_FAILED_KEY]);
    $map = json_decode((string)$stmt->fetchColumn(), true);
    return is_array($map) ? $map : [];
}

function smartispArchiveSaveFailed(PDO $pdo, array $map): void {
    $map = array_slice($map, -3000, null, true);
    $pdo->prepare('INSERT INTO settings_rows (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([':k' => SMARTISP_ARCHIVE_FAILED_KEY, ':v' => json_encode($map)]);
}

// Copia que el proxy guardó al mostrarla (product-image.php, cualquier antigüedad mientras siga en disco):
// rescata fotos que el proveedor borró después de que la tienda las sirvió.
function smartispProxyCachedImage(string $url): ?string {
    foreach ([dirname(__DIR__, 2) . '/uploads/cache', sys_get_temp_dir() . '/smartisp_img_cache'] as $dir) {
        if (!is_dir($dir)) continue;
        foreach ([640, 800, 480, 320] as $width) {
            foreach (['0', '1'] as $webp) {
                $hash = md5($url . '|w=' . $width . '|webp=' . $webp);
                foreach (SMARTISP_PROXY_IMAGE_TYPES as $ext) {
                    $file = "$dir/$hash.$ext";
                    if (!is_file($file) || filesize($file) <= 0) continue;
                    $data = file_get_contents($file);
                    if (is_string($data) && smartispDetectImageType($data) !== null) return $data;
                }
            }
        }
    }
    return null;
}

// Una foto marcada como fallida vuelve a la cola si el producto ya tiene otra dirección (alguien la cambió).
function smartispArchivePruneFailed(PDO $pdo, string $pTable, array $failed): array {
    if (!$failed) return $failed;
    $ids = array_map('strval', array_slice(array_keys($failed), -3000));
    $stmt = $pdo->prepare("SELECT id, image_url FROM `$pTable` WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute($ids);
    $current = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $current[(string)$row['id']] = trim(explode('|', (string)$row['image_url'])[0]);
    $kept = array_filter($failed, static fn($url, $id) => ($current[(string)$id] ?? null) === (string)$url, ARRAY_FILTER_USE_BOTH);
    if (count($kept) !== count($failed)) smartispArchiveSaveFailed($pdo, $kept);
    return $kept;
}

// Descarga una URL y la guarda en uploads/products. Devuelve la URL local o null si no se pudo.
function smartispArchiveImageUrl(string $url): ?string {
    $url = trim($url);
    if (!smartispIsExternalImage($url) || smartispIsPlaceholderImage($url)) return null;
    $data = smartispFetchRemoteImage($url) ?? smartispProxyCachedImage($url);
    if ($data === null) return null;
    $mime = smartispDetectImageType($data);
    if ($mime === null || !isset(SMARTISP_PROXY_IMAGE_TYPES[$mime])) return null;
    $dir = getSmartispUploadsDir('products');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return null;
    $base = 'arch_' . substr(md5($url), 0, 20);

    // Se guarda en WebP de 1200 px como máximo cuando el servidor puede; si no, el archivo tal cual.
    if ($mime !== 'image/gif' && function_exists('imagewebp') && function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring($data);
        if ($img) {
            $w = imagesx($img); $h = imagesy($img);
            $scale = min(1, SMARTISP_ARCHIVE_MAX_SIDE / max($w, $h, 1));
            if ($scale < 1) {
                $resized = imagecreatetruecolor((int)round($w * $scale), (int)round($h * $scale));
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled($resized, $img, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $w, $h);
                imagedestroy($img);
                $img = $resized;
            } else {
                imagepalettetotruecolor($img);
                imagesavealpha($img, true);
            }
            $file = $base . '.webp';
            $ok = @imagewebp($img, $dir . '/' . $file, 82);
            imagedestroy($img);
            if ($ok && filesize($dir . '/' . $file) > 0) return '/api/auth/serve-upload?category=products&file=' . rawurlencode($file);
        }
    }
    if ($mime === 'image/avif') return null;
    $file = $base . '.' . SMARTISP_PROXY_IMAGE_TYPES[$mime];
    if (@file_put_contents($dir . '/' . $file, $data) === false) return null;
    return '/api/auth/serve-upload?category=products&file=' . rawurlencode($file);
}

function smartispArchivePendingWhere(): string {
    return "(image_url LIKE 'http://%' OR image_url LIKE 'https://%')";
}

// Procesa una tanda de productos con foto externa (visibles primero). Devuelve el resumen.
function smartispArchiveBatch(PDO $pdo, string $pTable, int $limit, float $timeBudget = 20.0): array {
    $failed = smartispArchivePruneFailed($pdo, $pTable, smartispArchiveFailed($pdo));
    $started = microtime(true);
    $where = smartispArchivePendingWhere();
    $exclude = '';
    $params = [];
    $failedIds = array_slice(array_keys($failed), -3000);
    if ($failedIds) {
        $keys = [];
        foreach ($failedIds as $i => $id) { $keys[] = ":f$i"; $params[":f$i"] = (string)$id; }
        $exclude = ' AND id NOT IN (' . implode(', ', $keys) . ')';
    }
    $stmt = $pdo->prepare("SELECT id, image_url FROM `$pTable` WHERE $where$exclude ORDER BY visible DESC, id ASC LIMIT " . max(1, min(50, $limit)));
    $stmt->execute($params);
    $update = $pdo->prepare("UPDATE `$pTable` SET image_url = :new WHERE id = :id AND image_url = :old");
    $saved = 0; $newFailed = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (microtime(true) - $started > $timeBudget) break;
        $parts = array_values(array_filter(array_map('trim', explode('|', (string)$row['image_url'])), static fn($p) => $p !== '' && !smartispIsPlaceholderImage($p)));
        if (!$parts) {
            $update->execute([':new' => '', ':id' => $row['id'], ':old' => $row['image_url']]);
            continue;
        }
        $out = []; $any = false;
        foreach ($parts as $part) {
            $local = smartispIsExternalImage($part) ? smartispArchiveImageUrl($part) : null;
            if ($local !== null) $any = true;
            $out[] = $local ?? $part;
        }
        // Solo cuenta como guardado si la foto principal quedó local; si no, se anota para no reintentarla en cada tanda.
        if ($any && !smartispIsExternalImage($out[0])) {
            $update->execute([':new' => implode('|', $out), ':id' => $row['id'], ':old' => $row['image_url']]);
            $saved++;
        } else {
            $failed[$row['id']] = $parts[0] ?? '';
            $newFailed[] = ['id' => $row['id'], 'url' => $parts[0] ?? ''];
        }
    }
    if ($newFailed) smartispArchiveSaveFailed($pdo, $failed);
    return ['saved' => $saved, 'failed' => $newFailed] + smartispArchiveStats($pdo, $pTable, $failed);
}

function smartispArchiveStats(PDO $pdo, string $pTable, ?array $failed = null): array {
    $failed = $failed ?? smartispArchivePruneFailed($pdo, $pTable, smartispArchiveFailed($pdo));
    $where = smartispArchivePendingWhere();
    $external = (int)$pdo->query("SELECT COUNT(*) FROM `$pTable` WHERE $where")->fetchColumn();
    $failedStillExternal = 0;
    if ($failed) {
        $ids = array_slice(array_keys($failed), -3000);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$pTable` WHERE $where AND id IN ($in)");
        $stmt->execute(array_map('strval', $ids));
        $failedStillExternal = (int)$stmt->fetchColumn();
    }
    $local = (int)$pdo->query("SELECT COUNT(*) FROM `$pTable` WHERE image_url LIKE '/api/auth/serve-upload%'")->fetchColumn();
    return ['pending' => max(0, $external - $failedStillExternal), 'unreachable' => $failedStillExternal, 'local' => $local];
}
