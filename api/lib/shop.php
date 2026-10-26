<?php
// Lógica de pedidos e inventario compartida entre handlers (pedidos, dashboard, estadísticas,
// reportes). Vive fuera de api/handlers/ porque esos archivos se incluyen en orden y cada uno
// sale con exit: una función definida en orders.php no existe todavía cuando corre products.php.

// Estados que no cuentan como venta en ningún reporte.
const SMARTISP_VOID_STATUSES = ['cancelled', 'canceled', 'refunded'];

// Pagos que sí cuentan como cobrados (mismo criterio que admin-orders-stats).
const SMARTISP_CONFIRMED_PAYMENTS = ['paid', 'confirmed', 'succeeded', 'completed', 'approved', 'captured'];

function smartispSqlList(array $values): string {
    return "'" . implode("','", $values) . "'";
}

function smartispSqlIsVoid(string $statusColumn = 'status'): string {
    return "(LOWER(COALESCE($statusColumn, '')) IN (" . smartispSqlList(SMARTISP_VOID_STATUSES) . '))';
}

function smartispSqlIsPaid(string $paymentColumn = 'payment_status'): string {
    return "(LOWER(COALESCE($paymentColumn, '')) IN (" . smartispSqlList(SMARTISP_CONFIRMED_PAYMENTS) . '))';
}

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

// Fecha y hora UTC de la base (Y-m-d H:i:s) en hora de Ecuador, para mostrar o exportar.
function smartispUtcToLocal(?string $value): string {
    $value = trim((string)$value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) return '';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(smartispReportTimezone())->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return $value;
    }
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

// Productos vendidos entre dos fechas (Y-m-d H:i:s), excluyendo pedidos anulados, a partir de
// orders_rows.items (la tabla order_items de la migración 007 nunca se llegó a escribir).
// $limit 0 devuelve todos.
function smartispTopProducts(PDO $pdo, string $from, string $to, int $limit = 10): array {
    $products = [];
    $stmt = $pdo->prepare("SELECT o.id, o.items FROM orders_rows o
        WHERE o.created_at BETWEEN :from AND :to
          AND o.items IS NOT NULL AND TRIM(o.items) <> ''
          AND NOT " . smartispSqlIsVoid('o.status'));
    $stmt->execute([':from' => $from, ':to' => $to]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
        foreach (smartispDecodeOrderItems($order['items']) as $item) {
            $key = $item['product_id'] !== '' ? $item['product_id'] : ($item['sku'] !== '' ? $item['sku'] : strtolower($item['name']));
            $products[$key] ??= ['key' => $key, 'name' => $item['name'], 'units' => 0, 'revenue' => 0.0, 'order_ids' => []];
            $products[$key]['units'] += $item['quantity'];
            $products[$key]['revenue'] += $item['quantity'] * $item['price'];
            $products[$key]['order_ids'][(string)$order['id']] = true;
        }
    }

    foreach ($products as &$product) {
        $product['orders'] = count($product['order_ids']);
        $product['revenue'] = round($product['revenue'], 2);
        unset($product['order_ids']);
    }
    unset($product);
    uasort($products, static fn(array $a, array $b): int => ($b['units'] <=> $a['units']) ?: ($b['orders'] <=> $a['orders']));
    $products = array_values($products);
    return $limit > 0 ? array_slice($products, 0, $limit) : $products;
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
// RESPUESTAS DE PEDIDOS
// -------------------------------------------------------------

function smartispDecodeOrderRow(array $r): array {
    if (isset($r['items']) && is_string($r['items'])) {
        $r['items'] = json_decode($r['items'], true) ?: [];
    }
    if (isset($r['shipping']) && is_string($r['shipping'])) {
        $r['shipping'] = json_decode($r['shipping'], true) ?: [];
    }
    return $r;
}

// Envía $payload y cierra la conexión con el navegador; lo que siga (correos, avisos) corre
// después sin hacerlo esperar. Un SMTP lento hacía que el navegador diera el pedido por fallido
// y el cliente lo reintentara.
function smartispFinishResponse(string $payload = ''): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    ignore_user_abort(true);
    echo $payload;
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    else flush();
}

// Respuesta de éxito de un pedido nuevo; la misma se devuelve al repetir el intento de compra.
function smartispOrderSuccessPayload(string $orderId, string $status, float $total, array $items): array {
    return [
        'ok'          => true,
        'orderId'     => $orderId,
        'id'          => $orderId,
        'shortId'     => preg_replace('/^PED-/', '', $orderId),
        'isQuote'     => $status === 'quote_requested',
        'emailQueued' => true,
        'order'       => [
            'id'     => $orderId,
            'status' => $status,
            'total'  => $total,
            'items'  => array_map(static fn($i) => [
                'id' => (string)($i['id'] ?? ''), 'name' => (string)($i['name'] ?? ''), 'sku' => $i['sku'] ?? '',
                'price' => (float)($i['price'] ?? 0), 'quantity' => (int)($i['quantity'] ?? 1),
            ], $items),
        ],
    ];
}

// Clave de idempotencia del checkout (un UUID por intento de compra), por cabecera o cuerpo.
function smartispIdempotencyKey(array $body): ?string {
    $key = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? (is_scalar($body['idempotencyKey'] ?? null) ? $body['idempotencyKey'] : '')));
    return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key) ? $key : null;
}

