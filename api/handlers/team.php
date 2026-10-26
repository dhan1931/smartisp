<?php
// Equipo del panel (cuentas admin) y registro de actividad. Comparte el scope de router.php:
// $pdo, $body, $action, $method y los helpers de sesión. Se incluye después de auth.php.

if (!in_array($action, ['admin-team', 'admin-team-create', 'admin-team-role', 'admin-team-status', 'admin-team-revoke', 'admin-my-account', 'admin-audit-log'], true)) return;

requireAdminAuth();
$actor = getAuthUser();
$actorId = (string)($actor['id'] ?? '');
$actorRank = smartispRoleRank($actor['role'] ?? '');

function smartispTeamFail(int $status, string $message, array $extra = []): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message] + $extra);
    exit;
}

// "role IN (alias...)" para los roles canónicos dados; la columna guarda variantes (Administrator...).
function smartispTeamRoleSql(array $roles): array {
    $aliases = [];
    foreach ($roles as $role) foreach (smartispRoleAliases($role) as $alias) $aliases[$alias] = true;
    $placeholders = [];
    $params = [];
    foreach (array_keys($aliases) as $i => $alias) {
        $placeholders[] = ":role$i";
        $params[":role$i"] = $alias;
    }
    return ['LOWER(TRIM(role)) IN (' . implode(', ', $placeholders) . ')', $params];
}

