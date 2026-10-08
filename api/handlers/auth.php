<?php
// Parte de api/router.php, dividido por dominio (DEV-20261005-015): autenticacion, registro y cuenta.
// Comparte el scope de router.php via require (no es una funcion): $pdo, $body, $action,
// $method y los helpers (getAuthUser, isAdminUser, requireAdminAuth, etc.) ya estan definidos
// cuando este archivo se incluye. Cada bloque se autogestiona con 'if ($action === ...) { ... exit; }',
// igual que antes; solo cambio donde vive el codigo, no la logica.

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
        http_response_code(500);
        echo json_encode(['error' => 'Error al actualizar perfil: ' . $e->getMessage()]);
        exit;
    }
}

// -------------------------------------------------------------
// LISTA DE DESEOS IDEMPOTENTE (/api/auth/customer-wishlist) (QA-022, QA-023)
// -------------------------------------------------------------

if ($action === 'login' && $method === 'POST') {
    try {
        $uTable = getUsersTableName($pdo);
        $email = trim(strtolower($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');

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
        $match = $modernHashValid;

        if (!$match && $storedPass === $password) {
            $match = true;
        } elseif (!$match && md5($password) === $storedPass) {
            $match = true;
        } elseif (!$match && sha1($password) === $storedPass) {
            $match = true;
        }

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

        if (!$email || strlen($password) < 6) {
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
        http_response_code(400);
        echo json_encode(['error' => 'Error al registrar usuario: ' . $e->getMessage()]);
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
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $clientIp = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    $rateKey = 'pwd_reset_' . md5($clientIp);
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
        $user = getAuthUser();
        $reqEmail = strtolower(trim((string)($body['email'] ?? '')));
        if (!$user && !empty($reqEmail)) {
            $uTable = getUsersTableName($pdo);
            $stmt = $pdo->prepare("SELECT * FROM `$uTable` WHERE LOWER(TRIM(email)) = :email LIMIT 1");
            $stmt->execute([':email' => $reqEmail]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                $user = $found;
            }
        }

        if (!$user || (empty($user['id']) && empty($user['email']))) {
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

        $uTable = getUsersTableName($pdo);
        $colsStmt = $pdo->query("SHOW COLUMNS FROM `$uTable`");
        $cols = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $emailCol = 'email';
        foreach (['email', 'correo', 'mail', 'COL 2', 'col 2', 'COL_2', 'col_2'] as $c) {
            if (in_array($c, $cols, true)) { $emailCol = $c; break; }
        }
        $passCol = 'password_hash';
        foreach (['password_hash', 'password', 'clave', 'pass', 'hash', 'COL 3', 'col 3', 'COL_3', 'col_3'] as $c) {
            if (in_array($c, $cols, true)) { $passCol = $c; break; }
        }
        if (!$passCol && isset($cols[2])) $passCol = $cols[2];
        if (!$passCol) $passCol = 'password_hash';

        $userEmail = strtolower(trim((string)($user[$emailCol] ?? ($user['email'] ?? $reqEmail))));
        $userId = $user['id'] ?? null;

        $dbUser = null;
        if ($userId) {
            $stmt = $pdo->prepare("SELECT * FROM `$uTable` WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $userId]);
            $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (empty($dbUser) && $userEmail) {
            $stmt = $pdo->prepare("SELECT * FROM `$uTable` WHERE LOWER(`$emailCol`) = :email LIMIT 1");
            $stmt->execute([':email' => $userEmail]);
            $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$dbUser) {
            http_response_code(404);
            echo json_encode(['error' => 'No se encontró la cuenta de usuario.']);
            exit;
        }

        $storedPass = (string)($dbUser[$passCol] ?? '');
        $match = false;

        if (password_verify($currentPassword, $storedPass)) {
            $match = true;
        } elseif ($storedPass === $currentPassword) {
            $match = true;
        } elseif (md5($currentPassword) === $storedPass) {
            $match = true;
        } elseif (sha1($currentPassword) === $storedPass) {
            $match = true;
        }

        if (!$match) {
            http_response_code(401);
            echo json_encode(['error' => 'La contraseña actual no es correcta.']);
            exit;
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $updateStmt = $pdo->prepare("UPDATE `$uTable` SET `$passCol` = :hash WHERE id = :id OR LOWER(`$emailCol`) = :email");
        $updateStmt->execute([
            ':hash'  => $newHash,
            ':id'    => $dbUser['id'] ?? ($userId ?? ''),
            ':email' => $userEmail
        ]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                $_SESSION['user']['password_hash'] = $newHash;
            }
        }

        echo json_encode([
            'ok'      => true,
            'message' => 'Contraseña actualizada exitosamente.'
        ]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al cambiar la contraseña: ' . $e->getMessage()]);
        exit;
    }
}
