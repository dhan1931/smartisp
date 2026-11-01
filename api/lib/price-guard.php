<?php
// Precios que casi seguro son un error de carga: por debajo del costo, o una fracción mínima del precio
// típico de su categoría (un portátil a $1,46 porque el importador leyó «1,460.00» como 1,46).
const SMARTISP_PRICE_GUARD_MIN_SAMPLE = 8;
const SMARTISP_PRICE_GUARD_CATEGORY_RATIO = 0.05;
const SMARTISP_PRICE_GUARD_EXPENSIVE_MEDIAN = 50;
// Al importar, un cambio fuera de este rango respecto del precio anterior no se aplica solo.
const SMARTISP_PRICE_GUARD_DROP = 0.3;
const SMARTISP_PRICE_GUARD_JUMP = 4;

// [id => motivo] de los productos visibles con precio sospechoso.
function smartispSuspiciousPrices(PDO $pdo, string $pTable): array {
    $hasCost = in_array('cost', smartispTableColumns($pdo, $pTable), true);
    $rows = $pdo->query("SELECT id, category, price + 0 AS price" . ($hasCost ? ', cost + 0 AS cost' : ', NULL AS cost') . " FROM `$pTable` WHERE visible = 1 AND price + 0 > 0")->fetchAll(PDO::FETCH_ASSOC);
    $byCategory = [];
    foreach ($rows as $row) $byCategory[(string)$row['category']][] = (float)$row['price'];
    $medians = [];
    foreach ($byCategory as $category => $prices) {
        if (count($prices) < SMARTISP_PRICE_GUARD_MIN_SAMPLE) continue;
        sort($prices);
        $n = count($prices);
        $medians[$category] = $n % 2 ? $prices[intdiv($n, 2)] : ($prices[$n / 2 - 1] + $prices[$n / 2]) / 2;
    }
    $out = [];
    foreach ($rows as $row) {
        $price = (float)$row['price'];
        $cost = (float)($row['cost'] ?? 0);
        $median = $medians[(string)$row['category']] ?? 0;
        if ($cost > 0 && $price < $cost) $out[$row['id']] = 'Precio menor que el costo';
        elseif ($median >= SMARTISP_PRICE_GUARD_EXPENSIVE_MEDIAN && $price < $median * SMARTISP_PRICE_GUARD_CATEGORY_RATIO) $out[$row['id']] = sprintf('Muy por debajo de su categoría (típico $%s)', number_format($median, 2, '.', ''));
    }
    return $out;
}

function smartispPriceJumpIsSuspicious(float $old, float $new): bool {
    return $old > 0 && $new > 0 && ($new < $old * SMARTISP_PRICE_GUARD_DROP || $new > $old * SMARTISP_PRICE_GUARD_JUMP);
}
