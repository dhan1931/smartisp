<?php
// Parte de api/router.php: analítica agregada del panel (dashboard, Estadísticas, avisos de la
// barra lateral y búsqueda global). Comparte el scope de router.php: $pdo, $body, $action, $method.
//
// Criterios (los mismos de admin-analytics):
//   - Pedido: no anulado y no es una solicitud de cotización (quote_requested).
//   - Venta confirmada: no anulado con payment_status confirmado (SMARTISP_CONFIRMED_PAYMENTS).
//   - Pendiente de cobro: pedido sin pago confirmado.
//   - Días y horas de Ecuador (UTC-5 fijo); created_at está en UTC.

if (!in_array($action, ['admin-insights', 'admin-insights-alerts', 'admin-insights-search', 'admin-insights-goal'], true)) return;

requireAdminAuth();

const SMARTISP_INSIGHTS_LOW_STOCK = 5;
const SMARTISP_INSIGHTS_GOAL_KEY = 'admin_monthly_sales_goal';

$pTable = getProductsTableName($pdo);
$uTable = getUsersTableName($pdo);
$orderCols = smartispTableColumns($pdo, 'orders_rows');
$hasCol = static fn(string $c): bool => in_array($c, $orderCols, true);
$hasInventory = smartispTableExists($pdo, 'product_inventory');
$hasEvents = smartispTableExists($pdo, 'order_events');
$hasServiceQuotes = smartispTableExists($pdo, 'service_quote_requests');
$hasImages = smartispTableExists($pdo, 'product_images');
$hasOrderItems = smartispTableExists($pdo, 'order_items');

