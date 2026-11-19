<?php
// Reseñas: lista pública y escritura del cliente con sesión; moderación en el panel.
if (!in_array($action ?? '', ['product-reviews', 'product-review', 'admin-reviews', 'admin-review-update'], true)) return;

require_once __DIR__ . '/../lib/reviews.php';
$pTable = getProductsTableName($pdo);
$reviewFail = static function (int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
};
$ready = smartispReviewsReady($pdo);

if ($action === 'product-reviews') {
    $pid = trim((string)($_GET['product'] ?? ''));
    $user = getAuthUser();
    smartispReleaseSession();
    $empty = ['avg' => 0, 'count' => 0, 'dist' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]];
    if (!$ready || $pid === '') { echo json_encode(['ready' => $ready, 'summary' => $empty, 'reviews' => [], 'total' => 0, 'page' => 1, 'totalPages' => 1, 'loggedIn' => (bool)$user, 'mine' => null, 'verifiedBuyer' => false]); exit; }
    $limit = 10;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $summary = smartispReviewSummary($pdo, $pid);
    $mine = null;
    if ($user) {
        $stmt = $pdo->prepare('SELECT * FROM product_reviews WHERE product_id = :pid AND user_id = :uid');
        $stmt->execute([':pid' => $pid, ':uid' => (string)$user['id']]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $mine = smartispReviewPublic($row) + ['status' => (string)$row['status']];
    }
    echo json_encode([
        'ready' => true, 'summary' => $summary, 'reviews' => smartispReviewList($pdo, $pid, $limit, ($page - 1) * $limit),
        'total' => $summary['count'], 'page' => $page, 'totalPages' => max(1, (int)ceil($summary['count'] / $limit)),
        'loggedIn' => (bool)$user, 'mine' => $mine, 'verifiedBuyer' => $user ? smartispReviewVerified($pdo, (string)$user['id'], $pid) : false,
    ]);
    exit;
}

if ($action === 'product-review') {
    smartispRequirePost();
    $user = getAuthUser();
    smartispReleaseSession();
    if (!$user) $reviewFail(401, 'Inicia sesión para escribir una reseña.');
    if (!$ready) $reviewFail(503, 'Las reseñas todavía no están activadas.');
    $uid = (string)$user['id'];
    $pid = trim((string)($body['product'] ?? ''));
    $stmt = $pdo->prepare("SELECT id, name FROM `$pTable` WHERE id = :id AND visible = 1");
    $stmt->execute([':id' => $pid]);
    $product = $pid !== '' ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!$product) $reviewFail(404, 'El producto no existe.');

    if (!empty($body['delete'])) {
        $pdo->prepare('DELETE FROM product_reviews WHERE product_id = :pid AND user_id = :uid')->execute([':pid' => $pid, ':uid' => $uid]);
        smartispReviewRecalc($pdo, $pTable, $pid);
        echo json_encode(['ok' => true, 'deleted' => true]);
        exit;
    }
    if (smartispRateLimited('reviews', 10, 3600, $uid)) $reviewFail(429, 'Enviaste muchas reseñas seguidas. Intenta de nuevo en un rato.');

    $clean = static fn($value, int $max): string => mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$value) ?? ''), 0, $max);
    $rating = (int)($body['rating'] ?? 0);
    $title = $clean($body['title'] ?? '', 120);
    $text = $clean($body['body'] ?? '', 4000);
    if ($rating < 1 || $rating > 5) $reviewFail(400, 'Elige de 1 a 5 estrellas.');
    if (mb_strlen($text) < 10) $reviewFail(400, 'Cuéntanos un poco más: escribe al menos 10 caracteres.');
    if (mb_strlen($text) > 2000) $reviewFail(400, 'La reseña puede tener hasta 2000 caracteres.');

    $users = getUsersTableName($pdo);
    $cols = smartispTableColumns($pdo, $users);
    $stmt = $pdo->prepare('SELECT name' . (in_array('surname', $cols, true) ? ', surname' : '') . " FROM `$users` WHERE id = :id");
    $stmt->execute([':id' => $uid]);
    $author = smartispReviewAuthorName($stmt->fetch(PDO::FETCH_ASSOC) ?: $user);
    $verified = smartispReviewVerified($pdo, $uid, $pid);
    // Solo se publica sola la reseña de quien compró el producto y sin enlaces; el resto pasa por el panel.
    $hasLink = (bool)preg_match('~https?://|www\.|\.(com|net|org|ec)\b~i', $title . ' ' . $text);
    $status = $verified && !$hasLink ? 'published' : 'pending';
    $pdo->prepare('INSERT INTO product_reviews (product_id, user_id, author_name, rating, title, body, verified, status)
        VALUES (:pid, :uid, :author, :rating, :title, :body, :verified, :status)
        ON DUPLICATE KEY UPDATE author_name = VALUES(author_name), rating = VALUES(rating), title = VALUES(title), body = VALUES(body),
            verified = VALUES(verified), status = VALUES(status), updated_at = CURRENT_TIMESTAMP')
        ->execute([':pid' => $pid, ':uid' => $uid, ':author' => $author, ':rating' => $rating, ':title' => $title, ':body' => $text, ':verified' => $verified ? 1 : 0, ':status' => $status]);
    smartispReviewRecalc($pdo, $pTable, $pid);
    echo json_encode(['ok' => true, 'status' => $status, 'verified' => $verified]);
    exit;
}

