<?php
// Parte de api/router.php, dividido por dominio (DEV-20261005-015): autenticacion, registro y cuenta.
// Comparte el scope de router.php via require (no es una funcion): $pdo, $body, $action,
// $method y los helpers (getAuthUser, isAdminUser, requireAdminAuth, etc.) ya estan definidos
// cuando este archivo se incluye. Cada bloque se autogestiona con 'if ($action === ...) { ... exit; }',
// igual que antes; solo cambio donde vive el codigo, no la logica.

// IP para los límites de intentos. Solo REMOTE_ADDR: X-Forwarded-For y CF-Connecting-IP los
// escribe el cliente, y con cambiarlos en cada petición el límite no se aplicaba nunca.
function smartispClientIp(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
}

// Verifica una contraseña contra lo guardado: hash moderno, o formatos heredados (texto plano,
// MD5, SHA1) que se migran al iniciar sesión. El texto plano solo se acepta si lo guardado no es
// un hash moderno; si no, enviar el hash bcrypt tal cual (p. ej. filtrado de un respaldo)
// abría la cuenta.
function smartispPasswordMatches(string $password, string $stored): bool {
    if ($stored === '' || $password === '') return false;
    if (password_verify($password, $stored)) return true;
    // Por prefijo y no con password_get_info(): esa solo reconoce $2y$, y los hashes $2a$ que
    // genera bcryptjs (server.js, tests) pasarían por la comparación en texto plano.
    if (preg_match('/^\$(2[abxy]|argon2id?)\$/', $stored)) return false;
    return hash_equals($stored, $password)
        || hash_equals($stored, md5($password))
        || hash_equals($stored, sha1($password));
}

if ($action === 'update-profile' && $method === 'POST') {
    $user = getAuthUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Debes iniciar sesión para actualizar tu perfil.']);
        exit;
    }

    try {
        $uTable = getUsersTableName($pdo);
        $name = trim($body['name'] ?? ($user['name'] ?? ''));
        $surname = trim($body['surname'] ?? ($user['surname'] ?? ''));
        $phone = trim($body['phone'] ?? ($user['phone'] ?? ''));
        $email = strtolower(trim($body['email'] ?? ($user['email'] ?? '')));

        if (!$name) {
            http_response_code(400);
            echo json_encode(['error' => 'El nombre es obligatorio.']);
            exit;
        }

        if ($email && $email !== strtolower($user['email'] ?? '')) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'Ingresa un correo electrónico válido.']);
                exit;
            }
            $check = $pdo->prepare("SELECT id FROM `$uTable` WHERE LOWER(email) = :email AND id != :id LIMIT 1");
            $check->execute([':email' => $email, ':id' => $user['id']]);
            if ($check->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'Ese correo ya está registrado por otro usuario.']);
                exit;
            }
        } else {
            $email = $user['email'];
        }

        $stmt = $pdo->prepare("UPDATE `$uTable` SET name = :name, surname = :surname, phone = :phone, email = :email, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([
            ':name'    => $name,
            ':surname' => $surname,
            ':phone'   => $phone,
            ':email'   => $email,
            ':id'      => $user['id']
        ]);

        $user['name'] = $name;
        $user['surname'] = $surname;
        $user['phone'] = $phone;
        $user['email'] = $email;
        $_SESSION['auth_user_name'] = $name;
        $_SESSION['auth_user_email'] = $email;

        echo json_encode([
            'ok'   => true,
            'user' => [
                'id'      => $user['id'],
                'name'    => $name,
                'surname' => $surname,
                'email'   => $email,
                'phone'   => $phone,
                'role'    => $user['role'] ?? 'customer'
            ]
        ]);
        exit;
    } catch (Throwable $e) {
        error_log('SmartISP update-profile error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo actualizar tu perfil. Inténtalo nuevamente.']);
        exit;
    }
}

// -------------------------------------------------------------
// LISTA DE DESEOS IDEMPOTENTE (/api/auth/customer-wishlist) (QA-022, QA-023)
// -------------------------------------------------------------

