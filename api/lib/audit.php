<?php
// Registro de actividad del equipo (admin_audit_log, migración 044). Nada de aquí debe romper la
// petición: sin la tabla o con un INSERT fallido solo queda una línea en el log de PHP.

// Claves cuyo valor nunca se guarda (se anota solo que vinieron).
const SMARTISP_AUDIT_SENSITIVE = '/pass|token|secret|api[_-]?key|apikey|authorization|cookie|session|credential|private|signature|hash|card|cvv|otp|smtp_/i';
const SMARTISP_AUDIT_TARGET_KEYS = ['id', 'orderId', 'order_id', 'productId', 'product_id', 'userId', 'user_id', 'quoteId', 'quote_id', 'campaignId', 'campaign_id', 'categoryId', 'category_id', 'sku', 'slug', 'key'];
const SMARTISP_AUDIT_LOGIN_ALERT_AFTER = 5;
const SMARTISP_AUDIT_LOGIN_WINDOW = 900;

function smartispAuditAvailable(PDO $pdo): bool {
    try {
        return smartispTableExists($pdo, 'admin_audit_log');
    } catch (Throwable $e) {
        return false;
    }
}

function smartispAuditCut($value, int $max): string {
    $text = trim(preg_replace('/\s+/u', ' ', (string)$value) ?? '');
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
}

// Lo que un handler quiere dejar en vez del resumen automático (p. ej. "rol: cliente → admin").
function smartispAuditNote(?array $set = null): array {
    static $note = [];
    if ($set !== null) $note = array_merge($note, $set);
    return $note;
}

