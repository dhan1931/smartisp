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
