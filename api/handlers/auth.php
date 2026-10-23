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

// Límite de intentos por IP con ventana deslizante, guardado en el tmp del servidor. Corta la
// petición con 429 si ya se alcanzó; si no, registra este intento. El flock evita que dos
// peticiones simultáneas lean el mismo conteo y ambas pasen.
function smartispEnforceRateLimit(string $bucket, int $maxAttempts, int $windowSeconds, string $message): void {
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $bucket . '_' . md5(smartispClientIp()) . '.json';
    $handle = @fopen($file, 'c+');
    if (!$handle) return; // Sin tmp escribible no se bloquea el login; queda en el log.
    flock($handle, LOCK_EX);
    $now = time();
    $attempts = json_decode((string)stream_get_contents($handle), true);
    $attempts = array_values(array_filter(is_array($attempts) ? $attempts : [], fn($t) => ($now - (int)$t) < $windowSeconds));
    $limited = count($attempts) >= $maxAttempts;
    if (!$limited) {
        $attempts[] = $now;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($attempts));
    }
    flock($handle, LOCK_UN);
    fclose($handle);

    if ($limited) {
        http_response_code(429);
        header('Retry-After: ' . $windowSeconds);
        echo json_encode(['ok' => false, 'error' => $message, 'retry_after' => $windowSeconds]);
        exit;
    }
}

// Base de los enlaces que salen por correo (recuperar contraseña). Nunca de Origin/Referer: los
// pone quien hace la petición, y con eso un atacante podía pedir el reset de otra cuenta y
// hacer que el correo legítimo llevara el token a su propio dominio. SITE_URL (.env) manda; si
// no está, solo se acepta el Host si es un dominio conocido o local.
function smartispPublicBaseUrl(): string {
    $configured = trim((string)(getenv('SITE_URL') ?: ''));
    if ($configured !== '') return rtrim($configured, '/');

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $hostname = preg_replace('/:\d+$/', '', $host);
    if (in_array($hostname, ['localhost', '127.0.0.1'], true)) {
        return 'http://' . $host;
    }
    if (in_array($hostname, ['smart-isp.com.ec', 'www.smart-isp.com.ec'], true)) {
        return 'https://' . $hostname;
    }
    return 'https://smart-isp.com.ec';
}

