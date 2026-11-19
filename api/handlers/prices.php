<?php
// Precios: productos sin precio o sin costo, y listas de precios de proveedores con vista previa.
if (!in_array($action ?? '', ['admin-prices', 'admin-price-import'], true)) return;

requireAdminAuth();
smartispReleaseSession();
require_once __DIR__ . '/../lib/price-guard.php';
$pTable = getProductsTableName($pdo);
$columns = smartispTableColumns($pdo, $pTable);
$hasCost = in_array('cost', $columns, true);
$hasSupplier = in_array('supplier', $columns, true);
$quote = '(price IS NULL OR price <= 0)';
$filters = [
    'sin-precio' => "(visible = 1 AND $quote)",
    'sin-costo' => $hasCost ? '(visible = 1 AND price > 0 AND (cost IS NULL OR cost <= 0))' : '(1 = 0)',
    'ocultos' => "(visible = 0 AND $quote)",
];
$formula = ['increments' => SMARTISP_PRICE_INCREMENTS, 'factor' => round(smartispPriceFromCost(10000) / 10000, 4)];

if ($action === 'admin-prices') {
    require_once __DIR__ . '/../lib/catalog-search.php';
    $category = trim((string)($_GET['category'] ?? ''));
    $brand = smartispFindBrand((string)($_GET['brand'] ?? ''));
    // supplier=- son los que no tienen proveedor asignado.
    $supplier = $hasSupplier ? mb_substr(trim((string)($_GET['supplier'] ?? '')), 0, 60) : '';
    // Cada lista de opciones se cuenta sin su propio filtro, para poder cambiar de categoría, marca o proveedor.
    $scope = static function (array $skip = []) use ($category, $brand, $supplier): array {
        $sql = '';
        $params = [];
        if ($category !== '' && !in_array('category', $skip, true)) { $sql .= ' AND category = :cat'; $params[':cat'] = $category; }
        if ($brand !== null && !in_array('brand', $skip, true)) { $sql .= ' AND LOWER(name) REGEXP :brand'; $params[':brand'] = smartispBrandPattern($brand); }
        if ($supplier !== '' && !in_array('supplier', $skip, true)) {
            if ($supplier === '-') $sql .= " AND (supplier IS NULL OR supplier = '')";
            else { $sql .= ' AND supplier = :sup'; $params[':sup'] = $supplier; }
        }
        return [$sql, $params];
    };
    $run = static function (string $sql, array $params) use ($pdo): PDOStatement {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    };
    [$scopeSql, $scopeParams] = $scope();
    $counts = [];
    foreach ($filters as $key => $where) $counts[$key] = (int)$run("SELECT COUNT(*) FROM `$pTable` WHERE $where$scopeSql", $scopeParams)->fetchColumn();
    $status = isset($filters[$_GET['status'] ?? '']) ? $_GET['status'] : 'sin-precio';
    $where = $filters[$status];
    [$sql, $params] = $scope(['category']);
    $categories = array_map(static fn($row) => ['name' => (string)$row['category'], 'count' => (int)$row['n']],
        $run("SELECT category, COUNT(*) AS n FROM `$pTable` WHERE $where$sql AND category <> '' GROUP BY category ORDER BY category", $params)->fetchAll(PDO::FETCH_ASSOC));
    [$sql, $params] = $scope(['brand']);
    $brands = smartispBrandFacets($run("SELECT name FROM `$pTable` WHERE $where$sql", $params)->fetchAll(PDO::FETCH_COLUMN), 80);
    $suppliers = [];
    if ($hasSupplier) {
        [$sql, $params] = $scope(['supplier']);
        $suppliers = array_map(static fn($row) => ['name' => (string)$row['supplier'], 'count' => (int)$row['n']],
            $run("SELECT COALESCE(supplier, '') AS supplier, COUNT(*) AS n FROM `$pTable` WHERE $where$sql GROUP BY COALESCE(supplier, '') ORDER BY COALESCE(supplier, '') = '', COALESCE(supplier, '')", $params)->fetchAll(PDO::FETCH_ASSOC));
    }
    $where .= $scopeSql;
    $params = $scopeParams;
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') { $where .= ' AND (name LIKE :q1 OR sku LIKE :q2)'; $params[':q1'] = $params[':q2'] = '%' . $q . '%'; }
    // all=1: la lista completa para exportarla a Excel o CSV.
    $all = (string)($_GET['all'] ?? '') === '1';
    $limit = $all ? 10000 : max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $page = $all ? 1 : max(1, (int)($_GET['page'] ?? 1));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$pTable` WHERE $where");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT id, name, sku, category, visible, price + 0 AS price, " . ($hasCost ? 'cost + 0' : 'NULL') . " AS cost, " . ($hasSupplier ? 'supplier' : 'NULL') . " AS supplier, image_url FROM `$pTable` WHERE $where ORDER BY category ASC, name ASC LIMIT $limit OFFSET " . (($page - 1) * $limit));
    $stmt->execute($params);
    $products = array_map(static function ($row) {
        $first = trim(explode('|', (string)$row['image_url'])[0] ?? '');
        return [
            'id' => $row['id'], 'name' => $row['name'], 'sku' => $row['sku'], 'category' => $row['category'],
            'visible' => (int)$row['visible'] === 1, 'price' => (float)$row['price'],
            'cost' => $row['cost'] !== null && (float)$row['cost'] > 0 ? (float)$row['cost'] : null,
            'supplier' => (string)($row['supplier'] ?? ''),
            'image' => preg_match('#^(https?://|/api/auth/serve-upload)#', $first) ? $first : '',
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['counts' => $counts, 'status' => $status, 'categories' => $categories, 'brands' => $brands, 'brand' => $brand, 'suppliers' => $suppliers,
        'products' => $products, 'total' => $total, 'page' => $page, 'totalPages' => max(1, (int)ceil($total / $limit)), 'formula' => $formula, 'hasCost' => $hasCost, 'hasSupplier' => $hasSupplier]);
    exit;
}

smartispRequirePost();
$rows = $body['rows'] ?? [];
if (!is_array($rows) || count($rows) > 5000) {
    http_response_code(400);
    echo json_encode(['error' => 'Envía como máximo 5000 filas por importación.']);
    exit;
}
$amount = static function ($value): ?float {
    if (!is_numeric($value)) return null;
    $value = round((float)$value, 2);
    return $value > 0 && $value <= 1000000 ? $value : null;
};
$supplierName = static fn($value): string => mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($value) ? (string)$value : '')), 0, 60);

// Guardar lo confirmado en la vista previa: [{id, price, cost?, show?, supplier?}].
if (!empty($body['apply'])) {
    $find = $pdo->prepare("SELECT id, name, visible FROM `$pTable` WHERE id = :id");
    $results = [];
    $paths = [];
    foreach ($rows as $row) {
        $id = is_array($row) ? trim((string)($row['id'] ?? '')) : '';
        $price = $amount($row['price'] ?? null);
        $cost = $amount($row['cost'] ?? null);
        $find->execute([':id' => $id]);
        $product = $id !== '' ? $find->fetch(PDO::FETCH_ASSOC) : false;
        if (!$product) { $results[] = ['id' => $id, 'status' => 'not_found']; continue; }
        if ($price === null) { $results[] = ['id' => $id, 'status' => 'invalid']; continue; }
        $sets = ['price = :price'];
        $params = [':price' => $price, ':id' => $id];
        if ($hasCost && $cost !== null) { $sets[] = 'cost = :cost'; $params[':cost'] = $cost; }
        $supplier = $hasSupplier ? $supplierName($row['supplier'] ?? '') : '';
        if ($supplier !== '') { $sets[] = 'supplier = :supplier'; $params[':supplier'] = $supplier; }
        $show = !empty($row['show']) && (int)$product['visible'] === 0;
        if ($show) $sets[] = 'visible = 1';
        $pdo->prepare("UPDATE `$pTable` SET " . implode(', ', $sets) . ", updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute($params);
        $results[] = ['id' => $id, 'status' => 'saved', 'price' => $price, 'cost' => $hasCost ? $cost : null, 'supplier' => $supplier, 'shown' => $show];
        if ($show || (int)$product['visible'] === 1) $paths[] = smartispProductPath($id, (string)$product['name']);
    }
    $saved = count(array_filter($results, static fn($r) => $r['status'] === 'saved'));
    smartispAuditNote(['summary' => "Precios guardados: $saved producto" . ($saved === 1 ? '' : 's')]);
    echo json_encode(['results' => $results, 'saved' => $saved]);
    smartispNotifySearchEngines($paths);
    exit;
}

// Vista previa: cada fila trae id, sku y/o número de parte, y costo del proveedor (sin IVA) o precio final.
$norm = static fn($value): string => preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$value)));
$catalog = $pdo->query("SELECT id, name, sku, category, visible, price + 0 AS price, " . ($hasCost ? 'cost + 0' : 'NULL') . " AS cost, " . ($hasSupplier ? 'supplier' : 'NULL') . " AS supplier FROM `$pTable`")->fetchAll(PDO::FETCH_ASSOC);
$byId = $bySku = $byTail = $byToken = [];
foreach ($catalog as $i => $product) {
    $byId[(string)$product['id']] = $i;
    $sku = $norm($product['sku']);
    if ($sku !== '') $bySku[$sku][] = $i;
    // Códigos de Siglo21: 9 caracteres de marca, tipo y subtipo (TPLCAMIP0) y después el número de parte.
    if (preg_match('/^[A-Z]{6}[A-Z0-9]{3}/', strtoupper(trim((string)$product['sku']))) && strlen($sku) > 11) $byTail[substr($sku, 9)][] = $i;
    foreach (preg_split('/[\s\/,;|()+]+/', strtoupper((string)$product['name'])) ?: [] as $token) {
        $token = $norm($token);
        if (strlen($token) >= 5 && preg_match('/\d/', $token) && preg_match('/[A-Z]/', $token)) $byToken[$token][$i] = $i;
    }
}
$match = static function (array $row) use ($norm, $byId, $bySku, $byTail, $byToken): array {
    $id = trim((string)($row['id'] ?? ''));
    if ($id !== '' && isset($byId[$id])) return [[$byId[$id]], 'id'];
    foreach (['sku' => 'sku', 'part' => 'parte'] as $field => $how) {
        $key = $norm($row[$field] ?? '');
        if (strlen($key) >= 3 && !empty($bySku[$key])) return [$bySku[$key], $how];
    }
    foreach (['part', 'sku'] as $field) {
        $key = $norm($row[$field] ?? '');
        if (strlen($key) < 5) continue;
        if (!empty($byTail[$key])) return [$byTail[$key], 'parte'];
        if (!empty($byToken[$key])) return [array_values($byToken[$key]), 'nombre'];
    }
    return [[], ''];
};

$results = [];
foreach (array_values($rows) as $index => $row) {
    if (!is_array($row)) continue;
    $base = [
        'row' => (int)($row['row'] ?? $index + 1), 'file' => mb_substr((string)($row['file'] ?? ''), 0, 80),
        'key' => mb_substr(trim((string)($row['sku'] ?? '')) ?: trim((string)($row['part'] ?? '')) ?: trim((string)($row['id'] ?? '')), 0, 80),
        'part' => mb_substr(trim((string)($row['part'] ?? '')), 0, 80), 'supplierName' => mb_substr(trim((string)($row['name'] ?? '')), 0, 160),
        'stock' => mb_substr(trim((string)($row['stock'] ?? '')), 0, 30), 'fileSupplier' => $supplierName($row['fileSupplier'] ?? ''),
    ];
    $price = $amount($row['price'] ?? null);
    $cost = $amount($row['cost'] ?? null);
    $newPrice = $price ?? ($cost !== null ? smartispPriceFromCost($cost) : null);
    $newCost = $hasCost ? $cost : null;
    [$found, $how] = $match($row);
    if (!$found) { $results[] = $base + ['status' => 'not_found', 'newPrice' => $newPrice, 'newCost' => $newCost]; continue; }
    $multi = count($found) > 1;
    foreach (array_slice($found, 0, 5) as $i) {
        $p = $catalog[$i];
        $old = round((float)$p['price'], 2);
        $oldCost = $p['cost'] !== null && (float)$p['cost'] > 0 ? round((float)$p['cost'], 2) : null;
        if ($newPrice === null) $status = 'no_price';
        elseif ($old <= 0) $status = 'quote';
        elseif (abs($newPrice - $old) < 0.005) $status = $newCost !== null && ($oldCost === null || abs($newCost - $oldCost) >= 0.005) ? 'cost' : 'same';
        elseif (smartispPriceJumpIsSuspicious($old, $newPrice)) $status = 'suspicious';
        else $status = $newPrice > $old ? 'up' : 'down';
        $results[] = $base + [
            'status' => $status, 'how' => $how, 'multi' => $multi, 'id' => $p['id'], 'name' => $p['name'], 'sku' => $p['sku'],
            'category' => $p['category'], 'visible' => (int)$p['visible'] === 1, 'supplier' => (string)($p['supplier'] ?? ''),
            'oldPrice' => $old, 'oldCost' => $oldCost, 'newPrice' => $newPrice, 'newCost' => $newCost,
        ];
    }
}
echo json_encode(['results' => $results, 'formula' => $formula]);
exit;
