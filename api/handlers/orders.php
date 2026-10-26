<?php
// Parte de api/router.php, dividido por dominio (DEV-20261005-015): pedidos y lista de deseos.
// Comparte el scope de router.php via require (no es una funcion): $pdo, $body, $action,
// $method y los helpers (getAuthUser, isAdminUser, requireAdminAuth, etc.) ya estan definidos
// cuando este archivo se incluye. Cada bloque se autogestiona con 'if ($action === ...) { ... exit; }',
// igual que antes; solo cambio donde vive el codigo, no la logica.

// -------------------------------------------------------------
if ($action === 'orders' || $action === 'customer-orders') {
    if ($method === 'POST') {
        // Recortados al tamaño de su columna (orders_rows: customer_name/email 255, phone 50).
        $text = static fn($value, int $max): string => mb_substr(trim(is_scalar($value) ? (string)$value : ''), 0, $max);
        $shipping = is_array($body['shipping'] ?? null) ? $body['shipping'] : [];
        $customerName = $text($body['customerName'] ?? ($body['name'] ?? ($shipping['name'] ?? '')), 255);
        $customerEmail = $text($body['customerEmail'] ?? ($body['email'] ?? ($shipping['email'] ?? '')), 255);
        $customerPhone = $text($body['customerPhone'] ?? ($body['phone'] ?? ($shipping['phone'] ?? '')), 50);
        $customerAddress = $text($body['address'] ?? ($shipping['address'] ?? ''), 500);
        $customerCity = $text($body['city'] ?? ($shipping['city'] ?? ''), 120);
        $customerNotes = $text($body['notes'] ?? ($shipping['notes'] ?? ''), 2000);

        // Reintento del mismo intento de compra (doble clic, red que corta la respuesta): se
        // devuelve el pedido ya creado. Va antes del límite de pedidos para que el reintento no
        // termine en un 429. Sin la columna de la migración 039 se ignora la clave.
        $authUserId = isset(getAuthUser()['id']) ? (string)getAuthUser()['id'] : null;
        $idempotencyKey = in_array('idempotency_key', smartispTableColumns($pdo, 'orders_rows'), true) ? smartispIdempotencyKey($body) : null;
        if ($idempotencyKey !== null) {
            $previous = smartispFindIdempotentOrder($pdo, $idempotencyKey, $authUserId, $customerEmail);
            if ($previous && $previous['mine']) {
                echo json_encode(smartispIdempotentOrderPayload($previous['row']));
                exit;
            }
            if ($previous) $idempotencyKey = null;
        }

        smartispEnforceRateLimit('orders', 10, 600, 'Recibimos demasiados pedidos desde esta conexión. Por favor intenta de nuevo en unos minutos.');

        // El correo es a donde se envía el comprobante: sin uno válido el pedido queda sin
        // contacto y el mailer intentaría enviar a cualquier texto que llegue.
        if ($customerName === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Ingresa tu nombre y un correo electrónico válido.']);
            exit;
        }
        smartispEnforceRateLimit('orders_email', 5, 600, 'Recibimos varios pedidos con este correo hace poco. Por favor intenta de nuevo en unos minutos.', strtolower($customerEmail));

        $rawItems = is_array($body['items'] ?? null) ? array_values($body['items']) : [];
        if (empty($rawItems)) {
            http_response_code(400);
            echo json_encode(['error' => 'El carrito está vacío.']);
            exit;
        }
        if (count($rawItems) > 100) {
            http_response_code(400);
            echo json_encode(['error' => 'El carrito tiene demasiados productos distintos.']);
            exit;
        }

        // Del carrito solo se toman el id y la cantidad. Nombre, SKU, precio y si el stock se
        // controla salen de la base: antes se guardaba la línea tal como llegaba y un productId
        // o stockTracked inventados apartaban o descontaban stock de otro producto.
        $lines = [];
        $requestedByProduct = [];
        $totalUnits = 0;
        foreach ($rawItems as $it) {
            $qty = filter_var(is_array($it) ? ($it['quantity'] ?? 1) : null, FILTER_VALIDATE_INT);
            if ($qty === false || $qty < 1 || $qty > 99) {
                http_response_code(400);
                echo json_encode(['error' => 'La cantidad por producto debe estar entre 1 y 99 unidades.']);
                exit;
            }
            $productId = mb_substr(trim((string)(is_scalar($it['id'] ?? null) ? $it['id'] : '')), 0, 191);
            $lines[] = ['id' => $productId, 'quantity' => $qty];
            $requestedByProduct[$productId] = ($requestedByProduct[$productId] ?? 0) + $qty;
            $totalUnits += $qty;
        }
        if ($totalUnits > 500) {
            http_response_code(400);
            echo json_encode(['error' => 'El pedido supera el límite máximo permitido de 500 unidades en total.']);
            exit;
        }
        // Mismo orden de bloqueo en todos los pedidos: evita interbloqueos (error 1213).
        ksort($requestedByProduct, SORT_STRING);

        $pTable = getProductsTableName($pdo);
        $hasInventoryTable = smartispTableExists($pdo, 'product_inventory');
        $stockEnabled = smartispOrderStockEnabled($pdo);
        $productStmt = $pdo->prepare("SELECT * FROM `$pTable` WHERE id = :id LIMIT 1");
        // FOR UPDATE (con la transacción de abajo): dos compras simultáneas de la última unidad
        // no pueden leer el mismo stock libre y pasar las dos.
        $inventoryStmt = $hasInventoryTable
            ? $pdo->prepare('SELECT GREATEST(CAST(quantity_available AS SIGNED) - CAST(quantity_reserved AS SIGNED), 0) AS available_qty FROM product_inventory WHERE product_id = :id LIMIT 1' . ($stockEnabled ? ' FOR UPDATE' : ''))
            : null;

        $pdo->beginTransaction();
        $products = [];
        $tracked = [];
        $invalidItems = [];
        $stockProblems = [];
        foreach ($requestedByProduct as $productId => $requested) {
            $productRow = null;
            if ($productId !== '') {
                $productStmt->execute([':id' => $productId]);
                $productRow = $productStmt->fetch(PDO::FETCH_ASSOC);
            }
            $product = $productRow ? normalizeProductRow($productRow) : null;
            if (!$product || !$product['visible']) {
                $invalidItems[] = (string)$productId;
                continue;
            }
            $products[$productId] = $product;
            if ($inventoryStmt) {
                $inventoryStmt->execute([':id' => $productId]);
                $availableQty = $inventoryStmt->fetchColumn();
                if ($availableQty !== false) {
                    // El mismo producto puede venir en dos líneas del carrito: se compara la suma.
                    if ((int)$availableQty < $requested) {
                        $invalidItems[] = (string)$productId;
                        $stockProblems[] = ['id' => (string)$productId, 'name' => $product['name'], 'available' => (int)$availableQty];
                    }
                    $tracked[$productId] = $stockEnabled;
                }
            }
        }

        if ($invalidItems) {
            $pdo->rollBack();
            $message = count($stockProblems) === 1 && count($invalidItems) === 1
                ? 'No hay stock suficiente de "' . $stockProblems[0]['name'] . '" (disponible: ' . $stockProblems[0]['available'] . ').'
                : 'Uno o más productos de tu carrito ya no están disponibles o no tienen stock suficiente. Actualiza el carrito e intenta de nuevo.';
            http_response_code(400);
            echo json_encode([
                'error' => $message,
                'invalidItems' => $invalidItems,
                'stock' => array_map(static fn($p) => ['id' => $p['id'], 'available' => $p['available']], $stockProblems),
            ]);
            exit;
        }

        $validItems = [];
        $calculatedTotal = 0;
        $hasFreeItem = false;
        foreach ($lines as $line) {
            $product = $products[$line['id']];
            $price = max(0, (float)$product['price']);
            if ($price <= 0) $hasFreeItem = true;
            $calculatedTotal += $line['quantity'] * $price;
            $item = [
                'id'        => (string)$line['id'],
                'productId' => (string)$line['id'],
                'name'      => $product['name'],
                'sku'       => $product['sku'],
                'price'     => $price,
                'quantity'  => $line['quantity'],
            ];
            if (!empty($tracked[$line['id']])) $item['stockTracked'] = true;
            $validItems[] = $item;
        }

        // El total es siempre el calculado con los precios de la base, nunca el del body.
        $total = round($calculatedTotal, 2);

        // Un artículo sin precio necesita cotización: el pedido entero se trata como cotización,
        // si no se cobraba solo lo que tenía precio.
        $isQuote = $hasFreeItem || $total <= 0;
        $orderStatus = $isQuote ? 'quote_requested' : 'pending';

        $shippingData = [
            'name'    => $customerName,
            'email'   => $customerEmail,
            'phone'   => $customerPhone,
            'address' => $customerAddress,
            'city'    => $customerCity,
            'notes'   => $customerNotes
        ];

        // Obtener columnas reales de orders_rows para inserción 100% compatible
        $existingCols = smartispTableColumns($pdo, 'orders_rows');

        $insertData = [
            'id'     => '',
            'status' => $orderStatus,
            'total'  => $total
        ];

        if (in_array('user_id', $existingCols, true)) {
            // El historial del cliente se filtra solo por user_id: tomarlo de la sesión validada
            // contra la base, no de $_SESSION a secas (puede ser de un usuario ya borrado).
            $insertData['user_id'] = $authUserId;
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
        // Fechas en UTC, como el resto de la base (los reportes las pasan a hora de Ecuador).
        if (in_array('created_at', $existingCols, true)) {
            $insertData['created_at'] = gmdate('Y-m-d H:i:s');
        }
        if (in_array('updated_at', $existingCols, true)) {
            $insertData['updated_at'] = gmdate('Y-m-d H:i:s');
        }
        $stockState = $stockEnabled ? smartispOrderStockTarget($orderStatus) : null;
        if ($stockEnabled) {
            $insertData['stock_state'] = $stockState;
        }
        if ($idempotencyKey !== null) {
            $insertData['idempotency_key'] = $idempotencyKey;
        }

        $fields = array_keys($insertData);
        $placeholders = array_map(fn($f) => ':' . $f, $fields);
        $stmt = $pdo->prepare("INSERT INTO orders_rows (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")");

        // ID legible de pedido, ej: PED-A1B2C3. Son 6 hex (16M combinaciones): un choque con un
        // pedido existente es improbable pero posible, y antes terminaba en un 500 con el pedido
        // perdido. Ante clave duplicada se genera otro.
        for ($attempt = 1; ; $attempt++) {
            $orderId = 'PED-' . strtoupper(bin2hex(random_bytes(3)));
            $insertData['id'] = $orderId;
            try {
                $stmt->execute($insertData);
                break;
            } catch (PDOException $e) {
                // Dos envíos simultáneos con la misma clave: el segundo responde con el primero.
                if (($e->errorInfo[1] ?? null) === 1062 && $idempotencyKey !== null && str_contains($e->getMessage(), 'idempotency')) {
                    $pdo->rollBack();
                    $previous = smartispFindIdempotentOrder($pdo, $idempotencyKey, $authUserId, $customerEmail);
                    if (!$previous || !$previous['mine']) throw $e;
                    echo json_encode(smartispIdempotentOrderPayload($previous['row']));
                    exit;
                }
                if ($attempt >= 5 || ($e->errorInfo[1] ?? null) !== 1062) throw $e;
            }
        }
        if ($stockEnabled) {
            try {
                smartispApplyOrderStock($pdo, $validItems, 'released', $stockState);
            } catch (SmartispStockException $e) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage(), 'invalidItems' => []]);
                exit;
            }
        }
        $pdo->commit();

        smartispFinishResponse(json_encode(smartispOrderSuccessPayload($orderId, $orderStatus, $total, $validItems)));

        $categories = array_map(static fn($p) => $p['category'] ?? '', $products);
        try {
            sendOrderEmails($pdo, [
                'id'            => $orderId,
                'customerName'  => $customerName,
                'customerEmail' => $customerEmail,
                'customerPhone' => $customerPhone,
                'address'       => $customerAddress,
                'city'          => $customerCity,
                'notes'         => $customerNotes,
                'items'         => array_map(static fn($i) => $i + ['category' => $categories[$i['id']] ?? ''], $validItems),
                'total'         => $total,
                'isQuote'       => $isQuote
            ]);
        } catch (Throwable $e) {
            error_log('SmartISP correos del pedido ' . $orderId . ': ' . $e->getMessage());
        }
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
            $existingCols = smartispTableColumns($pdo, 'orders_rows');

            $userId = (string)($user['id'] ?? '');

            // El id (PED-XXXXXX) es aleatorio: ordenar por él mezclaba el historial.
            $orderBy = in_array('created_at', $existingCols, true) ? 'created_at DESC, id DESC' : 'id DESC';

            if (isAdminUser($user)) {
                $stmt = $pdo->query("SELECT * FROM orders_rows ORDER BY $orderBy LIMIT 100");
                $rows = $stmt ? $stmt->fetchAll() : [];
            } elseif (in_array('user_id', $existingCols, true) && $userId !== '') {
                // Solo por user_id. Antes también se cruzaba por customer_email, pero el registro
                // no verifica el correo: cualquiera que se registrara con el correo de otra persona
                // veía sus pedidos de invitado (dirección y teléfono incluidos).
                $stmt = $pdo->prepare("SELECT * FROM orders_rows WHERE user_id = :uid ORDER BY $orderBy LIMIT 50");
                $stmt->execute([':uid' => $userId]);
                $rows = $stmt->fetchAll();
            } else {
                $rows = [];
            }

            echo json_encode(['orders' => array_map('smartispDecodeOrderRow', $rows)]);
            exit;
        } catch (Throwable $e) {
            // No devolver 200 con lista vacia: para el cliente eso es indistinguible de "nunca
            // has comprado nada", cuando en realidad fallo la consulta (ej. timeout de la base).
            http_response_code(503);
            echo json_encode(['orders' => [], 'error' => 'No se pudo cargar tu historial de pedidos. Intenta de nuevo en un momento.']);
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
        $columns = smartispTableColumns($pdo, 'orders_rows');
        $searchColumns = array_values(array_intersect(['id', 'customer_name', 'customer_email', 'customer_phone', 'shipping'], $columns));
        if (!$searchColumns) $searchColumns = ['id'];
        // Un marcador por columna: con ATTR_EMULATE_PREPARES=false no se puede repetir :q.
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $conditions = [];
        foreach ($searchColumns as $i => $column) {
            $conditions[] = "`$column` LIKE :q$i";
            $params[":q$i"] = $like;
        }
        $where[] = '(' . implode(' OR ', $conditions) . ')';
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

    $eligibleSale = 'NOT ' . smartispSqlIsVoid() . ' AND ' . smartispSqlIsPaid();
    $byStatus = $pdo->query("SELECT status, COUNT(*) AS total,
        SUM(CASE WHEN $eligibleSale THEN total ELSE 0 END) AS revenue
        FROM orders_rows GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    // "Hoy" y los 7 días son días de Ecuador; created_at está en UTC.
    $today = smartispLocalToday();
    $totalsStmt = $pdo->prepare("SELECT COUNT(*) AS total,
        SUM(CASE WHEN $eligibleSale THEN total ELSE 0 END) AS revenue,
        SUM(CASE WHEN $eligibleSale THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN LOWER(status) IN ('delivered', 'completed') THEN 1 ELSE 0 END) AS completed_count,
        SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN created_at >= :today_start THEN 1 ELSE 0 END) AS today_count
        FROM orders_rows");
    $totalsStmt->execute([':today_start' => smartispLocalDayStartUtc($today)]);
    $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $localDay = smartispSqlLocalDay('created_at');
    $dailyStmt = $pdo->prepare("SELECT DATE_FORMAT($localDay, '%Y-%m-%d') AS day, COUNT(*) AS orders,
        SUM(CASE WHEN $eligibleSale THEN total ELSE 0 END) AS revenue
        FROM orders_rows WHERE created_at >= :week_start
        GROUP BY $localDay ORDER BY day ASC");
    $dailyStmt->execute([':week_start' => smartispLocalDayStartUtc(smartispLocalDayShift($today, -6))]);
    $daily = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'by_status'  => array_map(fn($r) => [
            'status'  => $r['status'],
            'total'   => (int)$r['total'],
            'revenue' => (float)($r['revenue'] ?? 0),
        ], $byStatus),
        'total'       => (int)($totals['total'] ?? 0),
        'revenue'     => (float)($totals['revenue'] ?? 0),
        'paid_count'  => (int)($totals['paid_count'] ?? 0),
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

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM orders_rows WHERE id = :id LIMIT 1 FOR UPDATE');
    $stmt->execute([':id' => $id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Pedido no encontrado.']);
        exit;
    }

    $fromStatus = $order['status'] ?? null;
    if (strtolower(trim((string)$fromStatus)) === strtolower($newStatus)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'El pedido ya está en ese estado. No se registró un cambio duplicado.']);
        exit;
    }

    $sets = ['status = :status', 'updated_at = NOW()'];
    $params = [':status' => $newStatus, ':id' => $id];

    // Un estado logístico (enviado, entregado...) no demuestra que se cobró. Pero "paid" es
    // justo lo que el panel muestra como "Pago confirmado": en pagos manuales (transferencia,
    // efectivo) es el admin quien confirma el cobro, así que ese estado sí lo registra.
    // Sin esto ningún pedido manual llegaba a contar como venta en el dashboard.
    $paymentProvider = strtolower(trim((string)($order['payment_provider'] ?? '')));
    $paymentStatus = strtolower(trim((string)($order['payment_status'] ?? '')));
    if ($newStatus === 'paid' && array_key_exists('payment_status', $order)
        && in_array($paymentProvider, ['', 'manual'], true)
        && !in_array($paymentStatus, SMARTISP_CONFIRMED_PAYMENTS, true)) {
        $sets[] = "payment_status = 'paid'";
        $order['payment_status'] = 'paid';
    }

    // Inventario: aplicar solo la diferencia entre lo que el pedido ocupaba y lo que ocupa ahora.
    if (smartispOrderStockEnabled($pdo)) {
        $fromStock = (string)($order['stock_state'] ?? '') ?: 'released';
        $toStock = smartispOrderStockTarget($newStatus);
        if ($fromStock !== $toStock) {
            try {
                smartispApplyOrderStock($pdo, smartispDecodeOrderRow($order)['items'] ?? [], $fromStock, $toStock);
            } catch (SmartispStockException $e) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['error' => $e->getMessage()]);
                exit;
            }
            $sets[] = 'stock_state = :stock_state';
            $params[':stock_state'] = $toStock;
        }
    }

    $upd = $pdo->prepare('UPDATE orders_rows SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $upd->execute($params);

    $actor = getAuthUser()['email'] ?? 'admin';
    $ev = $pdo->prepare('INSERT INTO order_events (order_id, from_status, to_status, actor, note) VALUES (:order_id, :from_status, :to_status, :actor, :note)');
    $ev->execute([
        ':order_id'    => $id,
        ':from_status' => $fromStatus,
        ':to_status'   => $newStatus,
        ':actor'       => $actor,
        ':note'        => $note !== '' ? $note : null,
    ]);
    $pdo->commit();

    $order['status'] = $newStatus;
    smartispFinishResponse(json_encode([
        'ok'          => true,
        'order'       => smartispDecodeOrderRow($order),
        'emailQueued' => true,
    ]));
    try {
        sendOrderStatusUpdateEmail($pdo, $order, $newStatus, $note);
    } catch (Throwable $e) {
        error_log('SmartISP correo de estado del pedido ' . $id . ': ' . $e->getMessage());
    }
    exit;
}

