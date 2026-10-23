<?php
// Lógica de pedidos e inventario compartida entre handlers (pedidos, dashboard, estadísticas,
// reportes). Vive fuera de api/handlers/ porque esos archivos se incluyen en orden y cada uno
// sale con exit: una función definida en orders.php no existe todavía cuando corre products.php.

// Estados que no cuentan como venta en ningún reporte.
const SMARTISP_VOID_STATUSES = ['cancelled', 'canceled', 'refunded'];

// Pagos que sí cuentan como cobrados (mismo criterio que admin-orders-stats).
const SMARTISP_CONFIRMED_PAYMENTS = ['paid', 'confirmed', 'succeeded', 'completed', 'approved', 'captured'];

// Los artículos de un pedido viven en orders_rows.items como JSON, con formas distintas según la
// época del pedido: lista plana, {items: [...]}, {products: [...]} o un solo artículo suelto.
function smartispDecodeOrderItems($raw): array {
    $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
    if (!is_array($decoded)) return [];
    if (isset($decoded['items']) && is_array($decoded['items'])) {
        $items = $decoded['items'];
    } elseif (isset($decoded['products']) && is_array($decoded['products'])) {
        $items = $decoded['products'];
    } elseif (isset($decoded['name']) || isset($decoded['product_name']) || isset($decoded['productId']) || isset($decoded['product_id'])) {
        $items = [$decoded];
    } else {
        $items = $decoded;
    }

    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $name = trim((string)($item['name'] ?? $item['product_name'] ?? $item['title'] ?? ''));
        if ($name === '') continue;
        $normalized[] = [
            'product_id' => trim((string)($item['productId'] ?? $item['product_id'] ?? $item['id'] ?? '')),
            'sku' => trim((string)($item['sku'] ?? '')),
            'name' => $name,
            'quantity' => max(1, (int)($item['quantity'] ?? $item['qty'] ?? $item['cantidad'] ?? 1)),
            'price' => max(0, (float)($item['price'] ?? $item['unit_price'] ?? 0)),
            'stock_tracked' => !empty($item['stockTracked']),
        ];
    }
    return $normalized;
}

// Productos más vendidos entre dos fechas (Y-m-d H:i:s), excluyendo pedidos anulados. Junta los
// pedidos normalizados en order_items con los que solo tienen el JSON en orders_rows.items.
function smartispTopProducts(PDO $pdo, string $from, string $to, int $limit = 10): array {
    $voidList = "'" . implode("','", SMARTISP_VOID_STATUSES) . "'";
    $products = [];
    $add = static function (string $key, string $name, int $units, float $revenue, string $orderId) use (&$products): void {
        if (!isset($products[$key])) $products[$key] = ['key' => $key, 'name' => $name, 'units' => 0, 'revenue' => 0.0, 'order_ids' => []];
        $products[$key]['units'] += $units;
        $products[$key]['revenue'] += $revenue;
        $products[$key]['order_ids'][$orderId] = true;
    };

    $hasOrderItems = smartispTableExists($pdo, 'order_items');
    if ($hasOrderItems) {
        $stmt = $pdo->prepare("SELECT oi.order_id, COALESCE(NULLIF(oi.product_id, ''), NULLIF(oi.sku, ''), oi.product_name) AS product_key,
                oi.product_name, oi.quantity, oi.subtotal
            FROM order_items oi
            INNER JOIN orders_rows o ON o.id = oi.order_id
            WHERE o.created_at BETWEEN :from AND :to
              AND LOWER(COALESCE(o.status, '')) NOT IN ($voidList)");
        $stmt->execute([':from' => $from, ':to' => $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $add((string)$row['product_key'], (string)$row['product_name'], (int)$row['quantity'], (float)$row['subtotal'], (string)$row['order_id']);
        }
    }

    $legacySql = "SELECT o.id, o.items FROM orders_rows o
        WHERE o.created_at BETWEEN :from AND :to
          AND o.items IS NOT NULL AND TRIM(o.items) <> ''
          AND LOWER(COALESCE(o.status, '')) NOT IN ($voidList)";
    if ($hasOrderItems) {
        $legacySql .= ' AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id)';
    }
    $stmt = $pdo->prepare($legacySql);
    $stmt->execute([':from' => $from, ':to' => $to]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
        foreach (smartispDecodeOrderItems($order['items']) as $item) {
            $key = $item['product_id'] !== '' ? $item['product_id'] : ($item['sku'] !== '' ? $item['sku'] : strtolower($item['name']));
            $add($key, $item['name'], $item['quantity'], $item['quantity'] * $item['price'], (string)$order['id']);
        }
    }

    foreach ($products as &$product) {
        $product['orders'] = count($product['order_ids']);
        $product['revenue'] = round($product['revenue'], 2);
        unset($product['order_ids']);
    }
    unset($product);
    uasort($products, static fn(array $a, array $b): int => ($b['units'] <=> $a['units']) ?: ($b['orders'] <=> $a['orders']));
    return array_slice(array_values($products), 0, $limit);
}

// -------------------------------------------------------------
// STOCK DE PEDIDOS
// -------------------------------------------------------------
// Solo los productos con fila en product_inventory tienen stock controlado; el resto se vende
// sin límite, como siempre. Cada pedido recuerda en orders_rows.stock_state (migración 037) en
// qué situación dejó el stock de sus artículos controlados:
//   reserved  -> apartado (quantity_reserved += cantidad), el pedido sigue en curso
//   consumed  -> ya salió del almacén (quantity_available -= cantidad)
//   released  -> no ocupa stock (cotización, cancelado o reembolsado)
// Pasar de un estado a otro aplica solo la diferencia, así que cualquier cambio de estado del
// pedido (incluso volver atrás) deja el inventario coherente.

function smartispOrderStockTarget(string $status): string {
    $status = strtolower(trim($status));
    if ($status === 'quote_requested' || in_array($status, SMARTISP_VOID_STATUSES, true)) return 'released';
    if (in_array($status, ['shipped', 'delivered'], true)) return 'consumed';
    return 'reserved';
}

function smartispApplyOrderStock(PDO $pdo, array $items, string $from, string $to): void {
    if ($from === $to) return;
    // [disponible, reservado] que ocupa una unidad en cada situación.
    $weights = ['released' => [0, 0], 'reserved' => [0, 1], 'consumed' => [-1, 0]];
    $fromWeight = $weights[$from] ?? $weights['released'];
    $toWeight = $weights[$to] ?? $weights['released'];
    $stmt = $pdo->prepare('UPDATE product_inventory
        SET quantity_available = GREATEST(CAST(quantity_available AS SIGNED) + :da, 0),
            quantity_reserved = GREATEST(CAST(quantity_reserved AS SIGNED) + :dr, 0)
        WHERE product_id = :id');
    foreach (smartispDecodeOrderItems($items) as $item) {
        if (!$item['stock_tracked'] || $item['product_id'] === '') continue;
        $stmt->execute([
            ':da' => ($toWeight[0] - $fromWeight[0]) * $item['quantity'],
            ':dr' => ($toWeight[1] - $fromWeight[1]) * $item['quantity'],
            ':id' => $item['product_id'],
        ]);
    }
}

// El inventario por pedido solo se activa cuando la migración 037 agregó stock_state; sin ella
// todo sigue como antes (se valida disponibilidad pero no se aparta stock).
function smartispOrderStockEnabled(PDO $pdo): bool {
    return smartispTableExists($pdo, 'product_inventory')
        && in_array('stock_state', smartispTableColumns($pdo, 'orders_rows'), true);
}
