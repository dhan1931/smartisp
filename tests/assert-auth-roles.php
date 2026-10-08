<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/roles.php';

$cases = [
    'admin' => 'admin',
    ' ADMINISTRATOR ' => 'admin',
    'Administrador' => 'admin',
    'customer' => 'customer',
    'Cliente' => 'customer',
    'superadmin' => 'customer',
    '' => 'customer',
];

foreach ($cases as $input => $expected) {
    $actual = smartispNormalizeRole($input);
    if ($actual !== $expected) {
        throw new RuntimeException("Rol '$input': se esperaba '$expected' y llegó '$actual'.");
    }
}

echo "Roles normalizados con política de mínimo privilegio.\n";