if ($action === 'admin-order-delete' && $method === 'POST') {
    requireAdminAuth();

    $id = trim((string)($body['id'] ?? ''));
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Falta el id del pedido.']);
        exit;
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT status, payment_status FROM orders_rows WHERE id = :id LIMIT 1 FOR UPDATE');
    $stmt->execute([':id' => $id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Pedido no encontrado.']);
        exit;
    }

    if (!in_array(strtolower(trim((string)$order['status'])), ['cancelled', 'canceled'], true)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'Solo se pueden eliminar pedidos cancelados.']);
        exit;
    }

    $paymentStatus = strtolower(trim((string)($order['payment_status'] ?? '')));
    if ($paymentStatus !== '' && !in_array($paymentStatus, ['pending', 'failed', 'cancelled', 'canceled', 'declined', 'expired'], true)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'No se puede eliminar: el pedido tiene un estado de pago que debe conservarse para auditoría.']);
        exit;
    }

    if (smartispTableExists($pdo, 'order_payments')) {
        $payments = $pdo->prepare('SELECT COUNT(*) FROM order_payments WHERE order_id = :id');
        $payments->execute([':id' => $id]);
        if ((int)$payments->fetchColumn() > 0) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'No se puede eliminar: existen registros de pago asociados.']);
            exit;
        }
    }

    $delete = $pdo->prepare('DELETE FROM orders_rows WHERE id = :id');
    $delete->execute([':id' => $id]);
    $pdo->commit();

    echo json_encode(['ok' => true, 'deleted_id' => $id]);
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
            // Vive en la sesión (un archivo por visitante): sin tope, crecía con lo que mandara el cliente.
            $_SESSION[$uKey] = array_slice($_SESSION[$uKey], 0, 100);
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
