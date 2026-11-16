<?php
// Reseñas de productos (migración 050): resumen, lista pública, compra verificada y promedio en el producto.

const SMARTISP_REVIEW_STATUSES = ['pending', 'published', 'hidden'];
// Pedidos que cuentan como compra: pagados o en camino; no cotizaciones, pendientes ni cancelados.
const SMARTISP_REVIEW_BOUGHT_STATUSES = ['paid', 'preparing', 'shipped', 'delivered'];

function smartispReviewsReady(PDO $pdo): bool {
    static $ready = null;
    return $ready ??= smartispTableExists($pdo, 'product_reviews');
}

// "Juan Pérez" -> "Juan P.": nunca se publica el apellido completo ni el correo.
function smartispReviewAuthorName(array $user): string {
    $first = trim(preg_split('/\s+/u', trim((string)($user['name'] ?? '')))[0] ?? '');
    $surname = trim((string)($user['surname'] ?? ''));
    if ($surname === '') {
        $parts = preg_split('/\s+/u', trim((string)($user['name'] ?? ''))) ?: [];
        $surname = count($parts) > 1 ? (string)end($parts) : '';
    }
    if ($first === '') return 'Cliente';
    return mb_substr($first, 0, 40) . ($surname !== '' ? ' ' . mb_strtoupper(mb_substr($surname, 0, 1)) . '.' : '');
}

function smartispReviewVerified(PDO $pdo, string $userId, string $productId): bool {
    if ($userId === '' || $productId === '') return false;
    $cols = smartispTableColumns($pdo, 'orders_rows');
    if (!in_array('user_id', $cols, true) || !in_array('items', $cols, true)) return false;
    $bought = "LOWER(COALESCE(status, '')) IN ('" . implode("','", SMARTISP_REVIEW_BOUGHT_STATUSES) . "')";
    if (in_array('payment_status', $cols, true)) {
        $bought = "($bought OR (LOWER(COALESCE(payment_status, '')) IN ('" . implode("','", SMARTISP_CONFIRMED_PAYMENTS) . "') AND LOWER(COALESCE(status, '')) NOT IN ('" . implode("','", SMARTISP_VOID_STATUSES) . "')))";
    }
    $stmt = $pdo->prepare("SELECT items FROM orders_rows WHERE user_id = :uid AND $bought AND items LIKE :pid LIMIT 20");
    $stmt->execute([':uid' => $userId, ':pid' => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $productId) . '%']);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        foreach ((array)json_decode((string)$raw, true) as $item) {
            if (is_array($item) && in_array($productId, [(string)($item['id'] ?? ''), (string)($item['productId'] ?? '')], true)) return true;
        }
    }
    return false;
}

function smartispReviewSummary(PDO $pdo, string $productId): array {
    $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    $stmt = $pdo->prepare("SELECT rating, COUNT(*) AS n FROM product_reviews WHERE product_id = :pid AND status = 'published' GROUP BY rating");
    $stmt->execute([':pid' => $productId]);
    $sum = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $r = max(1, min(5, (int)$row['rating']));
        $dist[$r] += (int)$row['n'];
        $sum += $r * (int)$row['n'];
    }
    $count = array_sum($dist);
    return ['avg' => $count ? round($sum / $count, 1) : 0, 'count' => $count, 'dist' => $dist];
}

function smartispReviewPublic(array $row): array {
    return [
        'id' => (int)$row['id'], 'author' => (string)$row['author_name'] ?: 'Cliente', 'rating' => (int)$row['rating'],
        'title' => (string)$row['title'], 'body' => (string)$row['body'], 'verified' => (int)$row['verified'] === 1,
        'date' => (string)$row['created_at'], 'reply' => trim((string)($row['reply'] ?? '')), 'replyDate' => (string)($row['replied_at'] ?? ''),
    ];
}

function smartispReviewList(PDO $pdo, string $productId, int $limit, int $offset = 0): array {
    $stmt = $pdo->prepare("SELECT * FROM product_reviews WHERE product_id = :pid AND status = 'published' ORDER BY verified DESC, created_at DESC, id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset));
    $stmt->execute([':pid' => $productId]);
    return array_map('smartispReviewPublic', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

// Promedio y cantidad de reseñas publicadas guardados en el producto (catálogo y tarjetas).
function smartispReviewRecalc(PDO $pdo, string $pTable, string $productId): void {
    $cols = smartispTableColumns($pdo, $pTable);
    if (!in_array('rating_avg', $cols, true) || !in_array('rating_count', $cols, true)) return;
    $summary = smartispReviewSummary($pdo, $productId);
    $pdo->prepare("UPDATE `$pTable` SET rating_avg = :avg, rating_count = :n WHERE id = :id")
        ->execute([':avg' => $summary['count'] ? round(array_sum(array_map(static fn($r, $n) => $r * $n, array_keys($summary['dist']), $summary['dist'])) / $summary['count'], 2) : 0, ':n' => $summary['count'], ':id' => $productId]);
}
