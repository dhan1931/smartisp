<?php
// Parte de api/router.php, dividido por dominio (DEV-20261005-015): pedidos y lista de deseos.
// Comparte el scope de router.php via require (no es una funcion): $pdo, $body, $action,
// $method y los helpers (getAuthUser, isAdminUser, requireAdminAuth, etc.) ya estan definidos
// cuando este archivo se incluye. Cada bloque se autogestiona con 'if ($action === ...) { ... exit; }',
// igual que antes; solo cambio donde vive el codigo, no la logica.

// -------------------------------------------------------------
if ($action === 'orders' || $action === 'customer-orders') {
    if ($method === 'POST') {
        $shipping = is_array($body['shipping'] ?? null) ? $body['shipping'] : [];
        $customerName = trim($body['customerName'] ?? ($body['name'] ?? ($shipping['name'] ?? '')));
        $customerEmail = trim($body['customerEmail'] ?? ($body['email'] ?? ($shipping['email'] ?? '')));
        $customerPhone = trim($body['customerPhone'] ?? ($body['phone'] ?? ($shipping['phone'] ?? '')));
        $customerAddress = trim($body['address'] ?? ($shipping['address'] ?? ''));
        $customerCity = trim($body['city'] ?? ($shipping['city'] ?? ''));
        $customerNotes = trim($body['notes'] ?? ($shipping['notes'] ?? ''));

        $rawItems = is_array($body['items'] ?? null) ? $body['items'] : [];
        if (empty($rawItems)) {
            http_response_code(400);
            echo json_encode(['error' => 'El carrito está vacío.']);
            exit;
        }

        $calculatedTotal = 0;
        $totalUnits = 0;
        $hasPrice = false;
        $validItems = [];

        foreach ($rawItems as $it) {
            $qty = (int)($it['quantity'] ?? 1);
            if ($qty < 1 || $qty > 99) {
                http_response_code(400);
                echo json_encode(['error' => 'La cantidad por producto debe estar entre 1 y 99 unidades.']);
                exit;
            }
            $totalUnits += $qty;
            $prc = max(0, (float)($it['price'] ?? 0));
            if ($prc > 0) {
                $hasPrice = true;
            }
            $calculatedTotal += ($qty * $prc);
            $it['quantity'] = $qty;
            $it['price'] = $prc;
            $validItems[] = $it;
        }

        if ($totalUnits > 500) {
            http_response_code(400);
            echo json_encode(['error' => 'El pedido supera el límite máximo permitido de 500 unidades en total.']);
            exit;
        }

        $total = (float)($body['total'] ?? 0);
        if ($total <= 0 && $calculatedTotal > 0) {
            $total = $calculatedTotal;
        }

        $isQuote = !$hasPrice || $total <= 0;
        $orderStatus = $isQuote ? 'quote_requested' : 'pending';

        // Generar un ID legible de pedido, ej: PED-A1B2C3
        $cleanShort = strtoupper(substr(md5(uniqid((string)microtime(true), true)), 0, 6));
        $orderId = 'PED-' . $cleanShort;

        $shippingData = [
            'name'    => $customerName,
            'email'   => $customerEmail,
            'phone'   => $customerPhone,
            'address' => $customerAddress,
            'city'    => $customerCity,
            'notes'   => $customerNotes
        ];

        // Obtener columnas reales de orders_rows para inserción 100% compatible
        $colsStmt = $pdo->query("SHOW COLUMNS FROM orders_rows");
        $existingCols = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_COLUMN) : [];

        $insertData = [
            'id'     => $orderId,
            'status' => $orderStatus,
            'total'  => $total
        ];

        if (in_array('user_id', $existingCols, true)) {
            $insertData['user_id'] = $_SESSION['user']['id'] ?? null;
        }
        if (in_array('items', $existingCols, true)) {
            $insertData['items'] = json_encode($validItems, JSON_UNESCAPED_UNICODE);
        }
        if (in_array('shipping', $existingCols, true)) {
            $insertData['shipping'] = json_encode($shippingData, JSON_UNESCAPED_UNICODE);
        }
        if (in_array('customer_name', $existingCols, true)) {
            $insertData['customer_name'] = $customerName;
        }
        if (in_array('customer_email', $existingCols, true)) {
            $insertData['customer_email'] = $customerEmail;
        }
        if (in_array('customer_phone', $existingCols, true)) {
            $insertData['customer_phone'] = $customerPhone;
        }
        if (in_array('payment_status', $existingCols, true)) {
            $insertData['payment_status'] = $isQuote ? 'quote_pending' : 'pending';
        }
        if (in_array('payment_provider', $existingCols, true)) {
            $insertData['payment_provider'] = 'manual';
        }
        if (in_array('created_at', $existingCols, true)) {
            $insertData['created_at'] = date('Y-m-d H:i:s');
        }
        if (in_array('updated_at', $existingCols, true)) {
            $insertData['updated_at'] = date('Y-m-d H:i:s');
        }

        $fields = array_keys($insertData);
        $placeholders = array_map(fn($f) => ':' . $f, $fields);
        $sql = "INSERT INTO orders_rows (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($insertData);

        // Despachar correos automáticos (Comprobante al cliente y Alerta con WhatsApp al admin)
        $orderDataForMail = [
            'id'            => $orderId,
            'customerName'  => $customerName,
            'customerEmail' => $customerEmail,
            'customerPhone' => $customerPhone,
            'address'       => $customerAddress,
            'city'          => $customerCity,
            'notes'         => $customerNotes,
            'items'         => $validItems,
            'total'         => $total,
            'isQuote'       => $isQuote
        ];

        $emailResults = sendOrderEmails($pdo, $orderDataForMail);

        echo json_encode([
            'ok'           => true,
            'orderId'      => $orderId,
            'id'           => $orderId,
            'shortId'      => $cleanShort,
            'isQuote'      => $isQuote,
            'emailResults' => $emailResults
        ]);
        exit;
    }

    if ($method === 'GET') {
        $user = getAuthUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['orders' => [], 'error' => 'No autorizado']);
            exit;
        }

        try {
            $colsStmt = $pdo->query("SHOW COLUMNS FROM orders_rows");
            $existingCols = array_map(fn($c) => $c['Field'], $colsStmt ? $colsStmt->fetchAll() : []);

            $userId = $user['id'] ?? '';
            $userEmail = $user['email'] ?? '';

            if (isAdminUser($user)) {
                $stmt = $pdo->query("SELECT * FROM orders_rows ORDER BY id DESC LIMIT 100");
                $rows = $stmt ? $stmt->fetchAll() : [];
            } else {
                $where = [];
                $params = [];
                if (in_array('user_id', $existingCols, true) && !empty($userId)) {
                    $where[] = "user_id = :uid";
                    $params[':uid'] = $userId;
                }
                if (in_array('customer_email', $existingCols, true) && !empty($userEmail)) {
                    $where[] = "customer_email = :email";
                    $params[':email'] = $userEmail;
                }

                if (!empty($where)) {
                    $stmt = $pdo->prepare("SELECT * FROM orders_rows WHERE (" . implode(' OR ', $where) . ") ORDER BY id DESC LIMIT 50");
                    $stmt->execute($params);
                    $rows = $stmt ? $stmt->fetchAll() : [];
                } else {
                    $rows = [];
                }
            }

            $orders = array_map(function($r) {
                if (isset($r['items']) && is_string($r['items'])) {
                    $r['items'] = json_decode($r['items'], true) ?: [];
                }
                if (isset($r['shipping']) && is_string($r['shipping'])) {
                    $r['shipping'] = json_decode($r['shipping'], true) ?: [];
                }
                return $r;
            }, $rows);

            echo json_encode(['orders' => $orders]);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['orders' => []]);
            exit;
        }
    }
}

