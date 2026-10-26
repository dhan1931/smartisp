<?php
// Consulta administrativa de clientes y compradores registrados en pedidos.
if ($action !== 'admin-customers') return;
requireAdminAuth();

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

$userTable = getUsersTableName($pdo);
$userColumns = array_column($pdo->query("SHOW COLUMNS FROM `$userTable`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
$orderColumns = smartispTableColumns($pdo, 'orders_rows');
$userColumn = static function (array $candidates) use ($userColumns): ?string {
    foreach ($candidates as $candidate) if (in_array($candidate, $userColumns, true)) return $candidate;
    return null;
};
$orderColumn = static function (array $candidates) use ($orderColumns): ?string {
    foreach ($candidates as $candidate) if (in_array($candidate, $orderColumns, true)) return $candidate;
    return null;
};
$uid = $userColumn(['id', 'user_id']);
$email = $userColumn(['email', 'correo', 'mail', 'user_email']);
$name = $userColumn(['name', 'nombre']);
$surname = $userColumn(['surname', 'apellido']);
$phone = $userColumn(['phone', 'telefono', 'mobile']);
$role = $userColumn(['role', 'rol']);
$created = $userColumn(['created_at', 'fecha_registro', 'created']);
$orderId = $orderColumn(['id']);
$orderUserId = $orderColumn(['user_id']);
$orderEmail = $orderColumn(['customer_email', 'email']);
$orderCreated = $orderColumn(['created_at']);

if (!$uid || !$email || !$orderId) {
    http_response_code(500);
    echo json_encode(['error' => 'El esquema de usuarios o pedidos no contiene los campos necesarios.']);
    exit;
}

$qName = static fn(?string $column, string $alias): string => $column ? "$alias.`$column`" : 'NULL';
$accountNameParts = array_values(array_filter([$name ? "NULLIF(TRIM(u.`$name`), '')" : null, $surname ? "NULLIF(TRIM(u.`$surname`), '')" : null]));
$accountName = $accountNameParts ? 'TRIM(CONCAT_WS(\' \', ' . implode(', ', $accountNameParts) . '))' : "''";
$accountRoleCondition = $role ? "LOWER(COALESCE(u.`$role`, 'customer')) NOT IN ('admin','administrator','administrador')" : '1=1';
$accountCreated = $created ? "u.`$created`" : 'NULL';
$accountPhone = $qName($phone, 'u');

// Pedidos agrupados una sola vez por user_id y por correo (ver smartispCustomerOrderAggregates):
// antes cada cuenta se cruzaba con todos los pedidos por "user_id = … OR correo = …", sin índice.
$aggregates = smartispCustomerOrderAggregates($pdo, $userTable, $uid);
$accountSql = static function (string $where = '', string $userWhere = '', string $emailWhere = '') use ($aggregates, $uid, $email, $userTable, $accountName, $accountPhone, $accountCreated, $accountRoleCondition): string {
    $orders = smartispCustomerAccountJoin($aggregates, $uid, $email, $userWhere, $emailWhere);
    return "SELECT CAST(u.`$uid` AS CHAR) AS customer_id, $accountName AS customer_name, u.`$email` AS customer_email, $accountPhone AS customer_phone, $accountCreated AS joined_at, 'account' AS source, {$orders['order_count']} AS order_count, {$orders['order_value']} AS order_value, {$orders['last_order']} AS last_order FROM `$userTable` u{$orders['joins']} WHERE $accountRoleCondition$where";
};
// Compradores sin cuenta: correos de pedidos que no pertenecen a ninguna cuenta.
$guestSql = $aggregates['by_email'] ? static fn(string $emailWhere = ''): string => "SELECT CONCAT('guest:', be.customer_key) AS customer_id, be.customer_name, be.customer_email, be.customer_phone, be.first_order AS joined_at, 'guest' AS source, be.order_count, be.order_value, be.last_order FROM (" . $aggregates['by_email']($emailWhere) . ") be WHERE NOT EXISTS (SELECT 1 FROM `$userTable` ua WHERE ua.`$email` = be.customer_key)" : null;

if (isset($_GET['id'])) {
    $id = (string)$_GET['id'];
    if (str_starts_with($id, 'guest:')) {
        $stmt = $guestSql ? $pdo->prepare($guestSql(" AND {$aggregates['email_key']} = :guest_key")) : null;
        $stmt?->execute([':guest_key' => substr($id, 6)]);
    } else {
        $userWhere = $aggregates['user_column'] ? " AND o.`{$aggregates['user_column']}` = :orders_uid" : '';
        $emailWhere = $aggregates['email_key'] ? " AND {$aggregates['email_key']} = (SELECT LOWER(TRIM(ue.`$email`)) FROM `$userTable` ue WHERE ue.`$uid` = :email_uid)" : '';
        $stmt = $pdo->prepare($accountSql(" AND u.`$uid` = :id", $userWhere, $emailWhere));
        $stmt->execute(array_filter([':id' => $id, ':orders_uid' => $userWhere ? $id : null, ':email_uid' => $emailWhere ? $id : null], static fn($v) => $v !== null));
    }
    $customer = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!$customer) {
        http_response_code(404);
        echo json_encode(['error' => 'Cliente no encontrado.']);
        exit;
    }
    $orderFilters = [];
    $orderParams = [];
    if ($orderUserId && $customer['source'] === 'account') {
        $orderFilters[] = "`$orderUserId` = :uid";
        $orderParams[':uid'] = substr((string)$customer['customer_id'], 0, 191);
    }
    if ($orderEmail) {
        $orderFilters[] = "LOWER(TRIM(`$orderEmail`)) = LOWER(TRIM(:email))";
        $orderParams[':email'] = (string)$customer['customer_email'];
    }
    $orders = [];
    if ($orderFilters) {
        $selectColumns = array_values(array_intersect(['id', 'created_at', 'customer_name', 'customer_email', 'total', 'status', 'payment_status'], $orderColumns));
        $ordersStmt = $pdo->prepare('SELECT `' . implode('`,`', $selectColumns) . '` FROM orders_rows WHERE (' . implode(' OR ', $orderFilters) . ') ORDER BY ' . ($orderCreated ? "`$orderCreated`" : "`$orderId`") . ' DESC LIMIT 20');
        $ordersStmt->execute($orderParams);
        $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['customer' => $customer, 'orders' => $orders]);
    exit;
}

$baseSql = '(' . implode(' UNION ALL ', array_filter([$accountSql(), $guestSql ? $guestSql() : null])) . ') customers';
$filters = [];
$params = [];
$query = trim((string)($_GET['q'] ?? ''));
$type = (string)($_GET['type'] ?? 'all');
if ($query !== '') {
    $filters[] = '(customers.customer_name LIKE :q_name OR customers.customer_email LIKE :q_email OR customers.customer_phone LIKE :q_phone)';
    $params[':q_name'] = $params[':q_email'] = $params[':q_phone'] = '%' . $query . '%';
}
if (in_array($type, ['account', 'guest'], true)) {
    $filters[] = 'customers.source = :source';
    $params[':source'] = $type;
}
$whereSql = $filters ? ' WHERE ' . implode(' AND ', $filters) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(10, (int)($_GET['limit'] ?? 25)));
$offset = ($page - 1) * $limit;

