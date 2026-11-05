<?php
// Fotos de producto: detectar las rotas o faltantes y reemplazarlas por copias guardadas en el servidor.
if (!in_array($action ?? '', ['admin-photos', 'admin-photo-candidates', 'admin-photo-replace', 'admin-photo-auto'], true)) return;

requireAdminAuth();
require_once __DIR__ . '/../lib/photo-sources.php';
$pTable = getProductsTableName($pdo);

$photoIdsIn = static function (array $ids, string $prefix, array &$params): string {
    if (!$ids) return "''";
    $keys = [];
    foreach (array_values($ids) as $i => $id) { $keys[] = ":$prefix$i"; $params[":$prefix$i"] = (string)$id; }
    return implode(', ', $keys);
};
$external = "(image_url LIKE 'http://%' OR image_url LIKE 'https://%')";
$noPhoto = "(image_url IS NULL OR TRIM(image_url) = '')";

if ($action === 'admin-photos') {
    $failedIds = array_keys(smartispArchiveFailed($pdo));
    $tried = smartispPhotoMap($pdo, SMARTISP_PHOTO_AUTO_TRIED_KEY);
    $params = [];
    $in = $photoIdsIn($failedIds, 'f', $params);
    $filters = [
        'rotas' => "($external AND id IN ($in))",
        'sin-foto' => $noPhoto,
        'por-revisar' => "($external AND id NOT IN ($in))",
        'propias' => "(image_url LIKE '/api/auth/serve-upload%')",
    ];
    $counts = [];
    foreach ($filters as $key => $where) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$pTable` WHERE $where");
        $stmt->execute(str_contains($where, ':f') ? $params : []);
        $counts[$key] = (int)$stmt->fetchColumn();
    }
    $status = isset($filters[$_GET['status'] ?? '']) ? $_GET['status'] : 'rotas';
    $where = $filters[$status];
    $queryParams = str_contains($where, ':f') ? $params : [];
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') { $where .= ' AND (name LIKE :q1 OR sku LIKE :q2)'; $queryParams[':q1'] = $queryParams[':q2'] = '%' . $q . '%'; }
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 40)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$pTable` WHERE $where");
    $stmt->execute($queryParams);
    $total = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT id, name, sku, category, visible, image_url FROM `$pTable` WHERE $where ORDER BY visible DESC, name ASC LIMIT $limit OFFSET " . (($page - 1) * $limit));
    $stmt->execute($queryParams);
    $products = array_map(static function ($row) use ($tried) {
        $first = trim(explode('|', (string)$row['image_url'])[0] ?? '');
        return [
            'id' => $row['id'], 'name' => $row['name'], 'sku' => $row['sku'], 'category' => $row['category'],
            'visible' => (int)$row['visible'] === 1,
            'image' => smartispIsLocalProductUpload($first) ? $first : '',
            'original' => smartispIsExternalImage($first) ? $first : '',
            'autoTried' => isset($tried[$row['id']]),
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
    $local = smartispIsLocalProductUpload($url) ? $url : (preg_match('#^https?://#i', $url) ? smartispArchiveImageUrl($url) : null);
    if ($local === null) {
        http_response_code(422);
        echo json_encode(['error' => 'No se pudo descargar una imagen válida desde esa dirección. Prueba con otra o sube el archivo.']);
        exit;
    }
    smartispPhotoSetLocal($pdo, $pTable, $id, $local);
    echo json_encode(['ok' => true, 'url' => $local]);
    exit;
}

if ($action === 'admin-photo-auto') {
    smartispRequirePost();
    @set_time_limit(90);
    $tried = smartispPhotoMap($pdo, SMARTISP_PHOTO_AUTO_TRIED_KEY);
    if (!empty($body['retry'])) $tried = [];
    $failedIds = array_keys(smartispArchiveFailed($pdo));
    $params = [];
    $in = $photoIdsIn($failedIds, 'f', $params);
    $notTried = $photoIdsIn(array_keys($tried), 't', $params);
    $where = "(($external AND id IN ($in)) OR $noPhoto) AND id NOT IN ($notTried)";
    $limit = max(1, min(10, (int)($body['limit'] ?? 6)));
    $stmt = $pdo->prepare("SELECT id, name, sku, image_url FROM `$pTable` WHERE $where ORDER BY visible DESC, id ASC LIMIT $limit");
    $stmt->execute($params);
    $started = microtime(true);
    $fixed = [];
    $checked = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
        if (microtime(true) - $started > 45) break;
        $checked++;
        $tried[$product['id']] = time();
        foreach (smartispPhotoCandidates($product, false, null, true) as $candidate) {
            $local = smartispArchiveImageUrl($candidate['url']);
            if ($local === null) continue;
            smartispPhotoSetLocal($pdo, $pTable, (string)$product['id'], $local);
            $fixed[] = ['id' => $product['id'], 'name' => $product['name'], 'source' => $candidate['source'], 'url' => $local];
            break;
        }
    }
    smartispPhotoSaveMap($pdo, SMARTISP_PHOTO_AUTO_TRIED_KEY, $tried);
    $params = [];
    $in = $photoIdsIn(array_keys(smartispArchiveFailed($pdo)), 'f', $params);
    $notTried = $photoIdsIn(array_keys($tried), 't', $params);
    $count = $pdo->prepare("SELECT COUNT(*) FROM `$pTable` WHERE (($external AND id IN ($in)) OR $noPhoto) AND id NOT IN ($notTried)");
    $count->execute($params);
    echo json_encode(['fixed' => $fixed, 'checked' => $checked, 'remaining' => (int)$count->fetchColumn()]);
    exit;
}