$voidList = "'" . implode("','", SMARTISP_VOID_STATUSES) . "'";
$paidList = "'" . implode("','", SMARTISP_CONFIRMED_PAYMENTS) . "'";
$isVoid = "(LOWER(COALESCE(o.status, '')) IN ($voidList))";
$isQuote = "(LOWER(COALESCE(o.status, '')) = 'quote_requested')";
$isOrder = "(NOT $isVoid AND NOT $isQuote)";
$isPaid = $hasCol('payment_status') ? "(NOT $isVoid AND LOWER(COALESCE(o.payment_status, '')) IN ($paidList))" : '(0)';
$offsetHours = intdiv(smartispReportTimezone()->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC'))), 3600);
$local = static fn(string $col): string => "DATE_ADD($col, INTERVAL $offsetHours HOUR)";
$customerKey = 'COALESCE(' . ($hasCol('user_id') ? "NULLIF(o.user_id, ''), " : '') . ($hasCol('customer_email') ? "NULLIF(LOWER(TRIM(o.customer_email)), ''), " : '') . 'o.id)';
$customerName = $hasCol('customer_name') ? "MAX(NULLIF(TRIM(o.customer_name), ''))" : 'NULL';
$customerEmail = $hasCol('customer_email') ? "MAX(NULLIF(TRIM(o.customer_email), ''))" : 'NULL';
$notAdmin = "LOWER(COALESCE(u.role, 'customer')) NOT IN ('admin', 'administrator', 'administrador')";

// Una sección que falla (esquema viejo, JSON inválido...) no tumba el resto del resumen.
$section = static function (string $name, callable $fn, $fallback = null) {
    try { return $fn(); } catch (Throwable $e) { error_log("admin-insights [$name]: " . $e->getMessage()); return $fallback; }
};

function smartispInsightsAlerts(PDO $pdo, array $ctx): array {
    $staleUtc = gmdate('Y-m-d H:i:s', time() - $ctx['stale_days'] * 86400);
    $stmt = $pdo->prepare("SELECT SUM(status = 'pending') AS pending, SUM(status = 'pending' AND created_at < :stale) AS pending_stale,
            SUM(status = 'quote_requested') AS quote_requested
        FROM orders_rows WHERE status IN ('pending', 'quote_requested')");
    $stmt->execute([':stale' => $staleUtc]);
    $orders = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $serviceNew = 0;
    if ($ctx['has_service_quotes']) {
        $serviceNew = (int)$pdo->query("SELECT COUNT(*) FROM service_quote_requests WHERE status = 'nuevo'")->fetchColumn();
    }

    $stock = ['out_of_stock' => 0, 'low_stock' => 0];
    if ($ctx['has_inventory']) {
        $free = 'CAST(i.quantity_available AS SIGNED) - CAST(i.quantity_reserved AS SIGNED)';
        $row = $pdo->query("SELECT SUM($free <= 0) AS out_of_stock, SUM($free > 0 AND $free <= " . SMARTISP_INSIGHTS_LOW_STOCK . ") AS low_stock
            FROM product_inventory i INNER JOIN `{$ctx['products']}` p ON p.id = i.product_id WHERE p.visible = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        $stock = ['out_of_stock' => (int)($row['out_of_stock'] ?? 0), 'low_stock' => (int)($row['low_stock'] ?? 0)];
    }

    $noPhoto = "(p.image_url IS NULL OR TRIM(p.image_url) = '')" . ($ctx['has_images'] ? ' AND NOT EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id = p.id)' : '');
    $catalog = $pdo->query("SELECT SUM($noPhoto) AS no_photo, SUM(p.price <= 0) AS no_price
        FROM `{$ctx['products']}` p WHERE p.visible = 1")->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'stale_days' => $ctx['stale_days'],
        'orders_pending' => (int)($orders['pending'] ?? 0),
        'orders_pending_stale' => (int)($orders['pending_stale'] ?? 0),
        'orders_quote_requested' => (int)($orders['quote_requested'] ?? 0),
        'service_quotes_new' => $serviceNew,
        'out_of_stock' => $stock['out_of_stock'],
        'low_stock' => $stock['low_stock'],
        'products_no_photo' => (int)($catalog['no_photo'] ?? 0),
        'products_no_price' => (int)($catalog['no_price'] ?? 0),
    ];
}

$alertCtx = [
    'stale_days' => min(30, max(1, (int)($_GET['stale_days'] ?? 2))),
    'has_service_quotes' => $hasServiceQuotes,
    'has_inventory' => $hasInventory,
    'has_images' => $hasImages,
    'products' => $pTable,
];

// -------------------------------------------------------------
// META MENSUAL (POST /api/auth/admin-insights-goal {goal})
// -------------------------------------------------------------
if ($action === 'admin-insights-goal') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido.']);
        exit;
    }
    $goal = $body['goal'] ?? null;
    if (!is_numeric($goal) || (float)$goal < 0 || (float)$goal > 100000000) {
        http_response_code(400);
        echo json_encode(['error' => 'La meta debe ser un número entre 0 y 100.000.000.']);
        exit;
    }
    $goal = round((float)$goal, 2);
    $stmt = $pdo->prepare('INSERT INTO settings_rows (setting_key, setting_value) VALUES (:key, :value)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP');
    $stmt->execute([':key' => SMARTISP_INSIGHTS_GOAL_KEY, ':value' => (string)$goal]);
    echo json_encode(['ok' => true, 'goal' => $goal]);
    exit;
}

// -------------------------------------------------------------
// AVISOS DE LA BARRA LATERAL (GET /api/auth/admin-insights-alerts)
// -------------------------------------------------------------
if ($action === 'admin-insights-alerts') {
    echo json_encode(smartispInsightsAlerts($pdo, $alertCtx));
    exit;
}

// -------------------------------------------------------------
// BÚSQUEDA GLOBAL (GET /api/auth/admin-insights-search?q=)
// -------------------------------------------------------------
if ($action === 'admin-insights-search') {
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        echo json_encode(['q' => $q, 'orders' => [], 'products' => [], 'customers' => []]);
        exit;
    }
    $q = mb_substr($q, 0, 80);
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

    $orders = $section('search-orders', static function () use ($pdo, $orderCols, $like) {
        $searchable = array_values(array_intersect(['id', 'customer_name', 'customer_email', 'customer_phone'], $orderCols));
        $select = array_values(array_intersect(['id', 'customer_name', 'customer_email', 'status', 'payment_status', 'total', 'created_at'], $orderCols));
        $where = [];
        $params = [];
        foreach ($searchable as $i => $col) { $where[] = "`$col` LIKE :q$i"; $params[":q$i"] = $like; }
        $stmt = $pdo->prepare('SELECT `' . implode('`, `', $select) . '` FROM orders_rows WHERE ' . implode(' OR ', $where) . ' ORDER BY created_at DESC LIMIT 6');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }, []);

    $products = $section('search-products', static function () use ($pdo, $pTable, $like, $q) {
        $stmt = $pdo->prepare("SELECT id, name, sku, price, visible, category FROM `$pTable`
            WHERE name LIKE :a OR sku LIKE :b OR id = :c ORDER BY visible DESC, name ASC LIMIT 6");
        $stmt->execute([':a' => $like, ':b' => $like, ':c' => $q]);
        return array_map(static fn($r) => ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'sku' => (string)($r['sku'] ?? ''),
            'price' => round((float)$r['price'], 2), 'visible' => (bool)$r['visible'], 'category' => (string)($r['category'] ?? '')], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }, []);

    $customers = $section('search-customers', static function () use ($pdo, $uTable, $like, $notAdmin) {
        $stmt = $pdo->prepare("SELECT u.id, TRIM(CONCAT_WS(' ', u.name, u.surname)) AS name, u.email FROM `$uTable` u
            WHERE $notAdmin AND (u.email LIKE :a OR u.name LIKE :b OR u.surname LIKE :c) ORDER BY u.created_at DESC LIMIT 5");
        $stmt->execute([':a' => $like, ':b' => $like, ':c' => $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }, []);

    echo json_encode(['q' => $q, 'orders' => $orders, 'products' => $products, 'customers' => $customers]);
    exit;
}

// -------------------------------------------------------------
// RESUMEN (GET /api/auth/admin-insights?period=today|7d|30d|90d|month|custom&from=&to=&scope=full)
// -------------------------------------------------------------
$today = smartispLocalToday();
$period = (string)($_GET['period'] ?? '30d');
$isDate = static fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
$spans = ['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90];
if ($period === 'month') {
    $fromDay = substr($today, 0, 8) . '01';
    $toDay = $today;
} elseif ($period === 'custom' && $isDate($_GET['from'] ?? null) && $isDate($_GET['to'] ?? null) && $_GET['from'] <= $_GET['to']) {
    $toDay = (string)$_GET['to'];
    $fromDay = max((string)$_GET['from'], smartispLocalDayShift($toDay, -729));
} else {
    if (!isset($spans[$period])) $period = '30d';
    $toDay = $today;
    $fromDay = smartispLocalDayShift($today, -($spans[$period] - 1));
}
$spanDays = (int)(new DateTimeImmutable($fromDay))->diff(new DateTimeImmutable($toDay))->days + 1;
if ($period === 'month') {
    // Mes en curso contra el mismo tramo de días del mes anterior.
    $prevFromDay = (new DateTimeImmutable($fromDay))->modify('first day of previous month')->format('Y-m-d');
    $prevMonthEnd = (new DateTimeImmutable($fromDay))->modify('-1 day')->format('Y-m-d');
    $prevToDay = min(smartispLocalDayShift($prevFromDay, $spanDays - 1), $prevMonthEnd);
} else {
    $prevToDay = smartispLocalDayShift($fromDay, -1);
    $prevFromDay = smartispLocalDayShift($prevToDay, -($spanDays - 1));
}
$from = smartispLocalDayStartUtc($fromDay);
$to = smartispLocalDayEndUtc($toDay);
$prevFrom = smartispLocalDayStartUtc($prevFromDay);
$prevTo = smartispLocalDayEndUtc($prevToDay);
$hourly = $spanDays === 1;
$fullScope = ($_GET['scope'] ?? '') === 'full';

$totalsStmt = $pdo->prepare("SELECT COUNT(*) AS all_orders, SUM($isOrder) AS orders, SUM($isVoid) AS void_orders, SUM($isQuote) AS quotes,
        SUM($isPaid) AS paid_orders,
        COALESCE(SUM(CASE WHEN $isPaid THEN o.total END), 0) AS revenue,
        COALESCE(SUM(CASE WHEN $isOrder AND NOT $isPaid THEN o.total END), 0) AS pending_value,
        COALESCE(SUM(CASE WHEN NOT $isVoid THEN o.total END), 0) AS pipeline
    FROM orders_rows o WHERE o.created_at BETWEEN :from AND :to");
$newCustomersStmt = $pdo->prepare("SELECT COUNT(*) FROM `$uTable` u WHERE u.created_at BETWEEN :from AND :to AND $notAdmin");
$quoteFlowSql = "SELECT COUNT(*) AS total, SUM(NOT $isQuote AND NOT $isVoid) AS converted, SUM($isVoid) AS lost
    FROM orders_rows o WHERE o.created_at BETWEEN :from AND :to AND ($isQuote"
    . ($hasEvents ? " OR EXISTS (SELECT 1 FROM order_events e WHERE e.order_id = o.id AND e.from_status = 'quote_requested')" : '') . ')';
$quoteFlowStmt = $pdo->prepare($quoteFlowSql);
$serviceQuotesStmt = $hasServiceQuotes ? $pdo->prepare('SELECT status, COUNT(*) AS total FROM service_quote_requests WHERE created_at BETWEEN :from AND :to GROUP BY status') : null;

$totals = static function (string $a, string $b) use ($totalsStmt, $newCustomersStmt, $quoteFlowStmt, $serviceQuotesStmt): array {
    $totalsStmt->execute([':from' => $a, ':to' => $b]);
    $row = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach (['all_orders', 'orders', 'void_orders', 'quotes', 'paid_orders'] as $k) $out[$k] = (int)($row[$k] ?? 0);
    foreach (['revenue', 'pending_value', 'pipeline'] as $k) $out[$k] = round((float)($row[$k] ?? 0), 2);
    $out['avg_ticket'] = $out['paid_orders'] > 0 ? round($out['revenue'] / $out['paid_orders'], 2) : 0;
    $placed = $out['orders'] + $out['void_orders'];
    $out['cancel_rate'] = $placed > 0 ? round($out['void_orders'] / $placed * 100, 1) : 0;
    $newCustomersStmt->execute([':from' => $a, ':to' => $b]);
    $out['new_customers'] = (int)$newCustomersStmt->fetchColumn();
    $quoteFlowStmt->execute([':from' => $a, ':to' => $b]);
    $flow = $quoteFlowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $out['cart_quotes'] = (int)($flow['total'] ?? 0);
    $out['cart_quotes_converted'] = (int)($flow['converted'] ?? 0);
    $out['cart_quotes_lost'] = (int)($flow['lost'] ?? 0);
    $out['quote_conversion'] = $out['cart_quotes'] > 0 ? round($out['cart_quotes_converted'] / $out['cart_quotes'] * 100, 1) : null;
    $out['service_quotes'] = 0;
    $out['service_quotes_by_status'] = ['nuevo' => 0, 'contactado' => 0, 'cerrado' => 0];
    if ($serviceQuotesStmt) {
        $serviceQuotesStmt->execute([':from' => $a, ':to' => $b]);
        foreach ($serviceQuotesStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['service_quotes'] += (int)$r['total'];
            $out['service_quotes_by_status'][(string)$r['status']] = (int)$r['total'];
        }
    }
    $out['new_quotes'] = $out['cart_quotes'] + $out['service_quotes'];
    return $out;
};

$current = $totals($from, $to);
$previous = $totals($prevFrom, $prevTo);

// Serie por día (o por hora si el periodo es un solo día), rellena con ceros.
$bucket = $hourly ? 'HOUR(' . $local('o.created_at') . ')' : "DATE_FORMAT({$local('o.created_at')}, '%Y-%m-%d')";
$userBucket = $hourly ? 'HOUR(' . $local('u.created_at') . ')' : "DATE_FORMAT({$local('u.created_at')}, '%Y-%m-%d')";
$seriesStmt = $pdo->prepare("SELECT $bucket AS b, SUM($isOrder) AS orders, SUM($isVoid) AS void_orders, SUM($isPaid) AS paid_orders,
        COALESCE(SUM(CASE WHEN $isPaid THEN o.total END), 0) AS revenue,
        COALESCE(SUM(CASE WHEN $isOrder AND NOT $isPaid THEN o.total END), 0) AS pending_value,
        SUM($isQuote) AS quotes
    FROM orders_rows o WHERE o.created_at BETWEEN :from AND :to GROUP BY b");
$usersSeriesStmt = $pdo->prepare("SELECT $userBucket AS b, COUNT(*) AS n FROM `$uTable` u WHERE u.created_at BETWEEN :from AND :to AND $notAdmin GROUP BY b");
$buildSeries = static function (string $a, string $b, string $firstDay, int $len) use ($seriesStmt, $usersSeriesStmt, $hourly): array {
    $seriesStmt->execute([':from' => $a, ':to' => $b]);
    $rows = [];
    foreach ($seriesStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(string)$r['b']] = $r;
    $usersSeriesStmt->execute([':from' => $a, ':to' => $b]);
    $users = $usersSeriesStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = [];
    for ($i = 0; $i < $len; $i++) {
        $key = $hourly ? (string)$i : smartispLocalDayShift($firstDay, $i);
        $r = $rows[$key] ?? [];
        $out[] = [
            'key' => $hourly ? sprintf('%02d:00', $i) : $key,
            'orders' => (int)($r['orders'] ?? 0),
            'void_orders' => (int)($r['void_orders'] ?? 0),
            'paid_orders' => (int)($r['paid_orders'] ?? 0),
            'quotes' => (int)($r['quotes'] ?? 0),
            'revenue' => round((float)($r['revenue'] ?? 0), 2),
            'pending_value' => round((float)($r['pending_value'] ?? 0), 2),
            'new_customers' => (int)($users[$key] ?? 0),
        ];
    }
    return $out;
};
$seriesLen = $hourly ? 24 : $spanDays;
$series = $buildSeries($from, $to, $fromDay, $seriesLen);
$previousSeries = $buildSeries($prevFrom, $prevTo, $prevFromDay, $hourly ? 24 : (int)(new DateTimeImmutable($prevFromDay))->diff(new DateTimeImmutable($prevToDay))->days + 1);

$byStatus = $section('by_status', static function () use ($pdo, $from, $to) {
    $stmt = $pdo->prepare("SELECT LOWER(COALESCE(NULLIF(o.status, ''), 'sin_estado')) AS status, COUNT(*) AS orders, COALESCE(SUM(o.total), 0) AS value
        FROM orders_rows o WHERE o.created_at BETWEEN :from AND :to GROUP BY 1 ORDER BY orders DESC");
    $stmt->execute([':from' => $from, ':to' => $to]);
    return array_map(static fn($r) => ['status' => $r['status'], 'orders' => (int)$r['orders'], 'value' => round((float)$r['value'], 2)], $stmt->fetchAll(PDO::FETCH_ASSOC));
}, []);

// Matriz 7 × 24: lunes = 0. Solo pedidos (sin anulados ni cotizaciones).
$heatmap = $section('heatmap', static function () use ($pdo, $from, $to, $local, $isOrder) {
    $ts = $local('o.created_at');
    $stmt = $pdo->prepare("SELECT WEEKDAY($ts) AS wd, HOUR($ts) AS h, COUNT(*) AS orders, COALESCE(SUM(o.total), 0) AS value
        FROM orders_rows o WHERE o.created_at BETWEEN :from AND :to AND $isOrder GROUP BY wd, h");
    $stmt->execute([':from' => $from, ':to' => $to]);
    $grid = array_fill(0, 7, array_fill(0, 24, 0));
    $value = array_fill(0, 7, array_fill(0, 24, 0.0));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $grid[(int)$r['wd']][(int)$r['h']] = (int)$r['orders'];
        $value[(int)$r['wd']][(int)$r['h']] = round((float)$r['value'], 2);
    }
    return ['orders' => $grid, 'value' => $value];
}, null);

// Productos y categorías del periodo.
[$topByUnits, $topByRevenue, $categories] = $section('products', static function () use ($pdo, $from, $to, $pTable) {
    $all = smartispTopProducts($pdo, $from, $to, 100000);
    $ids = array_values(array_filter(array_map(static fn($p) => (string)$p['key'], $all)));
    $info = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $stmt = $pdo->prepare("SELECT id, category FROM `$pTable` WHERE id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $stmt->execute($chunk);
        $info += $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $cats = [];
    foreach ($all as &$p) {
        $p['product_id'] = isset($info[$p['key']]) ? (string)$p['key'] : null;
        $cat = trim((string)($info[$p['key']] ?? '')) ?: 'Sin categoría';
        $cats[$cat] ??= ['category' => $cat, 'units' => 0, 'revenue' => 0.0, 'products' => 0];
        $cats[$cat]['units'] += $p['units'];
        $cats[$cat]['revenue'] = round($cats[$cat]['revenue'] + $p['revenue'], 2);
        $cats[$cat]['products']++;
    }
    unset($p);
    $byRevenue = $all;
    usort($byRevenue, static fn($a, $b) => $b['revenue'] <=> $a['revenue']);
    $cats = array_values($cats);
    usort($cats, static fn($a, $b) => ($b['revenue'] <=> $a['revenue']) ?: ($b['units'] <=> $a['units']));
    return [array_slice($all, 0, 10), array_slice($byRevenue, 0, 10), array_slice($cats, 0, 12)];
}, [[], [], []]);

// Compradores nuevos (primer pedido dentro del periodo) frente a recurrentes.
$customerFirst = "SELECT $customerKey AS ckey, MIN(o.created_at) AS first_at FROM orders_rows o WHERE $isOrder GROUP BY ckey";
$buyers = $section('buyers', static function () use ($pdo, $from, $to, $customerKey, $customerFirst, $isOrder, $isPaid) {
    $stmt = $pdo->prepare("SELECT SUM(f.first_at >= :f1) AS new_buyers, SUM(f.first_at < :f2) AS returning_buyers,
            COALESCE(SUM(CASE WHEN f.first_at >= :f3 THEN c.revenue END), 0) AS new_revenue,
            COALESCE(SUM(CASE WHEN f.first_at < :f4 THEN c.revenue END), 0) AS returning_revenue,
            COALESCE(SUM(CASE WHEN f.first_at >= :f5 THEN c.value END), 0) AS new_value,
            COALESCE(SUM(CASE WHEN f.first_at < :f6 THEN c.value END), 0) AS returning_value
        FROM (SELECT $customerKey AS ckey, COALESCE(SUM(CASE WHEN $isPaid THEN o.total END), 0) AS revenue, COALESCE(SUM(o.total), 0) AS value
              FROM orders_rows o WHERE o.created_at BETWEEN :from AND :to AND $isOrder GROUP BY ckey) c
        INNER JOIN ($customerFirst) f ON f.ckey = c.ckey");
    $stmt->execute([':f1' => $from, ':f2' => $from, ':f3' => $from, ':f4' => $from, ':f5' => $from, ':f6' => $from, ':from' => $from, ':to' => $to]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'new_buyers' => (int)($r['new_buyers'] ?? 0),
        'returning_buyers' => (int)($r['returning_buyers'] ?? 0),
        'new_revenue' => round((float)($r['new_revenue'] ?? 0), 2),
        'returning_revenue' => round((float)($r['returning_revenue'] ?? 0), 2),
        'new_value' => round((float)($r['new_value'] ?? 0), 2),
        'returning_value' => round((float)($r['returning_value'] ?? 0), 2),
    ];
}, null);

$topCustomers = $section('top_customers', static function () use ($pdo, $from, $to, $customerKey, $customerName, $customerEmail, $isOrder, $isPaid) {
    $stmt = $pdo->prepare("SELECT $customerKey AS ckey, $customerName AS name, $customerEmail AS email, COUNT(*) AS orders,
            COALESCE(SUM(o.total), 0) AS value, COALESCE(SUM(CASE WHEN $isPaid THEN o.total END), 0) AS revenue, MAX(o.created_at) AS last_order
        FROM orders_rows o WHERE o.created_at BETWEEN :from AND :to AND $isOrder GROUP BY ckey ORDER BY value DESC LIMIT 8");
    $stmt->execute([':from' => $from, ':to' => $to]);
    return array_map(static fn($r) => ['name' => $r['name'], 'email' => $r['email'], 'orders' => (int)$r['orders'],
        'value' => round((float)$r['value'], 2), 'revenue' => round((float)$r['revenue'], 2), 'last_order' => $r['last_order']], $stmt->fetchAll(PDO::FETCH_ASSOC));
}, []);

$cities = $hasCol('shipping') ? $section('cities', static function () use ($pdo, $from, $to, $isOrder) {
    $stmt = $pdo->prepare("SELECT LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(o.shipping, '$.city')))) AS city, COUNT(*) AS orders, COALESCE(SUM(o.total), 0) AS value
        FROM orders_rows o WHERE o.created_at BETWEEN :from AND :to AND $isOrder AND o.shipping IS NOT NULL AND JSON_VALID(o.shipping) GROUP BY 1");
    $stmt->execute([':from' => $from, ':to' => $to]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $city = trim((string)$r['city']);
        $name = $city === '' || $city === 'null' ? 'Sin ciudad' : mb_convert_case($city, MB_CASE_TITLE, 'UTF-8');
        $out[$name] ??= ['city' => $name, 'orders' => 0, 'value' => 0.0];
        $out[$name]['orders'] += (int)$r['orders'];
        $out[$name]['value'] = round($out[$name]['value'] + (float)$r['value'], 2);
    }
    usort($out, static fn($a, $b) => $b['value'] <=> $a['value']);
    return array_slice($out, 0, 10);
}, []) : [];

$inventory = $hasInventory ? $section('inventory', static function () use ($pdo, $pTable) {
    $free = 'GREATEST(CAST(i.quantity_available AS SIGNED) - CAST(i.quantity_reserved AS SIGNED), 0)';
    $row = $pdo->query("SELECT COUNT(*) AS tracked, COALESCE(SUM(i.quantity_available), 0) AS units, COALESCE(SUM($free), 0) AS free_units,
            COALESCE(SUM(i.quantity_reserved), 0) AS reserved_units, COALESCE(SUM(i.quantity_available * COALESCE(p.price, 0)), 0) AS stock_value,
            SUM($free = 0) AS out_of_stock, SUM($free > 0 AND $free <= " . SMARTISP_INSIGHTS_LOW_STOCK . ") AS low_stock
        FROM product_inventory i LEFT JOIN `$pTable` p ON p.id = i.product_id")->fetch(PDO::FETCH_ASSOC) ?: [];
    $low = $pdo->query("SELECT p.id, p.name, p.sku, p.price, p.visible, CAST(i.quantity_available AS SIGNED) AS available,
            CAST(i.quantity_reserved AS SIGNED) AS reserved, $free AS free
        FROM product_inventory i INNER JOIN `$pTable` p ON p.id = i.product_id
        WHERE $free <= " . SMARTISP_INSIGHTS_LOW_STOCK . " ORDER BY p.visible DESC, free ASC, p.name ASC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    return [
        'tracked' => (int)($row['tracked'] ?? 0),
        'units' => (int)($row['units'] ?? 0),
        'free_units' => (int)($row['free_units'] ?? 0),
        'reserved_units' => (int)($row['reserved_units'] ?? 0),
        'stock_value' => round((float)($row['stock_value'] ?? 0), 2),
        'out_of_stock' => (int)($row['out_of_stock'] ?? 0),
        'low_stock' => (int)($row['low_stock'] ?? 0),
        'low_threshold' => SMARTISP_INSIGHTS_LOW_STOCK,
        'low_items' => array_map(static fn($r) => ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'sku' => (string)($r['sku'] ?? ''),
            'price' => round((float)$r['price'], 2), 'visible' => (bool)$r['visible'], 'available' => (int)$r['available'], 'reserved' => (int)$r['reserved'], 'free' => (int)$r['free']], $low),
    ];
}, null) : null;

// Productos visibles sin ventas en 30/60/90 días (solo los que ya existían al empezar cada ventana).
$deadStock = $section('dead_stock', static function () use ($pdo, $pTable, $today, $hasOrderItems, $hasInventory, $isVoid) {
    $cut = [];
    foreach ([30, 60, 90] as $d) $cut[$d] = smartispLocalDayStartUtc(smartispLocalDayShift($today, -($d - 1)));
    $lastSale = [];
    if ($hasOrderItems) {
        $stmt = $pdo->prepare("SELECT oi.product_id, MAX(o.created_at) AS last_sale FROM order_items oi INNER JOIN orders_rows o ON o.id = oi.order_id
            WHERE o.created_at >= :cut AND NOT $isVoid AND oi.product_id IS NOT NULL AND oi.product_id <> '' GROUP BY oi.product_id");
        $stmt->execute([':cut' => $cut[90]]);
        $lastSale = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $stmt = $pdo->prepare("SELECT o.items, o.created_at FROM orders_rows o WHERE o.created_at >= :cut AND NOT $isVoid AND o.items IS NOT NULL AND TRIM(o.items) <> ''"
        . ($hasOrderItems ? ' AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id)' : ''));
    $stmt->execute([':cut' => $cut[90]]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
        foreach (smartispDecodeOrderItems($o['items']) as $item) {
            if ($item['product_id'] === '') continue;
            if (!isset($lastSale[$item['product_id']]) || $lastSale[$item['product_id']] < $o['created_at']) $lastSale[$item['product_id']] = $o['created_at'];
        }
    }
    $rows = $pdo->query("SELECT p.id, p.name, p.price, p.created_at" . ($hasInventory ? ', i.quantity_available AS stock' : ', NULL AS stock')
        . " FROM `$pTable` p" . ($hasInventory ? ' LEFT JOIN product_inventory i ON i.product_id = p.id' : '') . ' WHERE p.visible = 1')->fetchAll(PDO::FETCH_ASSOC);
    $counts = [30 => 0, 60 => 0, 90 => 0];
    $idle = [];
    $idleValue = 0.0;
    foreach ($rows as $r) {
        $sold = $lastSale[(string)$r['id']] ?? null;
        foreach ($counts as $d => $_) {
            $existed = empty($r['created_at']) || $r['created_at'] < $cut[$d];
            if ($existed && ($sold === null || $sold < $cut[$d])) $counts[$d]++;
        }
        if ((empty($r['created_at']) || $r['created_at'] < $cut[90]) && $sold === null) {
            $stockValue = $r['stock'] !== null ? max(0, (int)$r['stock']) * (float)$r['price'] : 0.0;
            $idleValue += $stockValue;
            $idle[] = ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'price' => round((float)$r['price'], 2),
                'stock' => $r['stock'] !== null ? (int)$r['stock'] : null, 'stock_value' => round($stockValue, 2)];
        }
    }
    usort($idle, static fn($a, $b) => ($b['stock_value'] <=> $a['stock_value']) ?: ($b['price'] <=> $a['price']));
    return ['days_30' => $counts[30], 'days_60' => $counts[60], 'days_90' => $counts[90], 'visible_products' => count($rows),
        'idle_stock_value' => round($idleValue, 2), 'items' => array_slice($idle, 0, 8)];
}, null);

// Horas promedio entre el pedido y el pago, y entre el pago y el envío (según order_events).
$timings = $hasEvents ? $section('timings', static function () use ($pdo, $from, $to) {
    $stmt = $pdo->prepare("SELECT COUNT(p.paid_at) AS paid_n,
            AVG(CASE WHEN p.paid_at >= o.created_at THEN TIMESTAMPDIFF(MINUTE, o.created_at, p.paid_at) END) AS to_paid,
            COUNT(CASE WHEN s.shipped_at >= p.paid_at THEN 1 END) AS shipped_n,
            AVG(CASE WHEN s.shipped_at >= p.paid_at THEN TIMESTAMPDIFF(MINUTE, p.paid_at, s.shipped_at) END) AS to_ship
        FROM orders_rows o
        LEFT JOIN (SELECT order_id, MIN(created_at) AS paid_at FROM order_events WHERE to_status = 'paid' GROUP BY order_id) p ON p.order_id = o.id
        LEFT JOIN (SELECT order_id, MIN(created_at) AS shipped_at FROM order_events WHERE to_status = 'shipped' GROUP BY order_id) s ON s.order_id = o.id
        WHERE o.created_at BETWEEN :from AND :to");
    $stmt->execute([':from' => $from, ':to' => $to]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'paid_samples' => (int)($r['paid_n'] ?? 0),
        'hours_to_paid' => $r['to_paid'] !== null ? round((float)$r['to_paid'] / 60, 1) : null,
        'shipped_samples' => (int)($r['shipped_n'] ?? 0),
        'hours_paid_to_shipped' => $r['to_ship'] !== null ? round((float)$r['to_ship'] / 60, 1) : null,
    ];
}, null) : null;

// Meta y proyección del mes calendario en curso (independiente del periodo elegido).
$goal = $section('goal', static function () use ($pdo, $today, $totals) {
    $monthStart = substr($today, 0, 8) . '01';
    $daysInMonth = (int)(new DateTimeImmutable($monthStart))->format('t');
    $elapsed = (int)substr($today, 8, 2);
    $month = $totals(smartispLocalDayStartUtc($monthStart), smartispLocalDayEndUtc($today));
    $prevStart = (new DateTimeImmutable($monthStart))->modify('first day of previous month')->format('Y-m-d');
    $prevEnd = (new DateTimeImmutable($monthStart))->modify('-1 day')->format('Y-m-d');
    $prev = $totals(smartispLocalDayStartUtc($prevStart), smartispLocalDayEndUtc($prevEnd));
    $stmt = $pdo->prepare('SELECT setting_value FROM settings_rows WHERE setting_key = :key LIMIT 1');
    $stmt->execute([':key' => SMARTISP_INSIGHTS_GOAL_KEY]);
    $target = (float)($stmt->fetchColumn() ?: 0);
    return [
        'month' => substr($today, 0, 7),
        'target' => round($target, 2),
        'revenue' => $month['revenue'],
        'pending_value' => $month['pending_value'],
        'orders' => $month['orders'],
        'days_elapsed' => $elapsed,
        'days_in_month' => $daysInMonth,
        'projection' => $elapsed > 0 ? round($month['revenue'] / $elapsed * $daysInMonth, 2) : 0,
        'previous_month_revenue' => $prev['revenue'],
    ];
}, null);

$alerts = $section('alerts', static fn() => smartispInsightsAlerts($pdo, $alertCtx), null);

$attention = $section('attention', static function () use ($pdo, $orderCols, $alertCtx, $hasServiceQuotes) {
    $cols = array_values(array_intersect(['id', 'customer_name', 'customer_email', 'customer_phone', 'total', 'created_at', 'payment_provider'], $orderCols));
    $stmt = $pdo->prepare('SELECT `' . implode('`, `', $cols) . "` FROM orders_rows WHERE status = 'pending' AND created_at < :stale ORDER BY created_at ASC LIMIT 6");
    $stmt->execute([':stale' => gmdate('Y-m-d H:i:s', time() - $alertCtx['stale_days'] * 86400)]);
    $stale = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $quotes = $hasServiceQuotes ? $pdo->query("SELECT id, client_type, service_interest, company_name, email, phone, created_at
        FROM service_quote_requests WHERE status = 'nuevo' ORDER BY created_at ASC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC) : [];
    return ['stale_orders' => $stale, 'service_quotes' => $quotes];
}, ['stale_orders' => [], 'service_quotes' => []]);

$recent = $section('recent', static function () use ($pdo, $orderCols) {
    $cols = array_values(array_intersect(['id', 'customer_name', 'customer_email', 'status', 'payment_status', 'payment_provider', 'total', 'created_at'], $orderCols));
    return $pdo->query('SELECT `' . implode('`, `', $cols) . '` FROM orders_rows ORDER BY created_at DESC, id DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC);
}, []);

$response = [
    'period' => $period,
    'from' => $fromDay,
    'to' => $toDay,
    'previous_from' => $prevFromDay,
    'previous_to' => $prevToDay,
    'days' => $spanDays,
    'granularity' => $hourly ? 'hour' : 'day',
    'generated_at' => gmdate('c'),
    'totals' => $current,
    'previous' => $previous,
    'series' => $series,
    'previous_series' => $previousSeries,
    'by_status' => $byStatus,
    'heatmap' => $heatmap,
    'top_products' => $topByUnits,
    'top_products_revenue' => $topByRevenue,
    'categories' => $categories,
    'buyers' => $buyers,
    'top_customers' => $topCustomers,
    'cities' => $cities,
    'inventory' => $inventory,
    'dead_stock' => $deadStock,
    'timings' => $timings,
    'goal' => $goal,
    'alerts' => $alerts,
    'attention' => $attention,
    'recent_orders' => $recent,
];

if ($fullScope) {
    // Tendencia de 12 meses y cohortes por mes del primer pedido (últimos 6 meses).
    $response['monthly'] = $section('monthly', static function () use ($pdo, $today, $local, $isOrder, $isPaid, $isVoid) {
        $start = (new DateTimeImmutable(substr($today, 0, 8) . '01'))->modify('-11 months')->format('Y-m-d');
        $ym = "DATE_FORMAT({$local('o.created_at')}, '%Y-%m')";
        $stmt = $pdo->prepare("SELECT $ym AS ym, SUM($isOrder) AS orders, SUM($isVoid) AS void_orders,
                COALESCE(SUM(CASE WHEN $isPaid THEN o.total END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN $isOrder AND NOT $isPaid THEN o.total END), 0) AS pending_value
            FROM orders_rows o WHERE o.created_at >= :start GROUP BY ym");
        $stmt->execute([':start' => smartispLocalDayStartUtc($start)]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[$r['ym']] = $r;
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $key = (new DateTimeImmutable($start))->modify("+$i months")->format('Y-m');
            $r = $rows[$key] ?? [];
            $out[] = ['month' => $key, 'orders' => (int)($r['orders'] ?? 0), 'void_orders' => (int)($r['void_orders'] ?? 0),
                'revenue' => round((float)($r['revenue'] ?? 0), 2), 'pending_value' => round((float)($r['pending_value'] ?? 0), 2)];
        }
        return $out;
    }, []);

    $response['cohorts'] = $section('cohorts', static function () use ($pdo, $today, $local, $isOrder, $customerKey) {
        $start = (new DateTimeImmutable(substr($today, 0, 8) . '01'))->modify('-5 months')->format('Y-m-d');
        $ym = "DATE_FORMAT({$local('o.created_at')}, '%Y-%m')";
        $firstYm = "DATE_FORMAT({$local('MIN(o.created_at)')}, '%Y-%m')";
        $stmt = $pdo->prepare("SELECT f.cohort, m.ym, COUNT(*) AS customers
            FROM (SELECT $customerKey AS ckey, $firstYm AS cohort FROM orders_rows o WHERE $isOrder GROUP BY ckey) f
            INNER JOIN (SELECT DISTINCT $customerKey AS ckey, $ym AS ym FROM orders_rows o WHERE $isOrder AND o.created_at >= :start) m ON m.ckey = f.ckey
            WHERE f.cohort >= :start_month GROUP BY f.cohort, m.ym");
        $stmt->execute([':start' => smartispLocalDayStartUtc($start), ':start_month' => substr($start, 0, 7)]);
        $months = [];
        for ($i = 0; $i < 6; $i++) $months[] = (new DateTimeImmutable($start))->modify("+$i months")->format('Y-m');
        $cells = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $cells[$r['cohort']][$r['ym']] = (int)$r['customers'];
        $out = [];
        foreach ($months as $i => $cohort) {
            $size = $cells[$cohort][$cohort] ?? 0;
            $retention = [];
            for ($k = 1; $k < 6 - $i; $k++) $retention[] = $cells[$cohort][$months[$i + $k]] ?? 0;
            $out[] = ['cohort' => $cohort, 'customers' => $size, 'returned' => $retention];
        }
        return $out;
    }, []);
}

echo json_encode($response);
exit;