// -------------------------------------------------------------
// CENTRO DE PEDIDOS - ADMIN (DEV-20261005-019)
// /api/auth/admin-orders, /api/auth/admin-orders-stats, /api/auth/admin-order,
// /api/auth/admin-order-status
// -------------------------------------------------------------

// 'received' es un estado legado de datos reales anteriores a este flujo; se acepta para
// no romper el filtro sobre pedidos viejos, pero no es un destino nuevo que se promueva en la UI.
const ADMIN_ORDER_STATUSES = ['quote_requested', 'pending', 'paid', 'preparing', 'shipped', 'delivered', 'cancelled', 'received'];

function smartispDecodeOrderRow(array $r): array {
    if (isset($r['items']) && is_string($r['items'])) {
        $r['items'] = json_decode($r['items'], true) ?: [];
    }
    if (isset($r['shipping']) && is_string($r['shipping'])) {
        $r['shipping'] = json_decode($r['shipping'], true) ?: [];
    }
    return $r;
}

if ($action === 'admin-orders') {
    requireAdminAuth();

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $status = trim((string)($_GET['status'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));

    $where = [];
    $params = [];
    if ($status !== '' && $status !== 'all' && in_array($status, ADMIN_ORDER_STATUSES, true)) {
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }
    if ($q !== '') {
        $columns = $pdo->query('SHOW COLUMNS FROM orders_rows')->fetchAll(PDO::FETCH_COLUMN);
        $searchColumns = array_values(array_intersect(['id', 'customer_name', 'customer_email', 'customer_phone', 'shipping'], $columns));
        if (!$searchColumns) $searchColumns = ['id'];
        $where[] = '(' . implode(' OR ', array_map(static fn($column) => "`$column` LIKE :q", $searchColumns)) . ')';
        $params[':q'] = '%' . $q . '%';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders_rows $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM orders_rows $whereSql ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'orders'     => array_map('smartispDecodeOrderRow', $rows),
        'total'      => $total,
        'page'       => $page,
        'limit'      => $limit,
        'totalPages' => (int)ceil($total / $limit),
    ]);
    exit;
}

