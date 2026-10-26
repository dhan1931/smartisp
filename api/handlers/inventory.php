<?php
// Parte de api/router.php: inventario del panel (pantalla Inventario).
// Comparte el scope de router.php via require: $pdo, $body, $action, $method y los helpers.
//
// Un producto tiene stock controlado solo si tiene fila en product_inventory. Sin fila se vende
// sin límite (como siempre). "Libre" = disponible - reservado por pedidos en curso.

if ($action !== 'admin-inventory' && $action !== 'admin-inventory-movements') return;

requireAdminAuth();

if (!smartispTableExists($pdo, 'product_inventory')) {
    http_response_code(409);
    echo json_encode(['error' => 'Falta la tabla product_inventory: aplica las migraciones (php scripts/migrate.php).']);
    exit;
}

$pTable = getProductsTableName($pdo);

// Historial de un producto (GET /api/auth/admin-inventory-movements?product_id=&page=), más reciente primero.
if ($action === 'admin-inventory-movements') {
    $productId = trim((string)($_GET['product_id'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    if (!smartispTableExists($pdo, 'inventory_movements')) {
        echo json_encode(['enabled' => false, 'items' => [], 'total' => 0, 'page' => 1, 'totalPages' => 1]);
        exit;
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM inventory_movements WHERE product_id = :id');
    $count->execute([':id' => $productId]);
    $total = (int)$count->fetchColumn();
    $stmt = $pdo->prepare('SELECT id, delta_available, delta_reserved, reason, order_id, user_id, note, created_at
        FROM inventory_movements WHERE product_id = :id ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':id', $productId);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Correo de quien hizo el ajuste; aparte del SELECT porque users_rows puede tener otra collation.
    $userIds = array_values(array_unique(array_filter(array_column($rows, 'user_id'))));
    $emails = [];
    if ($userIds) {
        $uStmt = $pdo->prepare('SELECT id, email FROM `' . getUsersTableName($pdo) . '` WHERE id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')');
        $uStmt->execute($userIds);
        $emails = $uStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $items = array_map(static fn($r) => [
        'id' => (int)$r['id'], 'deltaAvailable' => (int)$r['delta_available'], 'deltaReserved' => (int)$r['delta_reserved'],
        'reason' => (string)$r['reason'], 'orderId' => $r['order_id'], 'user' => $r['user_id'] ? ($emails[$r['user_id']] ?? $r['user_id']) : null,
        'note' => $r['note'], 'createdAt' => $r['created_at'],
    ], $rows);
    echo json_encode(['enabled' => true, 'items' => $items, 'total' => $total, 'page' => $page, 'totalPages' => max(1, (int)ceil($total / $limit))]);
    exit;
}

$freeSql = 'GREATEST(CAST(i.quantity_available AS SIGNED) - CAST(i.quantity_reserved AS SIGNED), 0)';

$inventoryRow = static function (string $productId) use ($pdo, $pTable, $freeSql): ?array {
    $stmt = $pdo->prepare("SELECT p.*, i.product_id AS inv_product_id, i.quantity_available, i.quantity_reserved, $freeSql AS free_qty, i.updated_at AS stock_updated_at
        FROM `$pTable` p LEFT JOIN product_inventory i ON i.product_id = p.id WHERE p.id = :id LIMIT 1");
    $stmt->execute([':id' => $productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? smartispInventoryItem($row) : null;
};

function smartispInventoryItem(array $row): array {
    $product = normalizeProductRow($row);
    $tracked = !empty($row['inv_product_id']);
    return [
        'id' => $product['id'],
        'name' => $product['name'],
        'sku' => $product['sku'],
        'category' => $product['category'],
        'price' => $product['price'],
        'visible' => $product['visible'],
        'imageUrl' => $product['imageUrl'],
        'tracked' => $tracked,
        'available' => $tracked ? (int)$row['quantity_available'] : null,
        'reserved' => $tracked ? (int)$row['quantity_reserved'] : null,
        'free' => $tracked ? (int)$row['free_qty'] : null,
        'updatedAt' => $row['stock_updated_at'] ?? null,
    ];
}

if ($method === 'GET') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;
    $lowThreshold = min(1000, max(1, (int)($_GET['low'] ?? 5)));
    $filter = (string)($_GET['filter'] ?? 'all');
    $q = trim((string)($_GET['q'] ?? ''));

    $where = ['1=1'];
    $params = [];
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $where[] = '(p.name LIKE :q_name OR p.sku LIKE :q_sku OR p.id = :q_id)';
        $params += [':q_name' => $like, ':q_sku' => $like, ':q_id' => $q];
    }
    $filters = [
        'tracked' => 'i.product_id IS NOT NULL',
        'untracked' => 'i.product_id IS NULL',
        'low' => "i.product_id IS NOT NULL AND $freeSql > 0 AND $freeSql <= :low",
        'out' => "i.product_id IS NOT NULL AND $freeSql = 0",
    ];
    if (isset($filters[$filter])) {
        $where[] = $filters[$filter];
        if ($filter === 'low') $params[':low'] = $lowThreshold;
    }
    $whereSql = implode(' AND ', $where);
    $from = "FROM `$pTable` p LEFT JOIN product_inventory i ON i.product_id = p.id";

    $countStmt = $pdo->prepare("SELECT COUNT(*) $from WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Los que piden atención primero: agotados, luego los de menos stock libre, luego sin control.
    $order = in_array($filter, ['low', 'out'], true)
        ? "$freeSql ASC, p.name ASC"
        : "(i.product_id IS NULL) ASC, $freeSql ASC, p.name ASC";
    $stmt = $pdo->prepare("SELECT p.*, i.product_id AS inv_product_id, i.quantity_available, i.quantity_reserved, $freeSql AS free_qty, i.updated_at AS stock_updated_at
        $from WHERE $whereSql ORDER BY $order LIMIT :limit OFFSET :offset");
    foreach ($params as $key => $value) $stmt->bindValue($key, $value);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = array_map('smartispInventoryItem', $stmt->fetchAll(PDO::FETCH_ASSOC));

    $statsStmt = $pdo->prepare("SELECT
            COUNT(*) AS products,
            SUM(i.product_id IS NOT NULL) AS tracked,
            SUM(i.product_id IS NOT NULL AND $freeSql = 0) AS out_of_stock,
            SUM(i.product_id IS NOT NULL AND $freeSql > 0 AND $freeSql <= :low) AS low_stock,
            COALESCE(SUM(CASE WHEN i.product_id IS NOT NULL THEN $freeSql END), 0) AS free_units,
            COALESCE(SUM(i.quantity_reserved), 0) AS reserved_units
        $from");
    $statsStmt->execute([':low' => $lowThreshold]);
    $stats = array_map('intval', $statsStmt->fetch(PDO::FETCH_ASSOC) ?: []);
    $hasCost = in_array('cost', smartispTableColumns($pdo, $pTable), true);
    $values = $pdo->query('SELECT COALESCE(SUM(i.quantity_available * COALESCE(p.price, 0)), 0) AS stock_value, '
        . ($hasCost ? 'SUM(i.quantity_available * p.cost) AS stock_cost_value, SUM(p.cost IS NOT NULL) AS with_cost' : 'NULL AS stock_cost_value, 0 AS with_cost')
        . " FROM product_inventory i INNER JOIN `$pTable` p ON p.id = i.product_id")->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats['stock_value'] = round((float)($values['stock_value'] ?? 0), 2);
    $stats['stock_cost_value'] = $values['stock_cost_value'] !== null ? round((float)$values['stock_cost_value'], 2) : null;
    $stats['tracked_with_cost'] = (int)($values['with_cost'] ?? 0);

    echo json_encode([
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'totalPages' => max(1, (int)ceil($total / $limit)),
        'lowThreshold' => $lowThreshold,
        'stats' => $stats,
        'stockByOrders' => smartispOrderStockEnabled($pdo),
    ]);
    exit;
}

if ($method === 'POST') {
    $productId = trim((string)($body['product_id'] ?? ''));
    $exists = $pdo->prepare("SELECT 1 FROM `$pTable` WHERE id = :id LIMIT 1");
    $exists->execute([':id' => $productId]);
    if ($productId === '' || !$exists->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado.']);
        exit;
    }
    $note = is_scalar($body['note'] ?? null) ? (string)$body['note'] : null;
    $fail = static function (int $status, string $message) use ($pdo): void {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code($status);
        echo json_encode(['error' => $message]);
        exit;
    };

    // Cada ajuste bloquea la fila, la cambia y deja su movimiento en la misma transacción.
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT CAST(quantity_available AS SIGNED) AS available, CAST(quantity_reserved AS SIGNED) AS reserved FROM product_inventory WHERE product_id = :id FOR UPDATE');
        $lock->execute([':id' => $productId]);
        $current = $lock->fetch(PDO::FETCH_ASSOC) ?: null;
        $before = $current ? [(int)$current['available'], (int)$current['reserved']] : [0, 0];

        if (array_key_exists('track', $body) && !$body['track']) {
            // Dejar de controlar: el producto vuelve a venderse sin límite. Con unidades apartadas
            // por pedidos en curso no se permite: al cerrarlos no habría dónde liberarlas o descontarlas.
            if ($before[1] > 0) {
                $fail(409, 'No se puede dejar de controlar el stock: hay ' . $before[1] . ' unidad(es) reservada(s) por pedidos en curso. Cancela o entrega esos pedidos primero.');
            }
            $pdo->prepare('DELETE FROM product_inventory WHERE product_id = :id AND quantity_reserved = 0')->execute([':id' => $productId]);
            if ($current) smartispRecordStockMovement($pdo, $productId, $before, [0, 0], 'untracked', null, $note);
        } else {
            if (isset($body['delta'])) {
                // Entrada/salida de mercadería sobre lo que haya en la base.
                $delta = (int)$body['delta'];
                if ($delta === 0 || abs($delta) > 1000000) $fail(400, 'Indica una cantidad distinta de cero para ajustar.');
                $available = max(0, $before[0] + $delta);
            } else {
                $available = filter_var($body['available'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
                if ($available === false) $fail(400, 'El stock debe ser un número entero entre 0 y 1.000.000.');
            }
            $pdo->prepare('INSERT INTO product_inventory (product_id, quantity_available) VALUES (:id, :qty)
                ON DUPLICATE KEY UPDATE quantity_available = VALUES(quantity_available)')
                ->execute([':id' => $productId, ':qty' => $available]);
            smartispRecordStockMovement($pdo, $productId, $before, [$available, $before[1]], 'manual', null, $note);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    echo json_encode(['ok' => true, 'item' => $inventoryRow($productId)]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido.']);
exit;
