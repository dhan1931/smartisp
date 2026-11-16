<?php
// Precios: 25 % de ganancia y después 15 % de IVA (× 1,4375), sin redondeos intermedios.
require __DIR__ . '/../api/lib/shop.php';

$cases = [
    [100, 143.75],
    [10, 14.38],
    [1, 1.44],
    [0.99, 1.42],
    [57.31, 82.38],
    [1234.56, 1774.68],
    [0, 0.0],
    [-5, 0.0],
];
$failed = 0;
foreach ($cases as [$cost, $expected]) {
    $got = smartispPriceFromCost((float)$cost);
    if (abs($got - $expected) > 0.0001) { $failed++; fwrite(STDERR, "FALLO costo $cost: esperado $expected, obtenido $got\n"); }
}
if (smartispPriceFromCost(100) === round(100 * 1.40, 2)) { $failed++; fwrite(STDERR, "FALLO: se aplicó un 40 % directo\n"); }
if (smartispPriceFromCost(smartispPriceFromCost(100)) === smartispPriceFromCost(100)) { $failed++; fwrite(STDERR, "FALLO: aplicar dos veces no debe ser idempotente (se calcula siempre desde el costo)\n"); }
if ($failed) exit(1);
echo "pricing OK (" . count($cases) . " casos)\n";