if ($action === 'admin-orders-stats') {
    requireAdminAuth();

    $confirmedPayment = "LOWER(COALESCE(payment_status, '')) IN ('paid', 'confirmed', 'succeeded', 'completed', 'approved', 'captured')";
    $eligibleSale = "LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'canceled', 'refunded') AND ($confirmedPayment)";
    $byStatus = $pdo->query("SELECT status, COUNT(*) AS total,
        SUM(CASE WHEN $eligibleSale THEN total ELSE 0 END) AS revenue
        FROM orders_rows GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    $totals = $pdo->query("SELECT COUNT(*) AS total,
        SUM(CASE WHEN $eligibleSale THEN total ELSE 0 END) AS revenue,
        SUM(CASE WHEN LOWER(status) IN ('delivered', 'completed') THEN 1 ELSE 0 END) AS completed_count,
        SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_count
        FROM orders_rows")->fetch(PDO::FETCH_ASSOC) ?: [];
    $daily = $pdo->query("SELECT DATE_FORMAT(created_at, '%Y-%m-%d') AS day, COUNT(*) AS orders,
        SUM(CASE WHEN $eligibleSale THEN total ELSE 0 END) AS revenue
        FROM orders_rows WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(created_at) ORDER BY day ASC")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'by_status'  => array_map(fn($r) => [
            'status'  => $r['status'],
            'total'   => (int)$r['total'],
            'revenue' => (float)($r['revenue'] ?? 0),
        ], $byStatus),
        'total'       => (int)($totals['total'] ?? 0),
        'revenue'     => (float)($totals['revenue'] ?? 0),
        'completed_count' => (int)($totals['completed_count'] ?? 0),
        'pending_count' => (int)($totals['pending_count'] ?? 0),
        'today_count' => (int)($totals['today_count'] ?? 0),
        'daily' => array_map(static fn($row) => [
            'day' => $row['day'],
            'orders' => (int)$row['orders'],
            'revenue' => (float)($row['revenue'] ?? 0),
        ], $daily),
    ]);
    exit;
}

