<?php
// Fotos de producto: detectar las rotas o faltantes y reemplazarlas por copias guardadas en el servidor.
if (!in_array($action ?? '', ['admin-photos', 'admin-photo-candidates', 'admin-photo-replace', 'admin-photo-auto', 'admin-photo-ok', 'admin-photo-match', 'admin-photo-import'], true)) return;

requireAdminAuth();
smartispReleaseSession();
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
$hasGallery = smartispTableExists($pdo, 'product_images');
$onePhoto = "(image_url IS NOT NULL AND TRIM(image_url) <> '' AND image_url NOT LIKE '%|%' AND image_url NOT LIKE 'data:%'" . ($hasGallery ? ' AND id NOT IN (SELECT product_id FROM product_images GROUP BY product_id HAVING COUNT(*) > 1)' : '') . ')';
$photoFile = static fn(string $url): string => 'file=arch_' . substr(md5($url), 0, 20) . '.';

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
        'una-foto' => "($onePhoto AND id NOT IN ($in))",
    ];
    $category = trim((string)($_GET['category'] ?? ''));
    $byCategory = '';
    if ($category !== '') { $byCategory = ' AND category = :cat'; $params[':cat'] = $category; }
    $counts = [];
    foreach ($filters as $key => $where) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$pTable` WHERE $where$byCategory");
        $stmt->execute($paramsFor($where . $byCategory, $params));
        $counts[$key] = (int)$stmt->fetchColumn();
    }
    $status = isset($filters[$_GET['status'] ?? '']) ? $_GET['status'] : 'rotas';
    $where = $filters[$status];
    $stmt = $pdo->prepare("SELECT category, COUNT(*) AS n FROM `$pTable` WHERE $where AND category <> '' GROUP BY category ORDER BY category");
    $stmt->execute($paramsFor($where, $params));
    $categories = array_map(static fn($row) => ['name' => (string)$row['category'], 'count' => (int)$row['n']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    $where .= $byCategory;
    $queryParams = $paramsFor($where, $params);
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') { $where .= ' AND (name LIKE :q1 OR sku LIKE :q2)'; $queryParams[':q1'] = $queryParams[':q2'] = '%' . $q . '%'; }
    // all=1: la lista completa para exportarla a Excel o CSV.
    $all = (string)($_GET['all'] ?? '') === '1';
    $limit = $all ? 5000 : max(1, min(100, (int)($_GET['limit'] ?? 40)));
    $page = $all ? 1 : max(1, (int)($_GET['page'] ?? 1));
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
            'photos' => count(smartispSplitImageUrls((string)$row['image_url'])),
            'image' => smartispIsLocalProductUpload($first) ? $first : '',
            'original' => smartispIsExternalImage($first) ? $first : '',
            'autoTried' => isset($tried[$row['id']]),
            'fromWeb' => isset($webFixed[$row['id']]),
            'query' => smartispPhotoWebQuery($row),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['counts' => $counts, 'status' => $status, 'categories' => $categories, 'products' => $products, 'total' => $total, 'page' => $page, 'totalPages' => max(1, (int)ceil($total / $limit)), 'webSearch' => smartispPhotoWebSearchEnabled()]);
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
    if (!empty($body['add'])) {
        $stmt = $pdo->prepare("SELECT image_url FROM `$pTable` WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $list = smartispSplitImageUrls((string)$stmt->fetchColumn());
        if (str_starts_with($list[0] ?? '', 'data:')) { http_response_code(422); echo json_encode(['error' => 'La foto principal está pegada dentro del producto: primero cámbiala por una guardada en tu servidor.']); exit; }
        if (in_array($localUrl, $list, true)) { http_response_code(409); echo json_encode(['error' => 'Esa foto ya está en el producto.']); exit; }
        smartispPhotoAppend($pdo, $pTable, $id, $list, [$localUrl], $hasGallery);
        echo json_encode(['ok' => true, 'url' => $localUrl, 'photos' => count($list) + 1]);
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

// Importar fotos desde enlaces (Excel o CSV): cada fila trae el id o el SKU y una o varias direcciones.
// Sin apply solo dice qué pasaría; con apply descarga y guarda en el servidor. Una foto que ya está en el
// servidor (elegida a mano o copiada antes) no se reemplaza salvo con overwrite. Con extra, las fotos se
// añaden detrás de las que ya tiene (sin repetir ninguna, 8 como máximo) y la principal no cambia.
if ($action === 'admin-photo-import') {
    smartispRequirePost();
    $apply = !empty($body['apply']);
    $overwrite = !empty($body['overwrite']);
    $extra = !empty($body['extra']);
    $rows = array_slice(array_values(array_filter((array)($body['rows'] ?? []), 'is_array')), 0, $apply ? 8 : 3000);
    if ($apply) @set_time_limit(90);
    $norm = static fn(string $s): string => preg_replace('/[^A-Z0-9]/', '', strtoupper($s));
    $byId = [];
    $bySku = [];
    foreach ($pdo->query("SELECT id, name, sku, image_url, visible FROM `$pTable`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byId[(string)$row['id']] = $row;
        $key = $norm((string)$row['sku']);
        if (strlen($key) >= 3) $bySku[$key][] = $row;
    }
    $failed = smartispArchivePruneFailed($pdo, $pTable, smartispArchiveFailed($pdo));
    $gallery = [];
    if ($extra && $hasGallery) {
        foreach ($pdo->query('SELECT product_id, image_url FROM product_images')->fetchAll(PDO::FETCH_ASSOC) as $g) $gallery[(string)$g['product_id']][] = (string)$g['image_url'];
    }
    $started = microtime(true);
    $downloaded = [];
    $results = [];
    foreach ($rows as $i => $row) {
        $key = trim((string)($row['key'] ?? ''));
        $urls = array_slice(array_values(array_unique(array_filter(array_map('trim', explode('|', (string)($row['urls'] ?? ''))), static fn($u) => (bool)preg_match('#^https?://\S+$#i', $u)))), 0, 4);
        $matches = isset($byId[$key]) ? [$byId[$key]] : ($key !== '' ? ($bySku[$norm($key)] ?? []) : []);
        $base = ['row' => $row['row'] ?? $i + 1, 'key' => $key, 'urls' => $urls];
        if (!$matches) { $results[] = $base + ['status' => 'not_found']; continue; }
        foreach ($matches as $product) {
            $id = (string)$product['id'];
            $current = trim(explode('|', (string)$product['image_url'])[0]);
            $status = !$urls ? 'bad_url'
                : ($current === '' ? 'missing'
                : (!preg_match('#^https?://#i', $current) ? 'own'
                : (isset($failed[$id]) ? 'broken' : 'external')));
            $list = smartispSplitImageUrls((string)$product['image_url']);
            $new = $urls;
            if ($extra && in_array($status, ['own', 'external'], true) && !str_starts_with($current, 'data:')) {
                $have = array_merge($list, $gallery[$id] ?? []);
                $new = array_values(array_filter($urls, static function (string $url) use ($have, $photoFile): bool {
                    foreach ($have as $h) if ($h === $url || str_contains($h, $photoFile($url))) return false;
                    return true;
                }));
                $room = 8 - max(count($list), count($gallery[$id] ?? []));
                $status = !$new ? 'duplicate' : ($room <= 0 ? 'full' : 'extra');
                $new = array_slice($new, 0, max(0, $room));
            }
            $result = array_merge($base, ['id' => $id, 'name' => (string)$product['name'], 'sku' => (string)$product['sku'], 'visible' => (int)$product['visible'] === 1, 'current' => $current, 'status' => $status, 'urls' => $new, 'photos' => count($list)]);
            $writable = in_array($status, ['missing', 'broken', 'external', 'extra'], true) || ($status === 'own' && $overwrite);
            if ($apply && $writable) {
                if (microtime(true) - $started > 45) { $results[] = ['status' => 'pending'] + $result; continue; }
                $saved = [];
                foreach ($new as $url) {
                    $downloaded[$url] ??= smartispArchiveImageUrl($url);
                    if ($downloaded[$url] !== null && !in_array($downloaded[$url], $saved, true)) $saved[] = $downloaded[$url];
                }
                if ($saved && $status === 'extra') {
                    smartispPhotoAppend($pdo, $pTable, $id, $list, $saved, isset($gallery[$id]));
                    $result = ['status' => 'saved', 'saved' => $saved] + $result;
                } elseif ($saved) {
                    smartispPhotoSetLocal($pdo, $pTable, $id, implode('|', $saved));
                    smartispPhotoForget($pdo, SMARTISP_PHOTO_WEB_FIXED_KEY, [$id]);
                    $result = ['status' => 'saved', 'saved' => $saved] + $result;
                } else {
                    $result = ['status' => 'download_failed'] + $result;
                }
            }
            $results[] = $result;
        }
    }
    echo json_encode(['results' => $results]);
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
