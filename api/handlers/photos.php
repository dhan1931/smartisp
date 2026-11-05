<?php
// Fotos de producto: detectar las rotas o faltantes y reemplazarlas por copias guardadas en el servidor.
if (!in_array($action ?? '', ['admin-photos', 'admin-photo-candidates', 'admin-photo-replace', 'admin-photo-auto', 'admin-photo-ok', 'admin-photo-match'], true)) return;

requireAdminAuth();
require_once __DIR__ . '/../lib/photo-sources.php';
$pTable = getProductsTableName($pdo);

$photoIdsIn = static function (array $ids, string $prefix, array &$params): string {
    if (!$ids) return "''";
    $keys = [];
    foreach (array_values($ids) as $i => $id) { $keys[] = ":$prefix$i"; $params[":$prefix$i"] = (string)$id; }
    return implode(', ', $keys);
};
$paramsFor = static fn(string $where, array $params): array => array_filter($params, static fn($k) => str_contains($where, $k), ARRAY_FILTER_USE_KEY);
$external = "(image_url LIKE 'http://%' OR image_url LIKE 'https://%')";
$noPhoto = "(image_url IS NULL OR TRIM(image_url) = '')";
$local = "(image_url LIKE '/api/auth/serve-upload%')";

if ($action === 'admin-photos') {
    $tried = smartispPhotoMap($pdo, SMARTISP_PHOTO_AUTO_TRIED_KEY);
    $webFixed = smartispPhotoMap($pdo, SMARTISP_PHOTO_WEB_FIXED_KEY);
    $params = [];
    $in = $photoIdsIn(array_keys(smartispArchivePruneFailed($pdo, $pTable, smartispArchiveFailed($pdo))), 'f', $params);
    $inWeb = $photoIdsIn(array_keys($webFixed), 'w', $params);
    $filters = [
        'rotas' => "($external AND id IN ($in))",
        'sin-foto' => $noPhoto,
        'por-revisar' => "($external AND id NOT IN ($in))",
        'web' => "($local AND id IN ($inWeb))",
        'propias' => $local,
    ];
    $counts = [];
    foreach ($filters as $key => $where) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$pTable` WHERE $where");
        $stmt->execute($paramsFor($where, $params));
        $counts[$key] = (int)$stmt->fetchColumn();
    }
    $status = isset($filters[$_GET['status'] ?? '']) ? $_GET['status'] : 'rotas';
    $where = $filters[$status];
    $queryParams = $paramsFor($where, $params);
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') { $where .= ' AND (name LIKE :q1 OR sku LIKE :q2)'; $queryParams[':q1'] = $queryParams[':q2'] = '%' . $q . '%'; }
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 40)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$pTable` WHERE $where");
    $stmt->execute($queryParams);
    $total = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT id, name, sku, category, visible, image_url FROM `$pTable` WHERE $where ORDER BY visible DESC, name ASC LIMIT $limit OFFSET " . (($page - 1) * $limit));
    $stmt->execute($queryParams);
    $products = array_map(static function ($row) use ($tried, $webFixed) {
        $first = trim(explode('|', (string)$row['image_url'])[0] ?? '');
        return [
            'id' => $row['id'], 'name' => $row['name'], 'sku' => $row['sku'], 'category' => $row['category'],
            'visible' => (int)$row['visible'] === 1,
            'image' => smartispIsLocalProductUpload($first) ? $first : '',
            'original' => smartispIsExternalImage($first) ? $first : '',
            'autoTried' => isset($tried[$row['id']]),
            'fromWeb' => isset($webFixed[$row['id']]),
            'query' => smartispPhotoWebQuery($row),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['counts' => $counts, 'status' => $status, 'products' => $products, 'total' => $total, 'page' => $page, 'totalPages' => max(1, (int)ceil($total / $limit)), 'webSearch' => smartispPhotoWebSearchEnabled()]);
    exit;
}

if ($action === 'admin-photo-candidates') {
    @set_time_limit(40);
    $id = trim((string)($_GET['id'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));
    $product = ['name' => $q, 'sku' => '', 'image_url' => ''];
    if ($id !== '') {
        $stmt = $pdo->prepare("SELECT id, name, sku, image_url FROM `$pTable` WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$product) { http_response_code(404); echo json_encode(['error' => 'Producto no encontrado.']); exit; }
    }
    echo json_encode(['candidates' => smartispPhotoCandidates($product, ($_GET['web'] ?? '1') !== '0', $q !== '' ? $q : null), 'webSearch' => smartispPhotoWebSearchEnabled()]);
    exit;
}

if ($action === 'admin-photo-replace') {
    smartispRequirePost();
    @set_time_limit(40);
    $id = trim((string)($body['id'] ?? ''));
    $url = trim((string)($body['url'] ?? ''));
    $stmt = $pdo->prepare("SELECT id FROM `$pTable` WHERE id = :id");
    $stmt->execute([':id' => $id]);
    if ($id === '' || !$stmt->fetchColumn()) { http_response_code(404); echo json_encode(['error' => 'Producto no encontrado.']); exit; }
    $localUrl = smartispIsLocalProductUpload($url) ? $url : (preg_match('#^https?://#i', $url) ? smartispArchiveImageUrl($url) : null);
    if ($localUrl === null) {
        http_response_code(422);
        echo json_encode(['error' => 'No se pudo descargar una imagen válida desde esa dirección. Prueba con otra o sube el archivo.']);
        exit;
    }
    smartispPhotoSetLocal($pdo, $pTable, $id, $localUrl);
    smartispPhotoForget($pdo, SMARTISP_PHOTO_WEB_FIXED_KEY, [$id]);
    echo json_encode(['ok' => true, 'url' => $localUrl]);
    exit;
}

// Subida en lote: cada archivo se asigna al producto cuyo SKU (o id) coincide con su nombre. Los proveedores
// nombran las fotos «SKU_1_1.jpg», así que también se prueba sin ese sufijo.
if ($action === 'admin-photo-match') {
    smartispRequirePost();
    $names = array_slice(array_values(array_filter(array_map('strval', (array)($body['names'] ?? [])), 'strlen')), 0, 1000);
    $norm = static fn(string $s): string => preg_replace('/[^A-Z0-9]/', '', strtoupper($s));
    $bySku = [];
    $ids = [];
    foreach ($pdo->query("SELECT id, name, sku FROM `$pTable`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ids[(string)$row['id']] = $row;
        $key = $norm((string)$row['sku']);
        if (strlen($key) >= 4) $bySku[$key][] = $row;
    }
    $matches = [];
    foreach ($names as $name) {
        $base = preg_replace('/\.[a-z0-9]{3,4}$/i', '', trim(basename($name)));
        $found = null;
        foreach (array_unique([$base, preg_replace('/(?:[_-]\d{1,2}){1,2}$|\s*\(\d+\)$/', '', $base)]) as $candidate) {
            if (isset($ids[$candidate])) { $found = [$ids[$candidate]]; break; }
            if (isset($bySku[$norm($candidate)])) { $found = $bySku[$norm($candidate)]; break; }
        }
        $matches[] = $found
            ? ['name' => $name, 'ids' => array_column($found, 'id'), 'product' => $found[0]['name'], 'sku' => $found[0]['sku']]
            : ['name' => $name, 'ids' => []];
    }
    echo json_encode(['matches' => $matches]);
    exit;
}

// Fotos puestas desde la web que el equipo ya revisó: salen de la lista «Desde la web».
if ($action === 'admin-photo-ok') {
    smartispRequirePost();
    $ids = array_values(array_filter(array_map('strval', (array)($body['ids'] ?? [])), 'strlen'));
    smartispPhotoForget($pdo, SMARTISP_PHOTO_WEB_FIXED_KEY, $ids);
    echo json_encode(['ok' => true, 'approved' => count($ids)]);
    exit;
}

if ($action === 'admin-photo-auto') {
    smartispRequirePost();
    @set_time_limit(90);
    $web = !empty($body['web']);
    if ($web && !smartispPhotoWebSearchEnabled()) {
        http_response_code(400);
        echo json_encode(['error' => 'Falta la clave de búsqueda web (SMARTISP_BRAVE_SEARCH_KEY en el .env del servidor).']);
        exit;
    }
    smartispArchivePruneFailed($pdo, $pTable, smartispArchiveFailed($pdo));
    $triedKey = $web ? SMARTISP_PHOTO_WEB_TRIED_KEY : SMARTISP_PHOTO_AUTO_TRIED_KEY;
    $tried = smartispPhotoMap($pdo, $triedKey);
    if (!empty($body['retry'])) $tried = [];
    $pendingWhere = static function (array &$params) use ($pdo, $photoIdsIn, $external, $noPhoto, &$tried): string {
        $in = $photoIdsIn(array_keys(smartispArchiveFailed($pdo)), 'f', $params);
        $notTried = $photoIdsIn(array_keys($tried), 't', $params);
        return "(($external AND id IN ($in)) OR $noPhoto) AND id NOT IN ($notTried)";
    };
    $params = [];
    $where = $pendingWhere($params);
    $limit = max(1, min(10, (int)($body['limit'] ?? 6)));
    $stmt = $pdo->prepare("SELECT id, name, sku, image_url FROM `$pTable` WHERE $where ORDER BY visible DESC, id ASC LIMIT $limit");
    $stmt->execute($params);
    $started = microtime(true);
    $fixed = [];
    $checked = 0;
    $webFixed = $web ? smartispPhotoMap($pdo, SMARTISP_PHOTO_WEB_FIXED_KEY) : [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
        if (microtime(true) - $started > 45) break;
        // El plan gratuito de Brave Search admite una consulta por segundo.
        if ($web && $checked > 0) usleep(1100000);
        $checked++;
        $tried[$product['id']] = time();
        $candidates = $web ? array_slice(smartispWebImageSearch(smartispPhotoWebQuery($product), 8), 0, 4) : smartispPhotoCandidates($product, false, null, true);
        foreach ($candidates as $candidate) {
            $localUrl = smartispArchiveImageUrl($candidate['url']);
            if ($localUrl === null) continue;
            smartispPhotoSetLocal($pdo, $pTable, (string)$product['id'], $localUrl);
            if ($web) $webFixed[$product['id']] = $localUrl;
            $fixed[] = ['id' => $product['id'], 'name' => $product['name'], 'source' => $candidate['source'], 'url' => $localUrl];
            break;
        }
    }
    smartispPhotoSaveMap($pdo, $triedKey, $tried);
    if ($web) smartispPhotoSaveMap($pdo, SMARTISP_PHOTO_WEB_FIXED_KEY, $webFixed);
    $params = [];
    $count = $pdo->prepare("SELECT COUNT(*) FROM `$pTable` WHERE " . $pendingWhere($params));
    $count->execute($params);
    echo json_encode(['fixed' => $fixed, 'checked' => $checked, 'remaining' => (int)$count->fetchColumn()]);
    exit;
}
