<?php
// Precios: dos incrementos consecutivos del 15 % (× 1,3225), sin redondeos intermedios.
require __DIR__ . '/../api/lib/shop.php';

$cases = [
    [100, 132.25],
    [10, 13.23],
    [1, 1.32],
    [0.99, 1.31],
    [57.31, 75.79],
    [1234.56, 1632.71],
    [0, 0.0],
    [-5, 0.0],
];
$failed = 0;
foreach ($cases as [$cost, $expected]) {
    $got = smartispPriceFromCost((float)$cost);
    if (abs($got - $expected) > 0.0001) { $failed++; fwrite(STDERR, "FALLO costo $cost: esperado $expected, obtenido $got\n"); }
}
if (smartispPriceFromCost(100) === round(100 * 1.30, 2)) { $failed++; fwrite(STDERR, "FALLO: se aplicó un 30 % directo\n"); }
if (smartispPriceFromCost(smartispPriceFromCost(100)) === smartispPriceFromCost(100)) { $failed++; fwrite(STDERR, "FALLO: aplicar dos veces no debe ser idempotente (se calcula siempre desde el costo)\n"); }
if ($failed) exit(1);
echo "pricing OK (" . count($cases) . " casos)\n";
