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
$orderColumns = array_column($pdo->query('SHOW COLUMNS FROM orders_rows')->fetchAll(PDO::FETCH_ASSOC), 'Field');
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
$orderName = $orderColumn(['customer_name', 'name']);
$orderPhone = $orderColumn(['customer_phone', 'phone']);
$orderTotal = $orderColumn(['total', 'amount']);
$orderStatus = $orderColumn(['status']);
$orderCreated = $orderColumn(['created_at']);

if (!$uid || !$email || !$orderId) {
    http_response_code(500);
    echo json_encode(['error' => 'El esquema de usuarios o pedidos no contiene los campos necesarios.']);
    exit;
}

$qName = static fn(?string $column, string $alias): string => $column ? "$alias.`$column`" : 'NULL';
$accountNameParts = array_values(array_filter([$name ? "NULLIF(TRIM(u.`$name`), '')" : null, $surname ? "NULLIF(TRIM(u.`$surname`), '')" : null]));
$accountName = $accountNameParts ? 'TRIM(CONCAT_WS(\' \', ' . implode(', ', $accountNameParts) . '))' : "''";
$guestEmail = $orderEmail ? "LOWER(TRIM(NULLIF(o.`$orderEmail`, '')))" : "''";
$guestName = $orderName ? "MAX(NULLIF(TRIM(o.`$orderName`), ''))" : "''";
$guestPhone = $orderPhone ? "MAX(NULLIF(TRIM(o.`$orderPhone`), ''))" : 'NULL';
$accountRoleCondition = $role ? "LOWER(COALESCE(u.`$role`, 'customer')) NOT IN ('admin','administrator','administrador')" : '1=1';
$joinOrders = [];
if ($orderUserId) $joinOrders[] = "o.`$orderUserId` = u.`$uid`";
if ($orderEmail) $joinOrders[] = "LOWER(TRIM(o.`$orderEmail`)) = LOWER(TRIM(u.`$email`))";
$orderJoin = $joinOrders ? '(' . implode(' OR ', $joinOrders) . ')' : '1=0';
$accountCount = "COUNT(DISTINCT o.`$orderId`)";
$accountSpend = $orderTotal ? "COALESCE(SUM(CASE WHEN o.`$orderId` IS NULL THEN 0" . ($orderStatus ? " WHEN LOWER(COALESCE(o.`$orderStatus`,'')) IN ('cancelled','canceled','refunded') THEN 0" : '') . " ELSE o.`$orderTotal` END),0)" : '0';
$accountLastOrder = $orderCreated ? "MAX(o.`$orderCreated`)" : 'NULL';
$accountCreated = $created ? "u.`$created`" : 'NULL';
$accountPhone = $qName($phone, 'u');

$parts = [];
$parts[] = "SELECT CAST(u.`$uid` AS CHAR) AS customer_id, $accountName AS customer_name, u.`$email` AS customer_email, $accountPhone AS customer_phone, $accountCreated AS joined_at, 'account' AS source, $accountCount AS order_count, $accountSpend AS order_value, $accountLastOrder AS last_order FROM `$userTable` u LEFT JOIN orders_rows o ON $orderJoin WHERE $accountRoleCondition GROUP BY u.`$uid`, u.`$email`";

if ($orderEmail) {
    $matchedAccount = "EXISTS (SELECT 1 FROM `$userTable` u WHERE LOWER(TRIM(u.`$email`)) = $guestEmail)";
    $guestCount = "COUNT(DISTINCT o.`$orderId`)";
    $guestSpend = $orderTotal ? "COALESCE(SUM(CASE WHEN " . ($orderStatus ? "LOWER(COALESCE(o.`$orderStatus`,'')) IN ('cancelled','canceled','refunded')" : '1=0') . " THEN 0 ELSE o.`$orderTotal` END),0)" : '0';
    $guestLast = $orderCreated ? "MAX(o.`$orderCreated`)" : 'NULL';
    $guestJoined = $orderCreated ? "MIN(o.`$orderCreated`)" : 'NULL';
    $parts[] = "SELECT CONCAT('guest:', $guestEmail) AS customer_id, $guestName AS customer_name, MAX(o.`$orderEmail`) AS customer_email, $guestPhone AS customer_phone, $guestJoined AS joined_at, 'guest' AS source, $guestCount AS order_count, $guestSpend AS order_value, $guestLast AS last_order FROM orders_rows o WHERE $guestEmail <> '' AND NOT $matchedAccount GROUP BY $guestEmail";
}

$baseSql = '(' . implode(' UNION ALL ', $parts) . ') customers';
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

if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM $baseSql WHERE customers.customer_id = :id LIMIT 1");
    $stmt->execute([':id' => (string)$_GET['id']]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
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

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM $baseSql$whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$statsStmt = $pdo->prepare("SELECT COUNT(*) AS customers, SUM(source='account') AS accounts, SUM(source='guest') AS guests, SUM(order_count) AS orders, SUM(order_value) AS order_value FROM $baseSql$whereSql");
$statsStmt->execute($params);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$stmt = $pdo->prepare("SELECT * FROM $baseSql$whereSql ORDER BY COALESCE(last_order, joined_at) DESC, customer_name ASC LIMIT :limit OFFSET :offset");
foreach ($params as $key => $value) $stmt->bindValue($key, $value);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
echo json_encode([
    'customers' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    'stats' => [
        'customers' => (int)($stats['customers'] ?? 0),
        'accounts' => (int)($stats['accounts'] ?? 0),
        'guests' => (int)($stats['guests'] ?? 0),
        'orders' => (int)($stats['orders'] ?? 0),
        'order_value' => (float)($stats['order_value'] ?? 0),
    ],
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'totalPages' => max(1, (int)ceil($total / $limit)),
]);
exit;