if ($action === 'update-profile' && $method === 'POST') {
    $user = getAuthUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Debes iniciar sesión para actualizar tu perfil.']);
        exit;
    }

    try {
        $uTable = 'users_rows';
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
// INICIO DE SESIÓN (/api/auth/login)
// -------------------------------------------------------------
// users_rows tiene columnas fijas desde la migración 004 (id, email único, password_hash, name,
// surname, phone, role): getAuthUser() y el registro ya las usaban así. Antes login/reset las
// volvían a adivinar con DESCRIBE en cada intento (email|correo|COL 2...).

if ($action === 'login' && $method === 'POST') {
    // Sin límite, un ataque de diccionario contra un correo de admin conocido no tiene freno.
    smartispEnforceRateLimit('login', 8, 900, 'Demasiados intentos de inicio de sesión. Por favor intenta de nuevo en 15 minutos.');

    try {
        $email = trim(strtolower($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $adminOnly = filter_var($body['adminOnly'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$email || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Por favor ingresa tu correo y contraseña.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT id, email, password_hash, name, surname, phone, role FROM users_rows WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        $storedPass = (string)($user['password_hash'] ?? '');
        $modernHashValid = $user && password_verify($password, $storedPass);
        if (!$user || (!$modernHashValid && !smartispPasswordMatches($password, $storedPass))) {
            http_response_code(401);
            echo json_encode(['error' => 'Correo o contraseña incorrectos.']);
            exit;
        }

        if (!$modernHashValid) {
            // Formato heredado (texto plano/MD5/SHA1): se migra a hash moderno en este login.
            $upgrade = $pdo->prepare('UPDATE users_rows SET password_hash = :hash WHERE id = :id');
            $upgrade->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
        }

        $normUser = [
            'id'      => (string)$user['id'],
            'name'    => (string)($user['name'] ?? 'Usuario'),
            'surname' => (string)($user['surname'] ?? ''),
            'email'   => (string)$user['email'],
            'phone'   => (string)($user['phone'] ?? ''),
            'role'    => smartispNormalizeRole($user['role'] ?? 'customer')
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
    } catch (Throwable $e) {
        error_log('SmartISP login error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo iniciar sesión por un error del servidor.']);
    }
    exit;
}


if ($action === 'register' && $method === 'POST') {
    try {
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
        $stmt = $pdo->prepare("INSERT INTO users_rows (id, email, password_hash, name, surname, phone, role)
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
    if (isAdminUser($user)) {
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
    // QA-034: máximo 3 solicitudes por IP cada 15 minutos.
    smartispEnforceRateLimit('pwd_reset', 3, 900, 'Has excedido el límite de solicitudes de recuperación de contraseña. Por favor intenta de nuevo en 15 minutos.');

    $genericOk = [
        'ok' => true,
        'message' => 'Si el correo está registrado, recibirás un enlace para recuperar tu contraseña.'
    ];

    try {
        $email = trim(strtolower($body['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Por favor ingresa un correo electrónico válido.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT id FROM users_rows WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);

        // Para evitar enumeración de cuentas, si no existe se responde igual que si existiera.
        if (!$stmt->fetchColumn()) {
            echo json_encode($genericOk);
            exit;
        }

        $token = bin2hex(random_bytes(32));
        $pdo->prepare('DELETE FROM password_resets WHERE email = :email')->execute([':email' => $email]);
        $pdo->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (:email, :token, :expires_at)')->execute([
            ':email'      => $email,
            ':token'      => $token,
            ':expires_at' => date('Y-m-d H:i:s', time() + 3600) // 1 hora de validez
        ]);

        $resetUrl = smartispPublicBaseUrl() . '/reset-password.html?token=' . urlencode($token);
        $mailRes = sendSmartEmail($pdo, $email, '🔐 Restablece tu contraseña - SmartISP', buildPasswordResetHtml($email, $resetUrl));
        if (!$mailRes['ok']) {
            error_log("Fallo al enviar correo de recuperación a $email: " . ($mailRes['error'] ?? ''));
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo enviar el correo de recuperación. Inténtalo más tarde.']);
            exit;
        }

        echo json_encode($genericOk);
    } catch (Throwable $e) {
        error_log('SmartISP request-password-reset error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo procesar la solicitud. Inténtalo nuevamente.']);
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

        $stmt = $pdo->prepare('SELECT email, expires_at FROM password_resets WHERE token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);
        $resetRow = $stmt->fetch();

        if (!$resetRow) {
            http_response_code(400);
            echo json_encode(['error' => 'El enlace no es válido o ya fue utilizado. Por favor solicita uno nuevo.']);
            exit;
        }

        if (strtotime($resetRow['expires_at'] ?? '2000-01-01') < time()) {
            http_response_code(400);
            echo json_encode(['error' => 'El enlace de recuperación ha expirado. Por favor solicita uno nuevo.']);
            exit;
        }

        $email = strtolower(trim($resetRow['email']));
        $pdo->prepare('UPDATE users_rows SET password_hash = :hash WHERE email = :email')->execute([
            ':hash'  => password_hash($password, PASSWORD_BCRYPT),
            ':email' => $email
        ]);

        // Consumir el token (y cualquier otro de ese correo) para que no se pueda reutilizar.
        $pdo->prepare('DELETE FROM password_resets WHERE token = :token OR email = :email')->execute([':token' => $token, ':email' => $email]);

        echo json_encode([
            'ok'      => true,
            'message' => 'Contraseña actualizada exitosamente. Ya puedes iniciar sesión con tu nueva contraseña.'
        ]);
    } catch (Throwable $e) {
        error_log('SmartISP reset-password error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo restablecer la contraseña. Inténtalo nuevamente.']);
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
