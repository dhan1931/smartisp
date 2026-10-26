<?php
// Parte de api/router.php (mismo patron que orders.php/customers.php): comparte el scope via
// require, no es una funcion. $pdo, $body, $action, $method y los helpers (requireAdminAuth,
// etc.) ya estan definidos cuando este archivo se incluye.
//
// Solicitudes de cotizacion del formulario de servicios.html / CTA de nosotros.html.

const QUOTE_STATUSES = ['nuevo', 'contactado', 'cerrado'];

if ($action === 'service-quote-requests' && $method === 'POST') {
    smartispEnforceRateLimit('quotes', 5, 600, 'Recibimos varias solicitudes desde esta conexión. Por favor intenta de nuevo en unos minutos.');
    $clientType = trim((string)($body['clientType'] ?? ''));
    $serviceInterest = trim((string)($body['service'] ?? ''));
    $description = trim((string)($body['description'] ?? ''));
    $companyName = trim((string)($body['companyName'] ?? ''));
    $email = trim((string)($body['email'] ?? ''));
    $phone = trim((string)($body['phone'] ?? ''));

    if ($clientType === '' || $serviceInterest === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Tipo de cliente y servicio de interes son obligatorios.']);
        exit;
    }

    $id = 'quote_' . bin2hex(random_bytes(12));
    $stmt = $pdo->prepare('INSERT INTO service_quote_requests
        (id, client_type, service_interest, description, company_name, email, phone)
        VALUES (:id, :client_type, :service_interest, :description, :company_name, :email, :phone)');
    try {
        $stmt->execute([
            ':id' => $id,
            ':client_type' => mb_substr($clientType, 0, 60),
            ':service_interest' => mb_substr($serviceInterest, 0, 120),
            ':description' => $description !== '' ? mb_substr($description, 0, 5000) : null,
            ':company_name' => $companyName !== '' ? mb_substr($companyName, 0, 191) : null,
            ':email' => $email !== '' ? mb_substr($email, 0, 191) : null,
            ':phone' => $phone !== '' ? mb_substr($phone, 0, 40) : null,
        ]);
    } catch (Throwable $e) {
        // Tabla sin migrar todavia (ver migrations/035_service_quote_requests.sql): no hacer
        // fallar la solicitud del cliente por eso, el formulario ya abre WhatsApp igual.
        error_log('service-quote-requests insert failed: ' . $e->getMessage());
        echo json_encode(['ok' => false]);
        exit;
    }
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

if ($action === 'admin-service-quotes' && $method === 'GET') {
    requireAdminAuth();

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $status = trim((string)($_GET['status'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));

    $where = [];
    $params = [];
    if ($status !== '' && $status !== 'all' && in_array($status, QUOTE_STATUSES, true)) {
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }
    if ($q !== '') {
        // Un marcador por columna: con ATTR_EMULATE_PREPARES=false no se puede repetir :q.
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $conditions = [];
        foreach (['company_name', 'email', 'phone', 'service_interest', 'description'] as $i => $column) {
            $conditions[] = "$column LIKE :q$i";
            $params[":q$i"] = $like;
        }
        $where[] = '(' . implode(' OR ', $conditions) . ')';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM service_quote_requests $whereSql");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM service_quote_requests $whereSql ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $statsStmt = $pdo->query('SELECT status, COUNT(*) AS total FROM service_quote_requests GROUP BY status');
        $byStatus = ['nuevo' => 0, 'contactado' => 0, 'cerrado' => 0];
        foreach ($statsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($byStatus[$row['status']])) $byStatus[$row['status']] = (int)$row['total'];
        }
    } catch (PDOException $e) {
        // Tabla sin migrar todavia -- no es un 500, es un estado valido de "todavia no hay nada".
        // Cualquier otro error sí lo es: antes se tragaba y el panel mostraba "sin solicitudes".
        if (($e->errorInfo[1] ?? null) !== 1146) throw $e;
        echo json_encode(['quotes' => [], 'total' => 0, 'page' => 1, 'limit' => $limit, 'totalPages' => 1, 'stats' => ['nuevo' => 0, 'contactado' => 0, 'cerrado' => 0], 'migrated' => false]);
        exit;
    }

    echo json_encode([
        'quotes' => $rows,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'totalPages' => (int)ceil($total / $limit),
        'stats' => $byStatus,
        'migrated' => true,
    ]);
    exit;
}

if ($action === 'admin-service-quote-status' && $method === 'POST') {
    requireAdminAuth();

    $id = trim((string)($body['id'] ?? ''));
    $status = trim((string)($body['status'] ?? ''));
    if ($id === '' || !in_array($status, QUOTE_STATUSES, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Solicitud invalida.']);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE service_quote_requests SET status = :status WHERE id = :id');
    $stmt->execute([':status' => $status, ':id' => $id]);
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'No se encontro la solicitud.']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}
