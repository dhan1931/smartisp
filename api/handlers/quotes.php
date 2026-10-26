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

// -------------------------------------------------------------
// Seguimiento interno y conversión a pedido (migración 043).
// -------------------------------------------------------------

function smartispQuoteFollowupReady(PDO $pdo): bool {
    return smartispTableExists($pdo, 'service_quote_notes')
        && in_array('order_id', smartispTableColumns($pdo, 'service_quote_requests'), true);
}

function smartispQuoteRequireMigration(PDO $pdo): void {
    if (smartispQuoteFollowupReady($pdo)) return;
    http_response_code(503);
    echo json_encode(['error' => 'Falta aplicar la migración 043 (seguimiento de cotizaciones).']);
    exit;
}

function smartispQuoteAddNote(PDO $pdo, string $quoteId, ?string $actor, string $text): void {
    $pdo->prepare('INSERT INTO service_quote_notes (quote_id, actor, body, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$quoteId, $actor, mb_substr($text, 0, 2000), gmdate('Y-m-d H:i:s')]);
}

if ($action === 'admin-service-quote' && $method === 'GET') {
    requireAdminAuth();
    smartispQuoteRequireMigration($pdo);
    $id = trim((string)($_GET['id'] ?? ''));
    $stmt = $pdo->prepare('SELECT * FROM service_quote_requests WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quote) {
        http_response_code(404);
        echo json_encode(['error' => 'No se encontro la solicitud.']);
        exit;
    }
    $notes = $pdo->prepare('SELECT id, actor, body, created_at FROM service_quote_notes WHERE quote_id = :id ORDER BY created_at ASC, id ASC');
    $notes->execute([':id' => $id]);
    $order = null;
    if (!empty($quote['order_id'])) {
        $orderStmt = $pdo->prepare('SELECT id, status, total, created_at FROM orders_rows WHERE id = :id LIMIT 1');
        $orderStmt->execute([':id' => $quote['order_id']]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    echo json_encode(['quote' => $quote, 'notes' => $notes->fetchAll(PDO::FETCH_ASSOC), 'order' => $order]);
    exit;
}

if ($action === 'admin-service-quote-note' && $method === 'POST') {
    requireAdminAuth();
    smartispQuoteRequireMigration($pdo);
    $id = trim((string)($body['id'] ?? ''));
    $note = trim(is_scalar($body['note'] ?? null) ? (string)$body['note'] : '');
    if ($id === '' || $note === '' || mb_strlen($note) > 2000) {
        http_response_code(400);
        echo json_encode(['error' => 'Escribe una nota de hasta 2000 caracteres.']);
        exit;
    }
    $exists = $pdo->prepare('SELECT 1 FROM service_quote_requests WHERE id = :id LIMIT 1');
    $exists->execute([':id' => $id]);
    if (!$exists->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'No se encontro la solicitud.']);
        exit;
    }
    smartispQuoteAddNote($pdo, $id, getAuthUser()['email'] ?? 'admin', $note);
    echo json_encode(['ok' => true]);
    exit;
}

// next_action_at llega del formulario (datetime-local, hora de Ecuador) y se guarda en UTC.
if ($action === 'admin-service-quote-followup' && $method === 'POST') {
    requireAdminAuth();
    smartispQuoteRequireMigration($pdo);
    $id = trim((string)($body['id'] ?? ''));
    $nextAction = mb_substr(trim(is_scalar($body['next_action'] ?? null) ? (string)$body['next_action'] : ''), 0, 255);
    $rawDate = trim(is_scalar($body['next_action_at'] ?? null) ? (string)$body['next_action_at'] : '');
    $nextAt = null;
    if ($rawDate !== '') {
        $date = DateTime::createFromFormat('!Y-m-d\TH:i', $rawDate, new DateTimeZone(SMARTISP_REPORT_TIMEZONE));
        $errors = DateTime::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
            http_response_code(400);
            echo json_encode(['error' => 'Usa una fecha y hora válidas para la próxima acción.']);
            exit;
        }
        $nextAt = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Solicitud invalida.']);
        exit;
    }
    $exists = $pdo->prepare('SELECT 1 FROM service_quote_requests WHERE id = :id LIMIT 1');
    $exists->execute([':id' => $id]);
    if (!$exists->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'No se encontro la solicitud.']);
        exit;
    }
    $stmt = $pdo->prepare('UPDATE service_quote_requests SET next_action = :next_action, next_action_at = :next_at WHERE id = :id');
    $stmt->execute([':next_action' => $nextAction !== '' ? $nextAction : null, ':next_at' => $nextAt, ':id' => $id]);
    echo json_encode(['ok' => true, 'next_action' => $nextAction !== '' ? $nextAction : null, 'next_action_at' => $nextAt]);
    exit;
}

