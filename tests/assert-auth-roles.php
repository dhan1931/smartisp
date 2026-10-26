<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/lib/roles.php';

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

if (!smartispIsFullAdmin('Administrator') || smartispIsFullAdmin('customer') || smartispIsFullAdmin('superadmin')) {
    throw new RuntimeException('Solo un rol admin canónico puede gestionar el equipo.');
}
if (smartispRoleRank('admin') <= smartispRoleRank('cliente')) {
    throw new RuntimeException('La jerarquía de roles debe poner al admin por encima del cliente.');
}
foreach (SMARTISP_TEAM_ROLES as $role) {
    if (!isset(SMARTISP_ROLE_RANK[$role]) || !in_array($role, smartispRoleAliases($role), true)) {
        throw new RuntimeException("El rol de equipo '$role' no está definido en la jerarquía.");
    }
}

echo "Roles normalizados con política de mínimo privilegio.\n";