if ($action === 'admin-order') {
    requireAdminAuth();
    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Falta el id del pedido.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM orders_rows WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Pedido no encontrado.']);
        exit;
    }

    $evStmt = $pdo->prepare('SELECT * FROM order_events WHERE order_id = :id ORDER BY created_at ASC, id ASC');
    $evStmt->execute([':id' => $id]);

    echo json_encode([
        'order'  => smartispDecodeOrderRow($order),
        'events' => $evStmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
    exit;
}

if ($action === 'admin-order-status' && $method === 'POST') {
    requireAdminAuth();

    $id = trim((string)($body['id'] ?? ''));
    $newStatus = trim((string)($body['status'] ?? ''));
    $note = trim((string)($body['note'] ?? ''));

    if ($id === '' || !in_array($newStatus, ADMIN_ORDER_STATUSES, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Pedido o estado inválido. Estados permitidos: ' . implode(', ', ADMIN_ORDER_STATUSES)]);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM orders_rows WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Pedido no encontrado.']);
        exit;
    }

    $fromStatus = $order['status'] ?? null;

    // El estado logístico no demuestra que el proveedor haya confirmado el pago.
    $upd = $pdo->prepare('UPDATE orders_rows SET status = :status, updated_at = NOW() WHERE id = :id');
    $upd->execute([':status' => $newStatus, ':id' => $id]);

    $actor = getAuthUser()['email'] ?? 'admin';
    $ev = $pdo->prepare('INSERT INTO order_events (order_id, from_status, to_status, actor, note) VALUES (:order_id, :from_status, :to_status, :actor, :note)');
    $ev->execute([
        ':order_id'    => $id,
        ':from_status' => $fromStatus,
        ':to_status'   => $newStatus,
        ':actor'       => $actor,
        ':note'        => $note !== '' ? $note : null,
    ]);

    $order['status'] = $newStatus;
    $emailResult = sendOrderStatusUpdateEmail($pdo, $order, $newStatus, $note);

    echo json_encode([
        'ok'    => true,
        'order' => smartispDecodeOrderRow($order),
        'email' => $emailResult,
    ]);
    exit;
}

// -------------------------------------------------------------
// ACTUALIZAR PERFIL (/api/auth/update-profile) (QA-019)
// -------------------------------------------------------------

if ($action === 'customer-wishlist') {
    $user = getAuthUser();
    $uKey = $user ? 'wishlist_' . $user['id'] : 'guest_wishlist';
    if (!isset($_SESSION[$uKey]) || !is_array($_SESSION[$uKey])) {
        $_SESSION[$uKey] = [];
    }

    if ($method === 'GET') {
        echo json_encode(['wishlist' => array_values($_SESSION[$uKey])]);
        exit;
    }

    if ($method === 'POST') {
        $item = $body['item'] ?? $body;
        $prodId = (string)($item['productId'] ?? ($item['id'] ?? ''));

        // Idempotencia: si ya existe el producto, no duplicarlo
        $_SESSION[$uKey] = array_values(array_filter($_SESSION[$uKey], function($x) use ($prodId) {
            $xId = (string)($x['productId'] ?? ($x['id'] ?? ''));
            return !empty($xId) && $xId !== $prodId;
        }));

        if (!empty($prodId)) {
            array_unshift($_SESSION[$uKey], $item);
        }

        echo json_encode(['ok' => true, 'wishlist' => array_values($_SESSION[$uKey])]);
        exit;
    }

    if ($method === 'DELETE') {
        $delId = (string)($_GET['productId'] ?? ($body['productId'] ?? ($body['id'] ?? '')));
        if (!empty($delId)) {
            $_SESSION[$uKey] = array_values(array_filter($_SESSION[$uKey], function($x) use ($delId) {
                $xId = (string)($x['productId'] ?? ($x['id'] ?? ''));
                return $xId !== $delId;
            }));
        } else {
            $_SESSION[$uKey] = [];
        }
        echo json_encode(['ok' => true, 'wishlist' => array_values($_SESSION[$uKey])]);
        exit;
    }
}

// -------------------------------------------------------------
// BÚSQUEDA Y PROXY DE IMÁGENES (/api/auth/search-product-image, /api/auth/proxy-image)
// -------------------------------------------------------------