requireAdminAuth();
smartispReleaseSession();
if (!$ready) $reviewFail(409, 'Falta la tabla de reseñas: aplica la migración 050_resenas_productos.sql.');

if ($action === 'admin-reviews') {
    $counts = array_fill_keys(SMARTISP_REVIEW_STATUSES, 0);
    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM product_reviews GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($counts[$row['status']])) $counts[$row['status']] = (int)$row['n'];
    }
    $status = in_array($_GET['status'] ?? '', SMARTISP_REVIEW_STATUSES, true) ? $_GET['status'] : 'pending';
    $where = 'r.status = :status';
    $params = [':status' => $status];
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where .= ' AND (p.name LIKE :q1 OR r.body LIKE :q2 OR r.title LIKE :q3 OR r.author_name LIKE :q4)';
        $params[':q1'] = $params[':q2'] = $params[':q3'] = $params[':q4'] = '%' . $q . '%';
    }
    $limit = 30;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $users = getUsersTableName($pdo);
    $from = "FROM product_reviews r LEFT JOIN `$pTable` p ON p.id = r.product_id LEFT JOIN `$users` u ON u.id = r.user_id WHERE $where";
    $stmt = $pdo->prepare("SELECT COUNT(*) $from");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT r.*, p.name AS product_name, u.email AS user_email $from ORDER BY r.created_at DESC, r.id DESC LIMIT $limit OFFSET " . (($page - 1) * $limit));
    $stmt->execute($params);
    $reviews = array_map(static fn($row) => smartispReviewPublic($row) + [
        'status' => (string)$row['status'], 'productId' => (string)$row['product_id'], 'productName' => (string)($row['product_name'] ?? ''),
        'productUrl' => $row['product_name'] !== null ? smartispProductPath((string)$row['product_id'], (string)$row['product_name']) : '',
        'email' => (string)($row['user_email'] ?? ''), 'updated' => (string)($row['updated_at'] ?? ''),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['counts' => $counts, 'status' => $status, 'reviews' => $reviews, 'total' => $total, 'page' => $page, 'totalPages' => max(1, (int)ceil($total / $limit))]);
    exit;
}

// admin-review-update: publicar, ocultar, responder o eliminar.
smartispRequirePost();
$id = (int)($body['id'] ?? 0);
$op = (string)($body['op'] ?? '');
$stmt = $pdo->prepare('SELECT id, product_id FROM product_reviews WHERE id = :id');
$stmt->execute([':id' => $id]);
$review = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$review) $reviewFail(404, 'La reseña ya no existe.');
if ($op === 'publish' || $op === 'hide') {
    $pdo->prepare('UPDATE product_reviews SET status = :status WHERE id = :id')->execute([':status' => $op === 'publish' ? 'published' : 'hidden', ':id' => $id]);
} elseif ($op === 'reply') {
    $reply = mb_substr(trim((string)($body['reply'] ?? '')), 0, 1000);
    $pdo->prepare('UPDATE product_reviews SET reply = :reply, replied_at = ' . ($reply === '' ? 'NULL' : 'CURRENT_TIMESTAMP') . ' WHERE id = :id')->execute([':reply' => $reply === '' ? null : $reply, ':id' => $id]);
} elseif ($op === 'delete') {
    $pdo->prepare('DELETE FROM product_reviews WHERE id = :id')->execute([':id' => $id]);
} else {
    $reviewFail(400, 'Acción no válida.');
}
smartispReviewRecalc($pdo, $pTable, (string)$review['product_id']);
smartispAuditNote(['target' => 'reseña #' . $id, 'summary' => ['publish' => 'Reseña publicada', 'hide' => 'Reseña ocultada', 'reply' => 'Respuesta a la reseña', 'delete' => 'Reseña eliminada'][$op]]);
echo json_encode(['ok' => true]);
exit;
