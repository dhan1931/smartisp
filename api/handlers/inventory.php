<?php
// Parte de api/router.php: inventario del panel (pantalla Inventario).
// Comparte el scope de router.php via require: $pdo, $body, $action, $method y los helpers.
//
// Un producto tiene stock controlado solo si tiene fila en product_inventory. Sin fila se vende
// sin límite (como siempre). "Libre" = disponible - reservado por pedidos en curso.

if ($action !== 'admin-inventory') return;

requireAdminAuth();

if (!smartispTableExists($pdo, 'product_inventory')) {
    http_response_code(409);
    echo json_encode(['error' => 'Falta la tabla product_inventory: aplica las migraciones (php scripts/migrate.php).']);
    exit;
}

$pTable = getProductsTableName($pdo);
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

    if (array_key_exists('track', $body) && !$body['track']) {
        // Dejar de controlar: el producto vuelve a venderse sin límite.
        $pdo->prepare('DELETE FROM product_inventory WHERE product_id = :id')->execute([':id' => $productId]);
    } elseif (isset($body['delta'])) {
        // Entrada/salida de mercadería: suma atómica sobre lo que haya en la base.
        $delta = (int)$body['delta'];
        if ($delta === 0 || abs($delta) > 1000000) {
            http_response_code(400);
            echo json_encode(['error' => 'Indica una cantidad distinta de cero para ajustar.']);
            exit;
        }
        $pdo->prepare('INSERT INTO product_inventory (product_id, quantity_available) VALUES (:id, GREATEST(:delta, 0))
            ON DUPLICATE KEY UPDATE quantity_available = GREATEST(CAST(quantity_available AS SIGNED) + :delta2, 0)')
            ->execute([':id' => $productId, ':delta' => $delta, ':delta2' => $delta]);
    } else {
        $available = filter_var($body['available'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
        if ($available === false) {
            http_response_code(400);
            echo json_encode(['error' => 'El stock debe ser un número entero entre 0 y 1.000.000.']);
            exit;
        }
        $pdo->prepare('INSERT INTO product_inventory (product_id, quantity_available) VALUES (:id, :qty)
            ON DUPLICATE KEY UPDATE quantity_available = VALUES(quantity_available)')
            ->execute([':id' => $productId, ':qty' => $available]);
    }

    echo json_encode(['ok' => true, 'item' => $inventoryRow($productId)]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido.']);
exit;