// Pedido con esa clave. 'mine': creado hace menos de 24 h por el mismo cliente (cuenta o correo);
// si no, la clave no se reutiliza, para no devolver el pedido de otra persona.
function smartispFindIdempotentOrder(PDO $pdo, string $key, ?string $userId, string $email): ?array {
    $stmt = $pdo->prepare('SELECT * FROM orders_rows WHERE idempotency_key = :k LIMIT 1');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $recent = !empty($row['created_at']) && strtotime($row['created_at'] . ' UTC') >= time() - 86400;
    $shipping = json_decode((string)($row['shipping'] ?? ''), true) ?: [];
    $rowEmail = strtolower(trim((string)($row['customer_email'] ?? ($shipping['email'] ?? ''))));
    $sameUser = $userId !== null && $userId !== '' && (string)($row['user_id'] ?? '') === $userId;
    $sameEmail = $email !== '' && $rowEmail === strtolower(trim($email));
    return ['row' => $row, 'mine' => $recent && ($sameUser || $sameEmail)];
}

// Se responde con el estado inicial del pedido, no con el actual (el admin pudo cambiarlo):
// cotización si algún artículo no tenía precio.
function smartispIdempotentOrderPayload(array $row): array {
    $items = json_decode((string)($row['items'] ?? ''), true) ?: [];
    $total = (float)($row['total'] ?? 0);
    $isQuote = $total <= 0;
    foreach ($items as $item) if ((float)($item['price'] ?? 0) <= 0) $isQuote = true;
    return smartispOrderSuccessPayload((string)$row['id'], $isQuote ? 'quote_requested' : 'pending', $total, $items);
}

// -------------------------------------------------------------
// PEDIDOS POR CLIENTE (directorio de clientes y su CSV)
// -------------------------------------------------------------
// Cada pedido cuenta una sola vez: para la cuenta de su user_id si esa cuenta existe y, si no,
// para su correo (que puede ser el de una cuenta o el de un comprador invitado). El valor es la
// suma de los pedidos no anulados. by_user/by_email generan subconsultas agrupadas (customer_key,
// order_count, order_value, last_order, first_order); $where añade filtros sobre o.
function smartispCustomerOrderAggregates(PDO $pdo, string $usersTable, string $usersIdColumn): array {
    $columns = smartispTableColumns($pdo, 'orders_rows');
    $pick = static function (array $candidates) use ($columns): ?string {
        foreach ($candidates as $candidate) if (in_array($candidate, $columns, true)) return $candidate;
        return null;
    };
    $userId = $pick(['user_id']);
    $email = $pick(['customer_email', 'email']);
    $name = $pick(['customer_name', 'name']);
    $phone = $pick(['customer_phone', 'phone']);
    $total = $pick(['total', 'amount']);
    $status = $pick(['status']);
    $created = $pick(['created_at']);

    $value = $total ? 'COALESCE(SUM(CASE WHEN ' . ($status ? smartispSqlIsVoid("o.`$status`") : '0') . " THEN 0 ELSE o.`$total` END), 0)" : '0';
    $aggregate = "COUNT(*) AS order_count, $value AS order_value, "
        . ($created ? "MAX(o.`$created`) AS last_order, MIN(o.`$created`) AS first_order" : 'NULL AS last_order, NULL AS first_order');
    $emailKey = $email ? "LOWER(TRIM(o.`$email`))" : null;
    $orphan = $userId ? " AND (o.`$userId` IS NULL OR o.`$userId` = '' OR NOT EXISTS (SELECT 1 FROM `$usersTable` ux WHERE ux.`$usersIdColumn` = o.`$userId`))" : '';

    return [
        'user_column' => $userId,
        'email_key' => $emailKey,
        'by_user' => $userId ? static fn(string $where = ''): string => "SELECT o.`$userId` AS customer_key, $aggregate FROM orders_rows o WHERE o.`$userId` IS NOT NULL AND o.`$userId` <> ''$where GROUP BY o.`$userId`" : null,
        'by_email' => $email ? static fn(string $where = ''): string => "SELECT $emailKey AS customer_key, $aggregate, "
            . ($name ? "MAX(NULLIF(TRIM(o.`$name`), ''))" : "''") . ' AS customer_name, '
            . ($phone ? "MAX(NULLIF(TRIM(o.`$phone`), ''))" : 'NULL') . ' AS customer_phone, '
            . "MAX(o.`$email`) AS customer_email FROM orders_rows o WHERE $emailKey <> ''$orphan$where GROUP BY $emailKey" : null,
    ];
}

// JOINs y columnas de pedidos de una cuenta (alias u) a partir de las subconsultas de arriba.
function smartispCustomerAccountJoin(array $aggregates, string $userIdColumn, string $userEmailColumn, string $userWhere = '', string $emailWhere = ''): array {
    $joins = '';
    $sources = [];
    if ($aggregates['by_user']) {
        $joins .= ' LEFT JOIN (' . $aggregates['by_user']($userWhere) . ") bu ON bu.customer_key = u.`$userIdColumn`";
        $sources[] = 'bu';
    }
    if ($aggregates['by_email']) {
        $joins .= ' LEFT JOIN (' . $aggregates['by_email']($emailWhere) . ") be ON be.customer_key = u.`$userEmailColumn`";
        $sources[] = 'be';
    }
    $sum = static fn(string $field): string => $sources ? implode(' + ', array_map(static fn($s) => "COALESCE($s.$field, 0)", $sources)) : '0';
    $last = match (count($sources)) {
        0 => 'NULL',
        1 => $sources[0] . '.last_order',
        default => 'CASE WHEN bu.last_order IS NULL THEN be.last_order WHEN be.last_order IS NULL THEN bu.last_order ELSE GREATEST(bu.last_order, be.last_order) END',
    };
    return ['joins' => $joins, 'order_count' => $sum('order_count'), 'order_value' => $sum('order_value'), 'last_order' => $last];
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
        smartispFinishResponse();
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
