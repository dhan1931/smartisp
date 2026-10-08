<?php
// Parte de api/router.php, dividido por dominio (DEV-20261005-015): diagnostico de BD/entorno.
// Comparte el scope de router.php via require (no es una funcion): $pdo, $body, $action,
// $method y los helpers (getAuthUser, isAdminUser, requireAdminAuth, etc.) ya estan definidos
// cuando este archivo se incluye. Cada bloque se autogestiona con 'if ($action === ...) { ... exit; }',
// igual que antes; solo cambio donde vive el codigo, no la logica.

// -------------------------------------------------------------
// DIAGNÓSTICO DE BASE DE DATOS Y ESQUEMA (Solo Administrador)
// -------------------------------------------------------------
if ($action === 'test-db' || $action === 'test-products' || $action === 'test-supabase') {
    requireAdminAuth();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'rowsFound' => 0,
            'error' => 'No se pudo conectar a la base de datos MySQL en Hostinger.'
        ]);
        exit;
    }

    try {
        $pTable = getProductsTableName($pdo);
        $uTable = getUsersTableName($pdo);

        $pCount = (int)$pdo->query("SELECT COUNT(*) FROM `$pTable`")->fetchColumn();
        $uCount = (int)$pdo->query("SELECT COUNT(*) FROM `$uTable`")->fetchColumn();

        // Esquema de tablas
        $schema = [];
        $tStmt = $pdo->query("SHOW TABLES");
        while ($t = $tStmt->fetch(PDO::FETCH_NUM)) {
            $tbl = $t[0];
            $cStmt = $pdo->query("DESCRIBE `$tbl`");
            $schema[$tbl] = $cStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        // Muestra de usuarios (ocultando clave)
        $uList = $pdo->query("SELECT id, email, name, surname, phone, role FROM `$uTable` LIMIT 10")->fetchAll() ?: [];
        foreach ($uList as &$u) {
            unset($u['password'], $u['password_hash']);
        }

        echo json_encode([
            'success'       => true,
            'version'       => 'v2.3-users',
            'database'      => 'mysql',
            'tableUsed'     => $pTable,
            'rowsFound'     => $pCount,
            'usersTable'    => $uTable,
            'usersCount'    => $uCount,
            'schema'        => $schema,
            'usersList'     => $uList
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'rowsFound' => 0,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

if ($action === 'test-email') {
    requireAdminAuth();
    $targetEmail = trim($body['to'] ?? ($body['admin_email'] ?? ''));
    $testResult = testEmailConnection($pdo, $body, $targetEmail);
    if ($testResult['ok']) {
        echo json_encode([
            'ok'        => true,
            'message'   => '¡Correo de prueba enviado exitosamente a ' . ($testResult['recipient'] ?? $targetEmail) . '!',
            'transport' => $testResult['transport'] ?? 'smtp'
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'ok'    => false,
            'error' => $testResult['error'] ?? 'No se pudo enviar el correo de prueba. Revisa las credenciales SMTP.'
        ]);
    }
    exit;
}

// -------------------------------------------------------------
// 6. AUTENTICACIÓN DINÁMICA (/api/auth/login, /api/auth/register, /api/auth/me)
// -------------------------------------------------------------
