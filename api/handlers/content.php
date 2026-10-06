<?php
// Parte de api/router.php, dividido por dominio (DEV-20261005-015): contenido editable del sitio (admin-content, landing/site-content).
// Comparte el scope de router.php via require (no es una funcion): $pdo, $body, $action,
// $method y los helpers (getAuthUser, isAdminUser, requireAdminAuth, etc.) ya estan definidos
// cuando este archivo se incluye. Cada bloque se autogestiona con 'if ($action === ...) { ... exit; }',
// igual que antes; solo cambio donde vive el codigo, no la logica.

// -------------------------------------------------------------
if ($action === 'admin-content') {
    requireAdminAuth();

    if ($method === 'GET') {
        // Un solo valor pesado (p. ej. un logo en base64), para cargarlo aparte cuando
        // realmente se necesita, en vez de traerlo siempre junto con el resto de los ajustes.
        $singleKey = trim((string)($_GET['key'] ?? ''));
        if ($singleKey !== '' && $singleKey !== 'smtp_pass' && $singleKey !== 'resend_api_key') {
            $stmt = $pdo->prepare('SELECT setting_value FROM settings_rows WHERE setting_key = :key LIMIT 1');
            $stmt->execute([':key' => $singleKey]);
            echo json_encode(['key' => $singleKey, 'value' => (string)($stmt->fetchColumn() ?: '')]);
            exit;
        }

        $stmt = $pdo->query("SELECT setting_key as `key`, setting_value as `value` FROM settings_rows");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
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

// -------------------------------------------------------------
// CONTENIDO PÚBLICO DE PORTADA Y TIENDA (/api/auth/landing-content, /api/auth/site-content)
// -------------------------------------------------------------

if ($action === 'landing-content' || $action === 'site-content') {
    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT setting_key as `key`, setting_value as `value` FROM settings_rows");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        // SANITIZACIÓN ESTRICTA DE SEGURIDAD (QA-024 / QA-025):
        // Jamás devolver secretos o credenciales en endpoints públicos
        $sensitiveKeys = [
            'smtp_pass', 'smtp_user', 'smtp_host', 'smtp_port', 'smtp_secure',
            'smtp_provider', 'smtp_from', 'resend_api_key', 'admin_email', 'email_from'
        ];
        $safeRows = [];
        $hasLandingLogo = false;
        foreach ($rows as $r) {
            $k = $r['key'] ?? '';
            if (in_array($k, $sensitiveKeys, true) || stripos($k, 'pass') !== false || stripos($k, 'secret') !== false) {
                continue;
            }
            if ($k === 'landing_logo_image' && !empty($r['value'])) {
                $hasLandingLogo = true;
            }
            $safeRows[] = $r;
        }

        if ($action === 'landing-content' && $hasLandingLogo) {
            $safeRows = array_values(array_filter($safeRows, fn($r) => ($r['key'] ?? '') !== 'logo_image'));
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
