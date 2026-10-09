<?php
// Parte de api/router.php, dividido por dominio (DEV-20261005-015): contenido editable del sitio (admin-content, landing/site-content).
// Comparte el scope de router.php via require (no es una funcion): $pdo, $body, $action,
// $method y los helpers (getAuthUser, isAdminUser, requireAdminAuth, etc.) ya estan definidos
// cuando este archivo se incluye. Cada bloque se autogestiona con 'if ($action === ...) { ... exit; }',
// igual que antes; solo cambio donde vive el codigo, no la logica.

// -------------------------------------------------------------
// DEV-20261005-023: los campos de correo viven en mail_settings (tabla propia, fila única),
// no en settings_rows. admin-content sigue siendo el único endpoint que usa el front (su
// formulario de "Correo" no cambió); lo que cambió es dónde se guardan estos 10 campos.
const MAIL_SETTINGS_KEYS = [
    'admin_email', 'smtp_provider', 'smtp_host', 'smtp_port', 'smtp_user',
    'smtp_pass', 'smtp_secure', 'smtp_from', 'resend_api_key', 'email_from',
];
const PUBLIC_BRAND_IMAGE_KEYS = ['logo_image', 'logo_dark_image', 'landing_logo_image', 'landing_logo_dark_image'];

if ($action === 'admin-content') {
    requireAdminAuth();

    if ($method === 'GET') {
        // Un solo valor pesado (p. ej. un logo en base64), para cargarlo aparte cuando
        // realmente se necesita, en vez de traerlo siempre junto con el resto de los ajustes.
        $singleKey = trim((string)($_GET['key'] ?? ''));
        if ($singleKey !== '' && !in_array($singleKey, MAIL_SETTINGS_KEYS, true)) {
            $stmt = $pdo->prepare('SELECT setting_value FROM settings_rows WHERE setting_key = :key LIMIT 1');
            $stmt->execute([':key' => $singleKey]);
            echo json_encode(['key' => $singleKey, 'value' => (string)($stmt->fetchColumn() ?: '')]);
            exit;
        }

        $stmt = $pdo->query("SELECT setting_key as `key`, setting_value as `value` FROM settings_rows");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $mailRow = $pdo->query('SELECT * FROM mail_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (MAIL_SETTINGS_KEYS as $k) {
            $v = $mailRow[$k] ?? null;
            if ($k === 'smtp_secure') $v = ((string)$v === '1') ? 'true' : 'false';
            $rows[] = ['key' => $k, 'value' => $v === null ? '' : (string)$v];
        }

        $maskedRows = [];
        $hasSmtpPass = false;
        $hasResendKey = false;
        // DEV-20261005-022 (parte del panel): valores grandes (logos en base64, HTML largo)
        // no viajan por defecto; solo se avisa que existen y cuánto pesan. El front los pide
        // uno por uno con ?key=<nombre> solo cuando la sección que los muestra está abierta.
        $largeValueThreshold = 20 * 1024; // 20 KB
        foreach ($rows as $r) {
            $k = $r['key'] ?? '';
            $v = $r['value'] ?? '';
            if ($k === 'smtp_pass') {
                if (!empty($v)) $hasSmtpPass = true;
                $v = !empty($v) ? '••••••••' : '';
            } elseif ($k === 'resend_api_key') {
                if (!empty($v)) $hasResendKey = true;
                $v = !empty($v) ? '••••••••' : '';
            } elseif (strlen($v) > $largeValueThreshold) {
                $maskedRows[] = ['key' => $k, 'value' => '', 'truncated' => true, 'size_kb' => (int)round(strlen($v) / 1024)];
                continue;
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
            $mailUpdates = [];
            foreach ($items as $item) {
                $k = $item['key'] ?? '';
                $v = $item['value'] ?? '';
                if (!$k) continue;
                // Si el valor de contraseña es viñetas '••••••••' o vacío al enviar sin cambios, NO sobreescribir la contraseña existente
                if (($k === 'smtp_pass' || $k === 'resend_api_key') && ($v === '••••••••' || $v === '')) {
                    continue;
                }
                if (in_array($k, MAIL_SETTINGS_KEYS, true)) {
                    $mailUpdates[$k] = (string)$v;
                    continue;
                }
                $stmt->execute([':key' => $k, ':value' => (string)$v]);
            }

            if (!empty($mailUpdates)) {
                if (isset($mailUpdates['smtp_secure'])) {
                    $mailUpdates['smtp_secure'] = in_array(strtolower($mailUpdates['smtp_secure']), ['false', '0', ''], true) ? 0 : 1;
                }
                if (isset($mailUpdates['smtp_port'])) {
                    $mailUpdates['smtp_port'] = $mailUpdates['smtp_port'] === '' ? null : (int)$mailUpdates['smtp_port'];
                }
                $pdo->exec('INSERT IGNORE INTO mail_settings (id) VALUES (1)');
                $setSql = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($mailUpdates)));
                $mailStmt = $pdo->prepare("UPDATE mail_settings SET $setSql WHERE id = 1");
                $params = [];
                foreach ($mailUpdates as $k => $v) { $params[":$k"] = $v; }
                $mailStmt->execute($params);
            }
        }

        echo json_encode(['ok' => true]);
        exit;
    }
}

// -------------------------------------------------------------
// CONTENIDO PÚBLICO DE PORTADA Y TIENDA (/api/auth/landing-content, /api/auth/site-content)
// -------------------------------------------------------------

