<?php
// Lógica de pedidos e inventario compartida entre handlers (pedidos, dashboard, estadísticas,
// reportes). Vive fuera de api/handlers/ porque esos archivos se incluyen en orden y cada uno
// sale con exit: una función definida en orders.php no existe todavía cuando corre products.php.

// Estados que no cuentan como venta en ningún reporte.
const SMARTISP_VOID_STATUSES = ['cancelled', 'canceled', 'refunded'];

// Pagos que sí cuentan como cobrados (mismo criterio que admin-orders-stats).
const SMARTISP_CONFIRMED_PAYMENTS = ['paid', 'confirmed', 'succeeded', 'completed', 'approved', 'captured'];

// -------------------------------------------------------------
// DÍAS DE REPORTE
// -------------------------------------------------------------
// created_at se guarda en UTC (pedidos con gmdate, columnas con CURRENT_TIMESTAMP en un servidor
// UTC). Los reportes cuentan días de Ecuador: America/Guayaquil es UTC-5 fijo, sin horario de
// verano, así que en SQL basta restar el desfase y no hacen falta las tablas de zonas de MySQL.
const SMARTISP_REPORT_TIMEZONE = 'America/Guayaquil';

function smartispReportTimezone(): DateTimeZone {
    static $tz = null;
    return $tz ??= new DateTimeZone(SMARTISP_REPORT_TIMEZONE);
}

function smartispLocalToday(): string {
    return (new DateTimeImmutable('now', smartispReportTimezone()))->format('Y-m-d');
}

function smartispLocalDayShift(string $day, int $days): string {
    return (new DateTimeImmutable($day, smartispReportTimezone()))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

// Inicio (00:00 en Ecuador) del día local Y-m-d, expresado en UTC para comparar con created_at.
function smartispLocalDayStartUtc(string $day): string {
    return (new DateTimeImmutable($day . ' 00:00:00', smartispReportTimezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

// Último segundo del día local Y-m-d, en UTC.
function smartispLocalDayEndUtc(string $day): string {
    return (new DateTimeImmutable($day . ' 23:59:59', smartispReportTimezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

// Expresión SQL con el día de Ecuador de una columna UTC.
function smartispSqlLocalDay(string $column): string {
    $offsetHours = intdiv(smartispReportTimezone()->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC'))), 3600);
    return "DATE(DATE_ADD($column, INTERVAL $offsetHours HOUR))";
}

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

class SmartispStockException extends RuntimeException {}

// Debe correr dentro de una transacción. Si el cambio necesita unidades libres (reservar o
// descontar) y no las hay, lanza SmartispStockException sin tocar nada: antes se recortaba a 0
// en silencio y el inventario quedaba descuadrado.
function smartispApplyOrderStock(PDO $pdo, array $items, string $from, string $to): void {
    if ($from === $to) return;
    // [disponible, reservado] que ocupa una unidad en cada situación.
    $weights = ['released' => [0, 0], 'reserved' => [0, 1], 'consumed' => [-1, 0]];
    $fromWeight = $weights[$from] ?? $weights['released'];
    $toWeight = $weights[$to] ?? $weights['released'];
    $da = $toWeight[0] - $fromWeight[0];
    $dr = $toWeight[1] - $fromWeight[1];

    $quantities = [];
    $names = [];
    foreach (smartispDecodeOrderItems($items) as $item) {
        if (!$item['stock_tracked'] || $item['product_id'] === '') continue;
        $quantities[$item['product_id']] = ($quantities[$item['product_id']] ?? 0) + $item['quantity'];
        $names[$item['product_id']] = $item['name'];
    }
    // Bloquear siempre en el mismo orden evita interbloqueos entre pedidos simultáneos.
    ksort($quantities, SORT_STRING);
    $lock = $pdo->prepare('SELECT CAST(quantity_available AS SIGNED) AS available, CAST(quantity_reserved AS SIGNED) AS reserved
        FROM product_inventory WHERE product_id = :id FOR UPDATE');
    $update = $pdo->prepare('UPDATE product_inventory SET quantity_available = :available, quantity_reserved = :reserved WHERE product_id = :id');
    $pending = [];
    foreach ($quantities as $productId => $qty) {
        $lock->execute([':id' => $productId]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$row) continue; // Dejó de controlarse: se vende sin límite.
        $available = (int)$row['available'] + $da * $qty;
        $reserved = (int)$row['reserved'] + $dr * $qty;
        if ($available < 0 || ($da - $dr < 0 && $available - max(0, $reserved) < 0)) {
            $free = max(0, (int)$row['available'] - (int)$row['reserved']);
            throw new SmartispStockException('No hay stock libre suficiente de "' . $names[$productId] . '" (libre: ' . $free . ', necesita: ' . $qty . '). Ajusta el inventario antes de cambiar el estado.');
        }
        // Liberar más de lo reservado solo pasa con datos descuadrados de antes: se deja en 0.
        $pending[] = [':available' => $available, ':reserved' => max(0, $reserved), ':id' => $productId];
    }
    foreach ($pending as $params) $update->execute($params);
}

// El inventario por pedido solo se activa cuando la migración 037 agregó stock_state; sin ella
// todo sigue como antes (se valida disponibilidad pero no se aparta stock).
function smartispOrderStockEnabled(PDO $pdo): bool {
    return smartispTableExists($pdo, 'product_inventory')
        && in_array('stock_state', smartispTableColumns($pdo, 'orders_rows'), true);
}

// -------------------------------------------------------------
// INDEXNOW (Bing, Yandex y otros). Inactivo sin INDEXNOW_KEY.
// -------------------------------------------------------------

function smartispIndexNowKey(): string {
    $key = trim((string)(getenv('INDEXNOW_KEY') ?: ''));
    return preg_match('/^[A-Za-z0-9-]{8,128}$/', $key) ? $key : '';
}

function smartispNotifySearchEngines(array $paths): void {
    $key = smartispIndexNowKey();
    $paths = array_values(array_unique(array_filter($paths, fn($p) => is_string($p) && str_starts_with($p, '/'))));
    if ($key === '' || !$paths || !function_exists('curl_init')) return;
    $siteUrl = rtrim((string)(getenv('SITE_URL') ?: 'https://smart-isp.com.ec'), '/');
    register_shutdown_function(static function () use ($key, $paths, $siteUrl): void {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        $ch = curl_init('https://api.indexnow.org/indexnow');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS => json_encode([
                'host' => (string)parse_url($siteUrl, PHP_URL_HOST),
                'key' => $key,
                'keyLocation' => $siteUrl . '/' . $key . '.txt',
                'urlList' => array_map(fn($p) => $siteUrl . $p, $paths),
            ], JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status >= 400 || $status === 0) error_log('IndexNow respondió ' . $status . ' para ' . implode(', ', $paths));
        curl_close($ch);
    });
}
