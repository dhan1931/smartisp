<?php
// Parte de api/router.php: estadísticas (pantalla Estadísticas) y exportaciones CSV (Reportes).
// Comparte el scope de router.php via require: $pdo, $body, $action, $method y los helpers.
//
// Criterios, iguales a los del dashboard (admin-orders-stats):
//   - Ventas cobradas: pedidos no anulados con pago confirmado (payment_status).
//   - Valor en curso: pedidos no anulados con total > 0, cobrados o no.
//   - Anulados: cancelled / canceled / refunded.

if ($action !== 'admin-analytics' && $action !== 'admin-export') return;

requireAdminAuth();

// Rango [desde, hasta] a partir de ?from=Y-m-d&to=Y-m-d o ?days=N (por defecto 30 días).
function smartispReportRange(): array {
    $isDate = static fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    if ($isDate($from) && $isDate($to) && $from <= $to) {
        return [$from . ' 00:00:00', $to . ' 23:59:59'];
    }
    $days = min(730, max(1, (int)($_GET['days'] ?? 30)));
    return [date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days')), date('Y-m-d 23:59:59')];
}

$voidList = "'" . implode("','", SMARTISP_VOID_STATUSES) . "'";
$paidList = "'" . implode("','", SMARTISP_CONFIRMED_PAYMENTS) . "'";
$orderCols = smartispTableColumns($pdo, 'orders_rows');
$hasPaymentStatus = in_array('payment_status', $orderCols, true);
$isVoid = "(LOWER(COALESCE(status, '')) IN ($voidList))";
$isPaid = $hasPaymentStatus ? "(NOT $isVoid AND LOWER(COALESCE(payment_status, '')) IN ($paidList))" : '0';

// -------------------------------------------------------------
// ESTADÍSTICAS (/api/auth/admin-analytics)
// -------------------------------------------------------------
if ($action === 'admin-analytics') {
    [$from, $to] = smartispReportRange();
    // Periodo anterior de igual largo, para mostrar la variación.
    $span = strtotime($to) - strtotime($from) + 1;
    $prevFrom = date('Y-m-d H:i:s', strtotime($from) - $span);
    $prevTo = date('Y-m-d H:i:s', strtotime($from) - 1);

    $totalsSql = "SELECT
            COUNT(*) AS orders,
            SUM(NOT $isVoid) AS active_orders,
            SUM($isVoid) AS void_orders,
            SUM(LOWER(COALESCE(status, '')) = 'quote_requested') AS quotes,
            SUM($isPaid) AS paid_orders,
            COALESCE(SUM(CASE WHEN $isPaid THEN total END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN NOT $isVoid THEN total END), 0) AS pipeline
        FROM orders_rows WHERE created_at BETWEEN :from AND :to";
    $totalsStmt = $pdo->prepare($totalsSql);
    $totals = static function (string $a, string $b) use ($totalsStmt): array {
        $totalsStmt->execute([':from' => $a, ':to' => $b]);
        $row = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach (['orders', 'active_orders', 'void_orders', 'quotes', 'paid_orders'] as $k) $out[$k] = (int)($row[$k] ?? 0);
        $out['revenue'] = round((float)($row['revenue'] ?? 0), 2);
        $out['pipeline'] = round((float)($row['pipeline'] ?? 0), 2);
        $out['avg_ticket'] = $out['paid_orders'] > 0 ? round($out['revenue'] / $out['paid_orders'], 2) : 0;
        return $out;
    };
    $current = $totals($from, $to);
    $previous = $totals($prevFrom, $prevTo);

    $newCustomers = 0;
    $prevCustomers = 0;
    $customerStmt = $pdo->prepare("SELECT COUNT(*) FROM users_rows WHERE created_at BETWEEN :from AND :to AND LOWER(COALESCE(role, 'customer')) NOT IN ('admin', 'administrator', 'administrador')");
    $customerStmt->execute([':from' => $from, ':to' => $to]);
    $newCustomers = (int)$customerStmt->fetchColumn();
    $customerStmt->execute([':from' => $prevFrom, ':to' => $prevTo]);
    $prevCustomers = (int)$customerStmt->fetchColumn();
    $current['new_customers'] = $newCustomers;
    $previous['new_customers'] = $prevCustomers;

    // Serie diaria; los días sin pedidos se rellenan con cero para que el gráfico no salte fechas.
    $dailyStmt = $pdo->prepare("SELECT DATE(created_at) AS day, SUM(NOT $isVoid) AS orders,
            COALESCE(SUM(CASE WHEN $isPaid THEN total END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN NOT $isVoid THEN total END), 0) AS pipeline
        FROM orders_rows WHERE created_at BETWEEN :from AND :to GROUP BY DATE(created_at)");
    $dailyStmt->execute([':from' => $from, ':to' => $to]);
    $byDay = [];
    foreach ($dailyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $byDay[$row['day']] = $row;
    $daily = [];
    for ($t = strtotime(substr($from, 0, 10)); $t <= strtotime(substr($to, 0, 10)); $t += 86400) {
        $day = date('Y-m-d', $t);
        $daily[] = [
            'day' => $day,
            'orders' => (int)($byDay[$day]['orders'] ?? 0),
            'revenue' => round((float)($byDay[$day]['revenue'] ?? 0), 2),
            'pipeline' => round((float)($byDay[$day]['pipeline'] ?? 0), 2),
        ];
    }

    $statusStmt = $pdo->prepare("SELECT COALESCE(NULLIF(status, ''), 'sin_estado') AS status, COUNT(*) AS orders, COALESCE(SUM(total), 0) AS value
        FROM orders_rows WHERE created_at BETWEEN :from AND :to GROUP BY COALESCE(NULLIF(status, ''), 'sin_estado') ORDER BY orders DESC");
    $statusStmt->execute([':from' => $from, ':to' => $to]);
    $byStatus = array_map(static fn($r) => ['status' => $r['status'], 'orders' => (int)$r['orders'], 'value' => round((float)$r['value'], 2)], $statusStmt->fetchAll(PDO::FETCH_ASSOC));

    // Categorías: se agrupan los más vendidos según la categoría actual del producto.
    $topProducts = smartispTopProducts($pdo, $from, $to, 200);
    $categories = [];
    $ids = array_values(array_filter(array_map(static fn($p) => (string)$p['key'], $topProducts)));
    if ($ids) {
        $pTable = getProductsTableName($pdo);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $catStmt = $pdo->prepare("SELECT id, category FROM `$pTable` WHERE id IN ($placeholders)");
        $catStmt->execute($ids);
        $categoryOf = $catStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($topProducts as $product) {
            $name = trim((string)($categoryOf[$product['key']] ?? '')) ?: 'Sin categoría';
            $categories[$name] ??= ['category' => $name, 'units' => 0, 'revenue' => 0.0];
            $categories[$name]['units'] += $product['units'];
            $categories[$name]['revenue'] = round($categories[$name]['revenue'] + $product['revenue'], 2);
        }
        usort($categories, static fn($a, $b) => $b['units'] <=> $a['units']);
    }

    echo json_encode([
        'from' => substr($from, 0, 10),
        'to' => substr($to, 0, 10),
        'totals' => $current,
        'previous' => $previous,
        'daily' => $daily,
        'by_status' => $byStatus,
        'top_products' => array_slice($topProducts, 0, 10),
        'categories' => array_slice(array_values($categories), 0, 8),
    ]);
    exit;
}

// -------------------------------------------------------------
// EXPORTACIONES CSV (/api/auth/admin-export?type=orders|products|customers|inventory)
// -------------------------------------------------------------
$type = (string)($_GET['type'] ?? '');
$exports = ['orders', 'products', 'customers', 'inventory'];
if (!in_array($type, $exports, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Tipo de reporte inválido. Opciones: ' . implode(', ', $exports)]);
    exit;
}

$pTable = getProductsTableName($pdo);
[$from, $to] = smartispReportRange();
$headers = [];
$rows = [];

if ($type === 'orders') {
    $stmt = $pdo->prepare('SELECT * FROM orders_rows WHERE created_at BETWEEN :from AND :to ORDER BY created_at ASC, id ASC');
    $stmt->execute([':from' => $from, ':to' => $to]);
    $headers = ['Pedido', 'Fecha', 'Estado', 'Pago', 'Cliente', 'Correo', 'Teléfono', 'Ciudad', 'Dirección', 'Artículos', 'Unidades', 'Total'];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $items = smartispDecodeOrderItems($o['items'] ?? '');
        $shipping = json_decode((string)($o['shipping'] ?? ''), true) ?: [];
        $rows[] = [
            $o['id'], $o['created_at'] ?? '', $o['status'] ?? '', $o['payment_status'] ?? '',
            $o['customer_name'] ?? ($shipping['name'] ?? ''), $o['customer_email'] ?? ($shipping['email'] ?? ''),
            $o['customer_phone'] ?? ($shipping['phone'] ?? ''), $shipping['city'] ?? '', $shipping['address'] ?? '',
            implode(' | ', array_map(static fn($i) => $i['quantity'] . ' x ' . $i['name'], $items)),
            array_sum(array_column($items, 'quantity')),
            number_format((float)($o['total'] ?? 0), 2, '.', ''),
        ];
    }
} elseif ($type === 'products') {
    $headers = ['ID', 'SKU', 'Nombre', 'Categoría', 'Subcategoría', 'Precio', 'Visible', 'Imagen'];
    foreach ($pdo->query("SELECT * FROM `$pTable` ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $p = normalizeProductRow($row);
        $rows[] = [$p['id'], $p['sku'], $p['name'], $p['category'], $p['subcategory'], number_format($p['price'], 2, '.', ''), $p['visible'] ? 'Sí' : 'No', $p['rawImageUrl']];
    }
} elseif ($type === 'customers') {
    $userCols = smartispTableColumns($pdo, 'users_rows');
    $joinOrders = in_array('user_id', $orderCols, true);
    $sql = 'SELECT u.id, u.name, u.surname, u.email, u.phone, u.created_at'
        . ($joinOrders ? ", COUNT(o.id) AS orders, COALESCE(SUM(CASE WHEN o.id IS NOT NULL AND LOWER(COALESCE(o.status, '')) NOT IN ($voidList) THEN o.total END), 0) AS value, MAX(o.created_at) AS last_order" : ", 0 AS orders, 0 AS value, NULL AS last_order")
        . ' FROM users_rows u' . ($joinOrders ? ' LEFT JOIN orders_rows o ON o.user_id = u.id' : '')
        . " WHERE LOWER(COALESCE(u.role, 'customer')) NOT IN ('admin', 'administrator', 'administrador')"
        . ' GROUP BY u.id ORDER BY u.created_at DESC';
    $headers = ['ID', 'Nombre', 'Apellido', 'Correo', 'Teléfono', 'Registrado', 'Pedidos', 'Valor no anulado', 'Último pedido'];
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $rows[] = [$u['id'], $u['name'] ?? '', $u['surname'] ?? '', $u['email'], $u['phone'] ?? '', $u['created_at'] ?? '', (int)$u['orders'], number_format((float)$u['value'], 2, '.', ''), $u['last_order'] ?? ''];
    }
} else {
    $headers = ['ID', 'SKU', 'Nombre', 'Categoría', 'Stock controlado', 'Disponible', 'Reservado', 'Libre'];
    $hasInventory = smartispTableExists($pdo, 'product_inventory');
    $sql = $hasInventory
        ? "SELECT p.id, p.sku, p.name, p.category, i.product_id AS tracked, i.quantity_available, i.quantity_reserved,
                GREATEST(CAST(i.quantity_available AS SIGNED) - CAST(i.quantity_reserved AS SIGNED), 0) AS free_qty
            FROM `$pTable` p LEFT JOIN product_inventory i ON i.product_id = p.id ORDER BY p.category, p.name"
        : "SELECT id, sku, name, category, NULL AS tracked, NULL AS quantity_available, NULL AS quantity_reserved, NULL AS free_qty FROM `$pTable` ORDER BY category, name";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tracked = !empty($r['tracked']);
        $rows[] = [$r['id'], $r['sku'] ?? '', $r['name'], $r['category'] ?? '', $tracked ? 'Sí' : 'No',
            $tracked ? (int)$r['quantity_available'] : '', $tracked ? (int)$r['quantity_reserved'] : '', $tracked ? (int)$r['free_qty'] : ''];
    }
}

// Excel abre bien el CSV si trae BOM UTF-8 y usa ; como separador (configuración regional es-EC).
$names = ['orders' => 'pedidos', 'products' => 'productos', 'customers' => 'clientes', 'inventory' => 'inventario'];
$filename = 'smartisp-' . $names[$type] . ($type === 'orders' ? '-' . substr($from, 0, 10) . '_a_' . substr($to, 0, 10) : '-' . date('Y-m-d')) . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
// Celdas que empiezan con = + - @ se tratan como fórmula en Excel: se neutralizan con un apóstrofo.
$safe = static fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
fputcsv($out, $headers, ';', '"', '\\');
foreach ($rows as $row) fputcsv($out, array_map($safe, $row), ';', '"', '\\');
fclose($out);
exit;