if ($action === 'brand-image' && $method === 'GET') {
    $key = trim((string)($_GET['key'] ?? ''));
    if (!in_array($key, PUBLIC_BRAND_IMAGE_KEYS, true)) {
        http_response_code(404);
        exit;
    }

    $stmt = $pdo->prepare('SELECT setting_value FROM settings_rows WHERE setting_key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $dataUrl = (string)($stmt->fetchColumn() ?: '');
    if (!preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $match)) {
        http_response_code(404);
        exit;
    }
    $imageData = base64_decode($match[2], true);
    if ($imageData === false || strlen($imageData) > 8 * 1024 * 1024) {
        http_response_code(404);
        exit;
    }

    $acceptsWebp = strpos(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'image/webp') !== false
        && function_exists('imagecreatefromstring') && function_exists('imagewebp')
        && function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');
    $etag = hash('sha256', $imageData . '|webp=' . ($acceptsWebp ? '1' : '0'));
    header('ETag: "' . $etag . '"');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Vary: Accept');
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $etag) {
        http_response_code(304);
        exit;
    }

    $mime = 'image/' . $match[1];
    if ($mime === 'image/jpeg') $mime = 'image/jpeg';
    $imageInfo = @getimagesizefromstring($imageData);
    if ($acceptsWebp && $imageInfo && (int)$imageInfo[0] > 0 && (int)$imageInfo[1] > 0
        && (int)$imageInfo[0] * (int)$imageInfo[1] <= 20000000
        && in_array($imageInfo['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true)) {
        $source = @imagecreatefromstring($imageData);
        if ($source !== false) {
            $width = (int)$imageInfo[0];
            $height = (int)$imageInfo[1];
            $outWidth = min(700, $width);
            $outHeight = max(1, (int)round($height * $outWidth / $width));
            $output = imagecreatetruecolor($outWidth, $outHeight);
            if ($output !== false) {
                imagealphablending($output, false);
                imagesavealpha($output, true);
                imagecopyresampled($output, $source, 0, 0, 0, 0, $outWidth, $outHeight, $width, $height);
                ob_start();
                $encoded = imagewebp($output, null, 84);
                $optimized = ob_get_clean();
                if ($encoded && is_string($optimized) && strlen($optimized) < strlen($imageData)) {
                    $imageData = $optimized;
                    $mime = 'image/webp';
                }
                imagedestroy($output);
            }
            imagedestroy($source);
        }
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($imageData));
    echo $imageData;
    exit;
}

if ($action === 'landing-content' || $action === 'site-content') {
    if ($method === 'GET') {
        if ($action === 'site-content') {
            // Este endpoint solo lo usan los logos públicos del sitio y del panel.
            // Evita leer y serializar el resto de ajustes (incluidos HTML e imágenes pesadas).
            $brandKeys = [
                'brand_name', 'logo_text', 'logo_type', 'logo_image', 'logo_height',
                'logo_dark_image', 'logo_dark_height', 'logo_dark_invert',
                'landing_logo_text', 'landing_logo_type', 'landing_logo_image', 'landing_logo_height',
                'landing_logo_dark_image', 'landing_logo_dark_height', 'landing_logo_dark_invert',
                'storefront_product_columns',
            ];
            $placeholders = implode(',', array_fill(0, count($brandKeys), '?'));
            $stmt = $pdo->prepare("SELECT setting_key as `key`, setting_value as `value` FROM settings_rows WHERE setting_key IN ($placeholders)");
            $stmt->execute($brandKeys);
        } else {
            $stmt = $pdo->query("SELECT setting_key as `key`, setting_value as `value` FROM settings_rows");
        }
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        // SANITIZACIÓN ESTRICTA DE SEGURIDAD (QA-024 / QA-025):
        // Jamás devolver secretos o credenciales en endpoints públicos
        $sensitiveKeys = [
            'smtp_pass', 'smtp_user', 'smtp_host', 'smtp_port', 'smtp_secure',
            'smtp_provider', 'smtp_from', 'resend_api_key', 'admin_email', 'email_from'
        ];
        $safeRows = [];
        $hasLandingLogo = false;
        $hasLandingDarkLogo = false;
        foreach ($rows as $r) {
            $k = $r['key'] ?? '';
            if (in_array($k, $sensitiveKeys, true) || stripos($k, 'pass') !== false || stripos($k, 'secret') !== false) {
                continue;
            }
            if ($k === 'landing_logo_image' && !empty($r['value'])) {
                $hasLandingLogo = true;
            }
            if ($k === 'landing_logo_dark_image' && !empty($r['value'])) {
                $hasLandingDarkLogo = true;
            }
            $safeRows[] = $r;
        }

        if ($hasLandingLogo || $hasLandingDarkLogo) {
            $safeRows = array_values(array_filter($safeRows, static function ($r) use ($action, $hasLandingLogo, $hasLandingDarkLogo) {
                $key = $r['key'] ?? '';
                if ($hasLandingLogo && $key === 'logo_image') return false;
                if ($hasLandingDarkLogo && $key === 'logo_dark_image') return false;
                return true;
            }));
        }

        if ($action === 'site-content') {
            foreach ($safeRows as &$row) {
                $key = (string)($row['key'] ?? '');
                $value = (string)($row['value'] ?? '');
                if (in_array($key, PUBLIC_BRAND_IMAGE_KEYS, true) && str_starts_with($value, 'data:image/')) {
                    $version = substr(hash('sha256', $value), 0, 16);
                    $row['value'] = '/api/auth/brand-image?key=' . rawurlencode($key) . '&v=' . $version;
                }
            }
            unset($row);
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