// Crea un pedido con una línea libre (descripción y precio del admin). La línea no tiene
// productId ni stockTracked, así que ningún cambio de estado del pedido toca inventario.
if ($action === 'admin-service-quote-convert' && $method === 'POST') {
    requireAdminAuth();
    smartispQuoteRequireMigration($pdo);
    $id = trim((string)($body['id'] ?? ''));
    $description = mb_substr(trim(is_scalar($body['description'] ?? null) ? (string)$body['description'] : ''), 0, 255);
    $price = filter_var($body['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $quantity = filter_var($body['quantity'] ?? 1, FILTER_VALIDATE_INT);
    $status = (string)($body['status'] ?? 'quote_requested');
    if ($id === '' || $description === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Escribe la descripción del servicio.']);
        exit;
    }
    if ($price === false || $price < 0 || $price > 1000000 || $quantity === false || $quantity < 1 || $quantity > 999) {
        http_response_code(400);
        echo json_encode(['error' => 'Revisa el precio (0 a 1.000.000) y la cantidad (1 a 999).']);
        exit;
    }
    if (!in_array($status, ['quote_requested', 'pending'], true) || ($status === 'pending' && $price <= 0)) {
        http_response_code(400);
        echo json_encode(['error' => 'Un pedido pendiente de pago necesita un precio mayor a 0; si aún no hay precio, créalo como cotización.']);
        exit;
    }
    $price = round($price, 2);
    $total = round($price * $quantity, 2);
    $actor = getAuthUser()['email'] ?? 'admin';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM service_quote_requests WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $quote = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$quote) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'No se encontro la solicitud.']);
            exit;
        }
        if (!empty($quote['order_id'])) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'Esta cotización ya se convirtió en el pedido ' . $quote['order_id'] . '.', 'orderId' => $quote['order_id']]);
            exit;
        }

        $email = (string)($quote['email'] ?? '');
        $phone = (string)($quote['phone'] ?? '');
        $name = (string)($quote['company_name'] ?: ($email ?: ($phone ?: 'Cliente de servicios')));
        $items = [[
            'id' => '', 'productId' => '', 'name' => $description, 'sku' => '',
            'price' => $price, 'quantity' => $quantity, 'custom' => true,
        ]];
        $columns = smartispTableColumns($pdo, 'orders_rows');
        $now = gmdate('Y-m-d H:i:s');
        $data = ['id' => '', 'status' => $status, 'total' => $total];
        $optional = [
            'user_id' => null,
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'shipping' => json_encode(['name' => $name, 'email' => $email, 'phone' => $phone, 'address' => '', 'city' => '', 'notes' => 'Servicio: ' . ($quote['service_interest'] ?? '')], JSON_UNESCAPED_UNICODE),
            'customer_name' => mb_substr($name, 0, 255),
            'customer_email' => mb_substr($email, 0, 255),
            'customer_phone' => mb_substr($phone, 0, 50),
            'payment_status' => $status === 'quote_requested' ? 'quote_pending' : 'pending',
            'payment_provider' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
            'stock_state' => smartispOrderStockTarget($status),
        ];
        foreach ($optional as $column => $value) if (in_array($column, $columns, true)) $data[$column] = $value;
        $insert = $pdo->prepare('INSERT INTO orders_rows (' . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', array_map(static fn($c) => ':' . $c, array_keys($data))) . ')');
        for ($attempt = 1; ; $attempt++) {
            $orderId = 'PED-' . strtoupper(bin2hex(random_bytes(3)));
            $data['id'] = $orderId;
            try {
                $insert->execute($data);
                break;
            } catch (PDOException $e) {
                if ($attempt >= 5 || ($e->errorInfo[1] ?? null) !== 1062) throw $e;
            }
        }
        $pdo->prepare('INSERT INTO order_events (order_id, from_status, to_status, actor, note, created_at) VALUES (?, NULL, ?, ?, ?, ?)')
            ->execute([$orderId, $status, $actor, 'Creado desde una cotización de servicio', $now]);
        smartispLogOrderActivity($pdo, $orderId, 'system', $actor, 'Pedido creado desde la cotización de servicio "' . ($quote['service_interest'] ?? '') . '" (' . $id . ').');
        $pdo->prepare("UPDATE service_quote_requests SET order_id = :order_id, status = CASE WHEN status = 'nuevo' THEN 'contactado' ELSE status END WHERE id = :id")
            ->execute([':order_id' => $orderId, ':id' => $id]);
        smartispQuoteAddNote($pdo, $id, $actor, 'Convertida en el pedido ' . $orderId . '.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    echo json_encode(['ok' => true, 'orderId' => $orderId, 'status' => $status, 'total' => $total]);
    exit;
}