function smartispTeamFind(PDO $pdo, string $id): ?array {
    $hasActive = smartispUsersHaveColumn($pdo, 'active');
    $stmt = $pdo->prepare('SELECT id, email, name, surname, role' . ($hasActive ? ', active' : '') . ' FROM users_rows WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['active'] = (int)($row['active'] ?? 1) === 1;
    return $row;
}

// Admins activos distintos de $excludeId, bloqueados hasta el commit para que dos cambios simultáneos
// no dejen la tienda sin administradores.
function smartispTeamOtherActiveAdmins(PDO $pdo, string $excludeId): int {
    [$roleSql, $params] = smartispTeamRoleSql(['admin']);
    $activeSql = smartispUsersHaveColumn($pdo, 'active') ? ' AND active = 1' : '';
    $stmt = $pdo->prepare("SELECT id FROM users_rows WHERE $roleSql AND id <> :exclude$activeSql FOR UPDATE");
    $stmt->execute($params + [':exclude' => $excludeId]);
    return count($stmt->fetchAll(PDO::FETCH_COLUMN));
}

function smartispTeamLabel(array $user): string {
    $name = trim(($user['name'] ?? '') . ' ' . ($user['surname'] ?? ''));
    return $name !== '' ? $name . ' <' . ($user['email'] ?? '') . '>' : (string)($user['email'] ?? '');
}

// Contraseña temporal legible (sin 0/O ni 1/l/I) en tres bloques.
function smartispTeamTempPassword(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 15; $i++) {
        if ($i > 0 && $i % 5 === 0) $out .= '-';
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function smartispTeamRequireManager(array $actor): void {
    if (!smartispIsFullAdmin($actor['role'] ?? '')) smartispTeamFail(403, 'Solo un administrador pleno puede gestionar el equipo.');
}

// Nadie gestiona una cuenta ni asigna un rol por encima del suyo.
function smartispTeamRequireRank(int $actorRank, $role, string $message): void {
    if (smartispRoleRank($role) > $actorRank) smartispTeamFail(403, $message);
}

// -------------------------------------------------------------
// LISTA DEL EQUIPO (GET /api/auth/admin-team)
// -------------------------------------------------------------
if ($action === 'admin-team' && $method === 'GET') {
    smartispTeamRequireManager($actor);
    $hasActive = smartispUsersHaveColumn($pdo, 'active');
    $hasLastLogin = smartispUsersHaveColumn($pdo, 'last_login_at');
    [$roleSql, $params] = smartispTeamRoleSql(SMARTISP_TEAM_ROLES);
    $stmt = $pdo->prepare('SELECT id, email, name, surname, role, created_at' . ($hasActive ? ', active' : '') . ($hasLastLogin ? ', last_login_at' : '') .
        " FROM users_rows WHERE $roleSql ORDER BY " . ($hasActive ? 'active DESC, ' : '') . 'name ASC, email ASC');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $lastActivity = [];
    if ($rows && smartispAuditAvailable($pdo)) {
        $ids = array_column($rows, 'id');
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $activity = $pdo->prepare("SELECT user_id, MAX(created_at) FROM admin_audit_log WHERE user_id IN ($marks) GROUP BY user_id");
        $activity->execute($ids);
        $lastActivity = $activity->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    $members = array_map(static function (array $row) use ($actorId, $lastActivity) {
        $role = smartispNormalizeRole($row['role'] ?? '');
        return [
            'id' => (string)$row['id'],
            'email' => (string)$row['email'],
            'name' => (string)($row['name'] ?? ''),
            'surname' => (string)($row['surname'] ?? ''),
            'role' => $role,
            'roleLabel' => smartispRoleLabel($role),
            'active' => (int)($row['active'] ?? 1) === 1,
            'createdAt' => $row['created_at'] ?? null,
            'lastLoginAt' => $row['last_login_at'] ?? null,
            'lastActivityAt' => $lastActivity[$row['id']] ?? null,
            'isSelf' => (string)$row['id'] === $actorId,
        ];
    }, $rows);

    $roles = [];
    foreach (SMARTISP_ROLE_RANK as $role => $rank) {
        if ($rank <= $actorRank) $roles[] = ['value' => $role, 'label' => smartispRoleLabel($role), 'team' => in_array($role, SMARTISP_TEAM_ROLES, true)];
    }
    echo json_encode([
        'members' => $members,
        'roles' => $roles,
        'me' => $actorId,
        'schema' => ['active' => $hasActive, 'lastLogin' => $hasLastLogin, 'audit' => smartispAuditAvailable($pdo), 'sessions' => smartispUsersHaveTokenVersion($pdo)],
    ]);
    exit;
}

// -------------------------------------------------------------
// CREAR CUENTA DE EQUIPO (POST /api/auth/admin-team-create)
// -------------------------------------------------------------
if ($action === 'admin-team-create') {
    smartispRequirePost();
    smartispTeamRequireManager($actor);
    $name = trim((string)($body['name'] ?? ''));
    $surname = trim((string)($body['surname'] ?? ''));
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $role = (string)($body['role'] ?? 'admin');
    $mode = ($body['mode'] ?? 'password') === 'invite' ? 'invite' : 'password';

    if ($name === '' || mb_strlen($name) > 120) smartispTeamFail(400, 'Escribe el nombre de la persona.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) smartispTeamFail(400, 'Ingresa un correo electrónico válido.');
    if (!in_array($role, SMARTISP_TEAM_ROLES, true)) smartispTeamFail(400, 'Elige un rol del equipo.');
    smartispTeamRequireRank($actorRank, $role, 'No puedes crear una cuenta con un rol superior al tuyo.');

    $existing = $pdo->prepare('SELECT id, email, name, surname, role FROM users_rows WHERE LOWER(email) = :email LIMIT 1');
    $existing->execute([':email' => $email]);
    if ($found = $existing->fetch(PDO::FETCH_ASSOC)) {
        $foundRole = smartispNormalizeRole($found['role'] ?? '');
        if (in_array($foundRole, SMARTISP_TEAM_ROLES, true)) smartispTeamFail(409, 'Ya existe una cuenta del equipo con ese correo.', ['existing' => 'team']);
        // Un cliente que ya tiene cuenta: se le da el rol (conserva su contraseña) solo si se confirma.
        if (!filter_var($body['promoteExisting'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            smartispTeamFail(409, 'Ya existe una cuenta de cliente con ese correo. Puedes darle acceso al panel con su contraseña actual.', ['existing' => 'customer']);
        }
        $pdo->prepare('UPDATE users_rows SET role = :role WHERE id = :id')->execute([':role' => $role, ':id' => $found['id']]);
        smartispAuditNote(['target' => (string)$found['id'], 'summary' => 'Dio acceso al panel (' . smartispRoleLabel($role) . ') a la cuenta existente ' . smartispTeamLabel($found)]);
        echo json_encode(['ok' => true, 'promoted' => true, 'member' => ['id' => (string)$found['id'], 'email' => (string)$found['email'], 'name' => (string)($found['name'] ?? ''), 'surname' => (string)($found['surname'] ?? ''), 'role' => $role, 'roleLabel' => smartispRoleLabel($role), 'active' => true], 'temporaryPassword' => null, 'resetSent' => null]);
        exit;
    }

    $id = uniqid('usr_');
    $temporary = $mode === 'password' ? smartispTeamTempPassword() : null;
    // En una invitación la contraseña es aleatoria y nadie la conoce: se define con el enlace del correo.
    $hash = password_hash($temporary ?? bin2hex(random_bytes(24)), PASSWORD_BCRYPT);
    try {
        $pdo->prepare("INSERT INTO users_rows (id, email, password_hash, name, surname, phone, role) VALUES (:id, :email, :hash, :name, :surname, '', :role)")
            ->execute([':id' => $id, ':email' => $email, ':hash' => $hash, ':name' => $name, ':surname' => $surname, ':role' => $role]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) === 1062) smartispTeamFail(409, 'Ya existe una cuenta con ese correo.');
        throw $e;
    }

    $resetSent = null;
    if ($mode === 'invite') {
        try {
            $mail = smartispIssuePasswordReset($pdo, $email, 72 * 3600, '🔑 Activa tu acceso al panel - SmartISP');
            $resetSent = !empty($mail['ok']);
            if (!$resetSent) error_log('SmartISP invitación de equipo a ' . $email . ': ' . ($mail['error'] ?? ''));
        } catch (Throwable $e) {
            error_log('SmartISP invitación de equipo: ' . $e->getMessage());
            $resetSent = false;
        }
    }

    smartispAuditNote(['target' => $id, 'summary' => 'Creó la cuenta ' . $email . ' (' . smartispRoleLabel($role) . ') ' .
        ($mode === 'invite' ? ($resetSent ? 'con invitación por correo' : 'sin poder enviar la invitación') : 'con contraseña temporal')]);
    echo json_encode([
        'ok' => true,
        'member' => ['id' => $id, 'email' => $email, 'name' => $name, 'surname' => $surname, 'role' => $role, 'roleLabel' => smartispRoleLabel($role), 'active' => true],
        'temporaryPassword' => $temporary,
        'resetSent' => $resetSent,
    ]);
    exit;
}

// -------------------------------------------------------------
// CAMBIAR ROL (POST /api/auth/admin-team-role  {id, role})
// -------------------------------------------------------------
if ($action === 'admin-team-role') {
    smartispRequirePost();
    smartispTeamRequireManager($actor);
    $id = trim((string)($body['id'] ?? ''));
    $role = (string)($body['role'] ?? '');
    if (!array_key_exists($role, SMARTISP_ROLE_RANK)) smartispTeamFail(400, 'Rol no válido.');
    smartispTeamRequireRank($actorRank, $role, 'No puedes asignar un rol superior al tuyo.');

    $pdo->beginTransaction();
    try {
        $target = smartispTeamFind($pdo, $id);
        if (!$target) { $pdo->rollBack(); smartispTeamFail(404, 'No se encontró la cuenta.'); }
        $current = smartispNormalizeRole($target['role'] ?? '');
        smartispTeamRequireRank($actorRank, $current, 'No puedes cambiar el rol de una cuenta superior a la tuya.');
        if ($current === $role) { $pdo->rollBack(); echo json_encode(['ok' => true, 'unchanged' => true]); exit; }
        if (smartispIsFullAdmin($current) && !smartispIsFullAdmin($role) && $target['active'] && smartispTeamOtherActiveAdmins($pdo, $id) === 0) {
            $pdo->rollBack();
            smartispTeamFail(409, $id === $actorId ? 'Eres el último administrador activo: no puedes quitarte el rol.' : 'No se puede quitar el rol al último administrador activo.');
        }
        $pdo->prepare('UPDATE users_rows SET role = :role WHERE id = :id')->execute([':role' => $role, ':id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    smartispAuditNote(['target' => $id, 'summary' => 'Rol de ' . smartispTeamLabel($target) . ': ' . smartispRoleLabel($current) . ' → ' . smartispRoleLabel($role)]);
    echo json_encode(['ok' => true, 'role' => $role, 'roleLabel' => smartispRoleLabel($role), 'selfDemoted' => $id === $actorId && !smartispIsFullAdmin($role)]);
    exit;
}

// -------------------------------------------------------------
// DESACTIVAR / REACTIVAR (POST /api/auth/admin-team-status  {id, active})
// -------------------------------------------------------------
if ($action === 'admin-team-status') {
    smartispRequirePost();
    smartispTeamRequireManager($actor);
    if (!smartispUsersHaveColumn($pdo, 'active')) smartispTeamFail(503, 'Falta aplicar la migración 044 para desactivar cuentas.');
    $id = trim((string)($body['id'] ?? ''));
    $active = filter_var($body['active'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (!$active && $id === $actorId) smartispTeamFail(409, 'No puedes desactivar tu propia cuenta.');

    $pdo->beginTransaction();
    try {
        $target = smartispTeamFind($pdo, $id);
        if (!$target) { $pdo->rollBack(); smartispTeamFail(404, 'No se encontró la cuenta.'); }
        smartispTeamRequireRank($actorRank, $target['role'] ?? '', 'No puedes gestionar una cuenta superior a la tuya.');
        if (!$active && $target['active'] && smartispIsFullAdmin($target['role'] ?? '') && smartispTeamOtherActiveAdmins($pdo, $id) === 0) {
            $pdo->rollBack();
            smartispTeamFail(409, 'No se puede desactivar al último administrador activo.');
        }
        $pdo->prepare('UPDATE users_rows SET active = :active WHERE id = :id')->execute([':active' => $active ? 1 : 0, ':id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    // resolveAuthUser ya rechaza la cuenta desactivada; subir la versión invalida además los tokens emitidos.
    if (!$active) smartispBumpTokenVersion($pdo, 'id', $id);

    smartispAuditNote(['target' => $id, 'summary' => ($active ? 'Reactivó la cuenta ' : 'Desactivó la cuenta ') . smartispTeamLabel($target)]);
    echo json_encode(['ok' => true, 'active' => $active]);
    exit;
}

// -------------------------------------------------------------
// CERRAR TODAS LAS SESIONES DE UNA CUENTA (POST /api/auth/admin-team-revoke  {id})
// -------------------------------------------------------------
if ($action === 'admin-team-revoke') {
    smartispRequirePost();
    smartispTeamRequireManager($actor);
    $id = trim((string)($body['id'] ?? ''));
    $target = smartispTeamFind($pdo, $id);
    if (!$target) smartispTeamFail(404, 'No se encontró la cuenta.');
    smartispTeamRequireRank($actorRank, $target['role'] ?? '', 'No puedes gestionar una cuenta superior a la tuya.');
    $version = smartispBumpTokenVersion($pdo, 'id', $id);
    if ($version === null) smartispTeamFail(503, 'Falta aplicar la migración 038 para poder cerrar sesiones.');
    // Si es la propia cuenta, esta pestaña sigue abierta con la versión nueva.
    $token = $id === $actorId ? smartispReissueSession($actor, $version) : null;

    smartispAuditNote(['target' => $id, 'summary' => 'Cerró todas las sesiones de ' . smartispTeamLabel($target)]);
    echo json_encode(['ok' => true, 'token' => $token]);
    exit;
}

// -------------------------------------------------------------
// MI CUENTA (GET /api/auth/admin-my-account)
// -------------------------------------------------------------
if ($action === 'admin-my-account' && $method === 'GET') {
    $lastLogin = null;
    if (smartispUsersHaveColumn($pdo, 'last_login_at')) {
        $stmt = $pdo->prepare('SELECT last_login_at FROM users_rows WHERE id = :id');
        $stmt->execute([':id' => $actorId]);
        $lastLogin = $stmt->fetchColumn() ?: null;
    }
    $recent = [];
    if (smartispAuditAvailable($pdo)) {
        $stmt = $pdo->prepare("SELECT action, ip, user_agent, created_at FROM admin_audit_log
            WHERE user_id = :id AND action IN ('login', 'login-failed', 'login-blocked', 'change-password', 'revoke-other-sessions', 'reset-password')
            ORDER BY created_at DESC, id DESC LIMIT 8");
        $stmt->execute([':id' => $actorId]);
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode([
        'user' => ['id' => $actorId, 'name' => $actor['name'] ?? '', 'surname' => $actor['surname'] ?? '', 'email' => $actor['email'] ?? '', 'phone' => $actor['phone'] ?? '',
            'role' => $actor['role'] ?? '', 'roleLabel' => smartispRoleLabel($actor['role'] ?? ''), 'lastLoginAt' => $lastLogin],
        'recent' => $recent,
        'canManageTeam' => smartispIsFullAdmin($actor['role'] ?? ''),
    ]);
    exit;
}

// -------------------------------------------------------------
// REGISTRO DE ACTIVIDAD (GET /api/auth/admin-audit-log?page=&limit=&user=&act=&from=&to=&q=&format=csv)
// -------------------------------------------------------------
if ($action === 'admin-audit-log' && $method === 'GET') {
    $csv = ($_GET['format'] ?? '') === 'csv';
    if (!smartispAuditAvailable($pdo)) {
        if ($csv) smartispTeamFail(503, 'Falta aplicar la migración 044 del registro de actividad.');
        echo json_encode(['available' => false, 'entries' => [], 'total' => 0, 'page' => 1, 'limit' => 50, 'actions' => [], 'users' => []]);
        exit;
    }
    $isDay = static fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
    $where = [];
    $params = [];
    $user = trim((string)($_GET['user'] ?? ''));
    if ($user !== '') { $where[] = '(user_id = :user OR user_email = :user_email)'; $params[':user'] = $user; $params[':user_email'] = strtolower($user); }
    // "act" y no "action": ese nombre ya lo usa el router para elegir el endpoint.
    $act = trim((string)($_GET['act'] ?? ''));
    if ($act !== '') {
        // "login-*" agrupa todos los eventos de acceso.
        if (substr($act, -1) === '*') { $where[] = 'action LIKE :act'; $params[':act'] = str_replace(['%', '_'], ['\\%', '\\_'], substr($act, 0, -1)) . '%'; }
        else { $where[] = 'action = :act'; $params[':act'] = $act; }
    }
    if ($isDay($_GET['from'] ?? null)) { $where[] = 'created_at >= :from'; $params[':from'] = smartispLocalDayStartUtc((string)$_GET['from']); }
    if ($isDay($_GET['to'] ?? null)) { $where[] = 'created_at <= :to'; $params[':to'] = smartispLocalDayEndUtc((string)$_GET['to']); }
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $where[] = '(summary LIKE :q1 OR target LIKE :q2 OR user_email LIKE :q3 OR action LIKE :q4 OR ip LIKE :q5)';
        foreach ([1, 2, 3, 4, 5] as $n) $params[":q$n"] = $like;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $columns = 'id, user_id, user_email, action, method, target, summary, status, ip, user_agent, created_at';

    if ($csv) {
        $stmt = $pdo->prepare("SELECT $columns FROM admin_audit_log $whereSql ORDER BY created_at DESC, id DESC LIMIT 5000");
        $stmt->execute($params);
        $cell = static function ($v): string {
            $s = (string)($v ?? '');
            if (preg_match('/^[=+\-@\t\r]/', $s)) $s = "'" . $s;
            return preg_match('/[";\n\r]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
        };
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="smartisp-actividad-' . smartispLocalToday() . '.csv"');
        echo "\xEF\xBB\xBF" . implode(';', ['Fecha (Ecuador)', 'Usuario', 'Acción', 'Método', 'Objetivo', 'Resumen', 'Resultado HTTP', 'IP', 'Navegador']) . "\r\n";
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo implode(';', array_map($cell, [smartispUtcToLocal($row['created_at']), $row['user_email'], $row['action'], $row['method'], $row['target'], $row['summary'], $row['status'], $row['ip'], $row['user_agent']])) . "\r\n";
        }
        exit;
    }

    $limit = max(10, min(100, (int)($_GET['limit'] ?? 50)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $count = $pdo->prepare("SELECT COUNT(*) FROM admin_audit_log $whereSql");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $stmt = $pdo->prepare("SELECT $columns FROM admin_audit_log $whereSql ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET " . (($page - 1) * $limit));
    $stmt->execute($params);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($entries as &$entry) $entry['status'] = $entry['status'] === null ? null : (int)$entry['status'];
    unset($entry);

    $actions = $pdo->query('SELECT DISTINCT action FROM admin_audit_log ORDER BY action LIMIT 300')->fetchAll(PDO::FETCH_COLUMN);
    $users = $pdo->query("SELECT user_id AS id, MAX(user_email) AS email FROM admin_audit_log WHERE user_id IS NOT NULL AND user_id <> '' GROUP BY user_id ORDER BY email LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['available' => true, 'entries' => $entries, 'total' => $total, 'page' => $page, 'limit' => $limit, 'actions' => $actions, 'users' => $users]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
exit;