// Página, total y resumen en una sola pasada (funciones de ventana sobre el resultado filtrado).
$statsColumns = ['_total' => 'COUNT(*)', '_accounts' => "SUM(customers.source = 'account')", '_guests' => "SUM(customers.source = 'guest')", '_orders' => 'SUM(customers.order_count)', '_order_value' => 'SUM(customers.order_value)'];
$windowSql = implode(', ', array_map(static fn($alias, $expr) => "$expr OVER () AS $alias", array_keys($statsColumns), $statsColumns));
$stmt = $pdo->prepare("SELECT customers.*, $windowSql FROM $baseSql$whereSql ORDER BY COALESCE(customers.last_order, customers.joined_at) DESC, customers.customer_name ASC LIMIT :limit OFFSET :offset");
foreach ($params as $key => $value) $stmt->bindValue($key, $value);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($rows) {
    $stats = array_intersect_key($rows[0], $statsColumns);
} elseif ($page > 1) {
    // Página fuera de rango: sin filas no llegan los totales de la ventana.
    $statsStmt = $pdo->prepare('SELECT ' . implode(', ', array_map(static fn($alias, $expr) => "$expr AS $alias", array_keys($statsColumns), $statsColumns)) . " FROM $baseSql$whereSql");
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} else {
    $stats = [];
}
$customers = array_map(static fn($row) => array_diff_key($row, $statsColumns), $rows);
$total = (int)($stats['_total'] ?? 0);
echo json_encode([
    'customers' => $customers,
    'stats' => [
        'customers' => $total,
        'accounts' => (int)($stats['_accounts'] ?? 0),
        'guests' => (int)($stats['_guests'] ?? 0),
        'orders' => (int)($stats['_orders'] ?? 0),
        'order_value' => (float)($stats['_order_value'] ?? 0),
    ],
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'totalPages' => max(1, (int)ceil($total / $limit)),
]);
exit;