if ($action === 'login' && $method === 'POST') {
    // Rate limiting por IP (mismo patron que request-password-reset / QA-034): sin esto, un
    // ataque de diccionario contra un correo de admin conocido puede mandar intentos sin limite.
    $loginRateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'login_' . md5(smartispClientIp()) . '.json';
    $loginRateNow = time();
    $loginRateWindow = 900; // 15 minutos
    $loginMaxAttempts = 8;
    $loginAttempts = [];
    if (file_exists($loginRateFile)) {
        $loginData = json_decode(@file_get_contents($loginRateFile), true);
        if (is_array($loginData)) {
            $loginAttempts = array_values(array_filter($loginData, fn($t) => ($loginRateNow - (int)$t) < $loginRateWindow));
        }
    }
    if (count($loginAttempts) >= $loginMaxAttempts) {
        http_response_code(429);
        header('Retry-After: ' . $loginRateWindow);
        echo json_encode([
            'error' => 'Demasiados intentos de inicio de sesión. Por favor intenta de nuevo en 15 minutos.',
            'retry_after' => $loginRateWindow
        ]);
        exit;
    }
    $loginAttempts[] = $loginRateNow;
    @file_put_contents($loginRateFile, json_encode($loginAttempts));

    try {
        $uTable = getUsersTableName($pdo);
        $email = trim(strtolower($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $adminOnly = filter_var($body['adminOnly'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$email || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Por favor ingresa tu correo y contraseña.']);
            exit;
        }

        // Descubrir columnas de la tabla de usuarios
        $colsStmt = $pdo->query("DESCRIBE `$uTable`");
        $cols = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_COLUMN) : [];

        $emailCol = null;
        foreach (['email', 'correo', 'mail', 'user_email', 'username', 'usuario', 'COL 2', 'col 2', 'COL_2', 'col_2'] as $c) {
            if (in_array($c, $cols, true)) { $emailCol = $c; break; }
        }
        if (!$emailCol && isset($cols[1])) $emailCol = $cols[1];
        if (!$emailCol) $emailCol = 'email';

        $passCol = null;
        foreach (['password_hash', 'password', 'clave', 'pass', 'hash', 'COL 3', 'col 3', 'COL_3', 'col_3'] as $c) {
            if (in_array($c, $cols, true)) { $passCol = $c; break; }
        }
        if (!$passCol && isset($cols[2])) $passCol = $cols[2];
        if (!$passCol) $passCol = 'password_hash';

        $stmt = $pdo->prepare("SELECT * FROM `$uTable` WHERE `$emailCol` = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Correo o contraseña incorrectos.']);
            exit;
        }

        $storedPass = (string)($user[$passCol] ?? '');
        $modernHashValid = password_verify($password, $storedPass);
        $match = $modernHashValid || smartispPasswordMatches($password, $storedPass);

        if ($match) {
            if (!$modernHashValid && !empty($user['id'])) {
                $upgrade = $pdo->prepare("UPDATE `$uTable` SET `$passCol` = :hash WHERE id = :id");
                $upgrade->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
            }
            unset($user[$passCol]);
            if (isset($user['password'])) unset($user['password']);
            if (isset($user['password_hash'])) unset($user['password_hash']);

            $normUser = [
                'id'      => (string)($user['id'] ?? ($user['COL 1'] ?? uniqid('usr_'))),
                'name'    => (string)($user['name'] ?? ($user['nombre'] ?? ($user['COL 4'] ?? 'Usuario'))),
                'surname' => (string)($user['surname'] ?? ($user['apellido'] ?? ($user['COL 5'] ?? ''))),
                'email'   => (string)($user[$emailCol] ?? ($user['COL 2'] ?? $email)),
                'phone'   => (string)($user['phone'] ?? ($user['telefono'] ?? ($user['COL 6'] ?? ''))),
                'role'    => smartispNormalizeRole($user['role'] ?? ($user['rol'] ?? ($user['COL 8'] ?? 'customer')))
            ];
            if ($adminOnly && !isAdminUser($normUser)) {
                http_response_code(403);
                echo json_encode(['error' => 'Esta cuenta no tiene acceso al panel administrativo.']);
                exit;
            }
            if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
            $_SESSION['user'] = $normUser;
            $token = null;
            if (isAdminUser($normUser)) {
                try { $token = generateAdminAuthToken($normUser); } catch (Throwable $e) { $token = null; }
            }
            echo json_encode(['user' => $normUser, 'token' => $token]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Correo o contraseña incorrectos.']);
        }
    } catch (Throwable $e) {
        error_log('SmartISP login error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo iniciar sesión por un error del servidor.']);
    }
    exit;
}


if ($action === 'register' && $method === 'POST') {
    try {
        $uTable = getUsersTableName($pdo);
        $id = uniqid('usr_');
        $name = trim($body['name'] ?? '');
        $surname = trim($body['surname'] ?? '');
        $email = trim(strtolower($body['email'] ?? ''));
        $phone = trim($body['phone'] ?? '');
        $password = (string)($body['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
            http_response_code(400);
            echo json_encode(['error' => 'Ingresa un correo válido y una contraseña de al menos 6 caracteres.']);
            exit;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO `$uTable` (id, email, password_hash, name, surname, phone, role)
                               VALUES (:id, :email, :hash, :name, :surname, :phone, 'customer')");
        $stmt->execute([
            ':id'      => $id,
            ':email'   => $email,
            ':hash'    => $hash,
            ':name'    => $name,
            ':surname' => $surname,
            ':phone'   => $phone
        ]);

        $regUser = [
            'id'      => $id,
            'name'    => $name,
            'surname' => $surname,
            'email'   => $email,
            'phone'   => $phone,
            'role'    => 'customer'
        ];
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION['user'] = $regUser;
        echo json_encode(['user' => $regUser]);
    } catch (Throwable $e) {
        // No devolver el mensaje de MySQL: incluía la fila duplicada ("Duplicate entry '<correo>'").
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062) {
            http_response_code(409);
            echo json_encode(['error' => 'Ya existe una cuenta con ese correo. Inicia sesión o recupera tu contraseña.']);
        } else {
            error_log('SmartISP register error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'No se pudo crear la cuenta. Inténtalo nuevamente.']);
        }
    }
    exit;
}


if ($action === 'me' && $method === 'GET') {
    $user = getAuthUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['user' => null, 'error' => 'No hay una sesión activa.']);
        exit;
    }
    $token = null;
    if ($user && is_array($user) && isAdminUser($user)) {
        try { $token = generateAdminAuthToken($user); } catch (Throwable $e) { $token = null; }
    }
    echo json_encode(['user' => $user, 'token' => $token]);
    exit;
}


if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    @session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}


if ($action === 'request-password-reset' && $method === 'POST') {
    // QA-034: Rate Limiting estricto por IP (máximo 3 solicitudes por ventana de 15 minutos)
    $rateKey = 'pwd_reset_' . md5(smartispClientIp());
    $rateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $rateKey . '.json';
    $now = time();
    $window = 900; // 15 minutos
    $maxAttempts = 3;
    $history = [];
    if (file_exists($rateFile)) {
        $data = json_decode(@file_get_contents($rateFile), true);
        if (is_array($data)) {
            $history = array_filter($data, fn($t) => ($now - (int)$t) < $window);
        }
    }
    if (count($history) >= $maxAttempts) {
        http_response_code(429);
        header('Retry-After: ' . $window);
        echo json_encode([
            'ok' => false,
            'error' => 'Has excedido el límite de solicitudes de recuperación de contraseña. Por favor intenta de nuevo en 15 minutos.',
            'retry_after' => $window
        ]);
        exit;
    }
    $history[] = $now;
    @file_put_contents($rateFile, json_encode(array_values($history)));

    try {
        $email = trim(strtolower($body['email'] ?? ''));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Por favor ingresa un correo electrónico válido.']);
            exit;
        }

        $uTable = getUsersTableName($pdo);
        $colsStmt = $pdo->query("DESCRIBE `$uTable`");
        $cols = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $emailCol = null;
        foreach (['email', 'correo', 'mail', 'user_email', 'username', 'usuario', 'COL 2', 'col 2', 'COL_2', 'col_2'] as $c) {
            if (in_array($c, $cols, true)) { $emailCol = $c; break; }
        }
        if (!$emailCol && isset($cols[1])) $emailCol = $cols[1];
        if (!$emailCol) $emailCol = 'email';

        $stmt = $pdo->prepare("SELECT * FROM `$uTable` WHERE LOWER(`$emailCol`) = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        // Para evitar enumeración maliciosa de cuentas, si no existe devolvemos ok: true
        if (!$user) {
            echo json_encode([
                'ok' => true,
                'message' => 'Si el correo está registrado, recibirás un enlace para recuperar tu contraseña.'
            ]);
            exit;
        }

        // Generar token criptográfico seguro
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hora de validez

        // Eliminar tokens previos de este correo
        try {
            $delStmt = $pdo->prepare("DELETE FROM password_resets WHERE LOWER(email) = :email");
            $delStmt->execute([':email' => $email]);
        } catch (Throwable $e) {}

        // Guardar nuevo token en password_resets
        $insStmt = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (:email, :token, :expires_at)");
        $insStmt->execute([
            ':email'      => $email,
            ':token'      => $token,
            ':expires_at' => $expiresAt
        ]);

        // Construir URL base dinámica
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'smart-isp.com.ec';
        if (!empty($_SERVER['HTTP_ORIGIN'])) {
            $baseUrl = rtrim($_SERVER['HTTP_ORIGIN'], '/');
        } elseif (!empty($_SERVER['HTTP_REFERER'])) {
            $parsed = parse_url($_SERVER['HTTP_REFERER']);
            if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
                $baseUrl = $parsed['scheme'] . '://' . $parsed['host'] . (!empty($parsed['port']) && !in_array((int)$parsed['port'], [80, 443], true) ? ':' . $parsed['port'] : '');
            } else {
                $baseUrl = "$proto://$host";
            }
        } else {
            $baseUrl = "$proto://$host";
        }

        $resetUrl = "$baseUrl/reset-password.html?token=" . urlencode($token);
        $resetHtml = buildPasswordResetHtml($email, $resetUrl);

        $mailRes = sendSmartEmail($pdo, $email, '🔐 Restablece tu contraseña - SmartISP', $resetHtml);
        if (!$mailRes['ok']) {
            error_log("Fallo al enviar correo de recuperación a $email: " . ($mailRes['error'] ?? ''));
            http_response_code(500);
            echo json_encode([
                'ok'    => false,
                'error' => 'No se pudo enviar el correo de recuperación: ' . ($mailRes['error'] ?? 'Error de despacho SMTP/mail.')
            ]);
            exit;
        }

        echo json_encode([
            'ok'      => true,
            'message' => 'Si el correo está registrado, recibirás un enlace para recuperar tu contraseña.'
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al procesar la solicitud: ' . $e->getMessage()]);
    }
    exit;
}


if ($action === 'reset-password' && $method === 'POST') {
    try {
        $token = trim((string)($body['token'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $confirmation = (string)($body['confirmation'] ?? '');

        if (!$token) {
            http_response_code(400);
            echo json_encode(['error' => 'Token de seguridad no proporcionado o inválido.']);
            exit;
        }

        if (strlen($password) < 6) {
            http_response_code(400);
            echo json_encode(['error' => 'La contraseña debe tener al menos 6 caracteres.']);
            exit;
        }

        if ($password !== $confirmation) {
            http_response_code(400);
            echo json_encode(['error' => 'Las contraseñas no coinciden.']);
            exit;
        }

        // Buscar token en password_resets
        $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = :token LIMIT 1");
        $stmt->execute([':token' => $token]);
        $resetRow = $stmt->fetch();

        if (!$resetRow) {
            http_response_code(400);
            echo json_encode(['error' => 'El enlace no es válido o ya fue utilizado. Por favor solicita uno nuevo.']);
            exit;
        }

        // Verificar expiración
        $expiresAt = strtotime($resetRow['expires_at'] ?? '2000-01-01');
        if ($expiresAt < time()) {
            http_response_code(400);
            echo json_encode(['error' => 'El enlace de recuperación ha expirado. Por favor solicita uno nuevo.']);
            exit;
        }

        $email = strtolower(trim($resetRow['email']));
        $uTable = getUsersTableName($pdo);
        $colsStmt = $pdo->query("DESCRIBE `$uTable`");
        $cols = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_COLUMN) : [];

        $emailCol = null;
        foreach (['email', 'correo', 'mail', 'user_email', 'username', 'usuario', 'COL 2', 'col 2', 'COL_2', 'col_2'] as $c) {
            if (in_array($c, $cols, true)) { $emailCol = $c; break; }
        }
        if (!$emailCol && isset($cols[1])) $emailCol = $cols[1];
        if (!$emailCol) $emailCol = 'email';

        $passCol = null;
        foreach (['password_hash', 'password', 'clave', 'pass', 'hash', 'COL 3', 'col 3', 'COL_3', 'col_3'] as $c) {
            if (in_array($c, $cols, true)) { $passCol = $c; break; }
        }
        if (!$passCol && isset($cols[2])) $passCol = $cols[2];
        if (!$passCol) $passCol = 'password_hash';

        // Actualizar contraseña con BCRYPT
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $updateStmt = $pdo->prepare("UPDATE `$uTable` SET `$passCol` = :hash WHERE LOWER(`$emailCol`) = :email");
        $updateStmt->execute([
            ':hash'  => $hash,
            ':email' => $email
        ]);

        // Consumir token para que no se pueda reutilizar
        $delStmt = $pdo->prepare("DELETE FROM password_resets WHERE token = :token OR LOWER(email) = :email");
        $delStmt->execute([':token' => $token, ':email' => $email]);

        echo json_encode([
            'ok'      => true,
            'message' => 'Contraseña actualizada exitosamente. Ya puedes iniciar sesión con tu nueva contraseña.'
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al restablecer la contraseña: ' . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// CAMBIO DE CONTRASEÑA DE USUARIO (/api/auth/change-password)
// -------------------------------------------------------------

if (($action === 'change-password' || $action === 'change_password') && $method === 'POST') {
    try {
        // Solo con sesión. Antes, sin sesión, bastaba mandar 'email' para buscar la cuenta: eso
        // permitía probar contraseñas de cualquier cuenta aquí, sin el límite de intentos del login.
        $user = getAuthUser();

        if (!$user || empty($user['id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Debes iniciar sesión para cambiar tu contraseña.']);
            exit;
        }

        $currentPassword = (string)($body['currentPassword'] ?? ($body['current_password'] ?? ''));
        $newPassword = (string)($body['newPassword'] ?? ($body['new_password'] ?? ''));
        $confirmation = (string)($body['confirmation'] ?? ($body['confirm_password'] ?? ''));

        if (empty($currentPassword)) {
            http_response_code(400);
            echo json_encode(['error' => 'Por favor ingresa tu contraseña actual.']);
            exit;
        }

        if (strlen($newPassword) < 6) {
            http_response_code(400);
            echo json_encode(['error' => 'La nueva contraseña debe tener al menos 6 caracteres.']);
            exit;
        }

        if (!empty($confirmation) && $newPassword !== $confirmation) {
            http_response_code(400);
            echo json_encode(['error' => 'La confirmación no coincide con la nueva contraseña.']);
            exit;
        }

        // getAuthUser() ya validó la cuenta contra users_rows (id/email/password_hash, ver
        // migración 004): no hace falta volver a adivinar la tabla ni las columnas.
        $stmt = $pdo->prepare('SELECT password_hash FROM users_rows WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $user['id']]);
        $storedPass = $stmt->fetchColumn();

        if ($storedPass === false) {
            http_response_code(404);
            echo json_encode(['error' => 'No se encontró la cuenta de usuario.']);
            exit;
        }

        if (!smartispPasswordMatches($currentPassword, (string)$storedPass)) {
            http_response_code(401);
            echo json_encode(['error' => 'La contraseña actual no es correcta.']);
            exit;
        }

        $updateStmt = $pdo->prepare('UPDATE users_rows SET password_hash = :hash WHERE id = :id');
        $updateStmt->execute([
            ':hash' => password_hash($newPassword, PASSWORD_BCRYPT),
            ':id'   => $user['id']
        ]);

        echo json_encode([
            'ok'      => true,
            'message' => 'Contraseña actualizada exitosamente.'
        ]);
        exit;
    } catch (Throwable $e) {
        error_log('SmartISP change-password error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo cambiar la contraseña. Inténtalo nuevamente.']);
        exit;
    }
}