function smartispAuditWrite(PDO $pdo, array $entry): void {
    if (!smartispAuditAvailable($pdo)) return;
    try {
        $stmt = $pdo->prepare('INSERT INTO admin_audit_log (user_id, user_email, action, method, target, summary, status, ip, user_agent, created_at)
            VALUES (:user_id, :user_email, :action, :method, :target, :summary, :status, :ip, :ua, :created_at)');
        $stmt->execute([
            ':user_id' => isset($entry['user_id']) ? smartispAuditCut($entry['user_id'], 191) : null,
            ':user_email' => isset($entry['user_email']) ? smartispAuditCut(strtolower((string)$entry['user_email']), 191) : null,
            ':action' => smartispAuditCut($entry['action'] ?? 'desconocida', 80),
            ':method' => isset($entry['method']) ? substr((string)$entry['method'], 0, 8) : null,
            ':target' => ($entry['target'] ?? '') !== '' ? smartispAuditCut($entry['target'], 191) : null,
            ':summary' => ($entry['summary'] ?? '') !== '' ? smartispAuditCut($entry['summary'], 500) : null,
            ':status' => isset($entry['status']) ? (int)$entry['status'] : null,
            ':ip' => substr(smartispClientIp(), 0, 45),
            ':ua' => smartispAuditCut($_SERVER['HTTP_USER_AGENT'] ?? '', 255),
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        error_log('SmartISP audit: ' . $e->getMessage());
    }
}

function smartispAuditTarget(array $query, array $body): string {
    foreach ([$query, $body] as $source) {
        foreach (SMARTISP_AUDIT_TARGET_KEYS as $key) {
            if (isset($source[$key]) && is_scalar($source[$key]) && trim((string)$source[$key]) !== '') return (string)$source[$key];
        }
    }
    // Cuerpos anidados del tipo {product: {id}} o {category: {id}}.
    foreach ($body as $value) {
        if (is_array($value) && isset($value['id']) && is_scalar($value['id']) && trim((string)$value['id']) !== '') return (string)$value['id'];
    }
    return '';
}

// Resumen corto: nombres de campo y valores escalares recortados; nada sensible ni anidado.
function smartispAuditSummary(array $body, array $files = []): string {
    $parts = [];
    // {key: 'smtp_pass', value: '...'}: el nombre del ajuste decide si el valor se oculta.
    $sensitiveValue = false;
    foreach (['key', 'setting_key'] as $nameKey) {
        if (isset($body[$nameKey]) && is_string($body[$nameKey]) && preg_match(SMARTISP_AUDIT_SENSITIVE, $body[$nameKey])) $sensitiveValue = true;
    }
    foreach ($body as $key => $value) {
        $key = (string)$key;
        if ($key === 'action' || $key === 'payload') continue;
        if (preg_match(SMARTISP_AUDIT_SENSITIVE, $key) || ($sensitiveValue && in_array($key, ['value', 'setting_value'], true))) { $parts[] = $key . '=(oculto)'; continue; }
        if (is_array($value)) {
            $keys = [];
            foreach (array_slice($value, 0, 6) as $item) {
                if (is_array($item) && isset($item['key']) && is_string($item['key'])) $keys[] = $item['key'];
            }
            $parts[] = $keys ? $key . ': ' . implode(', ', $keys) . (count($value) > 6 ? '…' : '') : $key . '[' . count($value) . ']';
            continue;
        }
        if (is_bool($value)) $text = $value ? 'sí' : 'no';
        elseif ($value === null) $text = '∅';
        else $text = (string)$value;
        if (preg_match('/^data:|^[A-Za-z0-9+\/=]{120,}$/', $text)) $text = '(dato)';
        $parts[] = $key . '=' . smartispAuditCut($text, 60);
    }
    foreach ($files as $field => $file) {
        $parts[] = $field . ': archivo ' . smartispAuditCut(is_array($file['name'] ?? null) ? count($file['name']) . ' archivos' : ($file['name'] ?? ''), 60);
    }
    return smartispAuditCut(implode(' · ', $parts), 500);
}

// Login de una cuenta admin. Tras N fallos seguidos en la ventana deja una alerta aparte.
function smartispAuditLogin(PDO $pdo, array $user, string $action, int $status, string $summary = ''): void {
    smartispAuditWrite($pdo, ['user_id' => $user['id'] ?? null, 'user_email' => $user['email'] ?? null, 'action' => $action, 'method' => 'POST', 'status' => $status, 'summary' => $summary]);
    if ($action !== 'login-failed' || !smartispAuditAvailable($pdo)) return;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_audit_log WHERE action = 'login-failed' AND user_id = :id AND created_at >= :since");
        $stmt->execute([':id' => (string)($user['id'] ?? ''), ':since' => gmdate('Y-m-d H:i:s', time() - SMARTISP_AUDIT_LOGIN_WINDOW)]);
        $failures = (int)$stmt->fetchColumn();
        if ($failures > 0 && $failures % SMARTISP_AUDIT_LOGIN_ALERT_AFTER === 0) {
            smartispAuditWrite($pdo, ['user_id' => $user['id'] ?? null, 'user_email' => $user['email'] ?? null, 'action' => 'login-alert', 'method' => 'POST', 'status' => $status,
                'summary' => $failures . ' intentos fallidos de acceso al panel en ' . (SMARTISP_AUDIT_LOGIN_WINDOW / 60) . ' minutos']);
        }
    } catch (Throwable $e) {
        error_log('SmartISP audit login: ' . $e->getMessage());
    }
}

// Conteos de las últimas 24 h para los avisos del panel.
function smartispAuditLoginAlerts(PDO $pdo): array {
    $out = ['admin_login_failures' => 0, 'admin_login_alerts' => 0];
    if (!smartispAuditAvailable($pdo)) return $out;
    try {
        $stmt = $pdo->prepare("SELECT SUM(action = 'login-failed') AS failures, SUM(action = 'login-alert') AS alerts FROM admin_audit_log
            WHERE action IN ('login-failed', 'login-alert') AND created_at >= :since");
        $stmt->execute([':since' => gmdate('Y-m-d H:i:s', time() - 86400)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['admin_login_failures'] = (int)($row['failures'] ?? 0);
        $out['admin_login_alerts'] = (int)($row['alerts'] ?? 0);
    } catch (Throwable $e) {
        error_log('SmartISP audit alerts: ' . $e->getMessage());
    }
    return $out;
}
