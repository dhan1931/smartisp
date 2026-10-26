<?php
declare(strict_types=1);

// Rol canónico => alias guardados en users_rows.role. Lo que no esté aquí cuenta como cliente.
const SMARTISP_ROLE_ALIASES = [
    'admin' => ['admin', 'administrator', 'administrador'],
    'customer' => ['customer', 'client', 'cliente', 'user', 'usuario', ''],
];

// Jerarquía para impedir que alguien asigne un rol por encima del suyo.
const SMARTISP_ROLE_RANK = ['customer' => 0, 'admin' => 100];

// Roles que forman el equipo del panel (pantalla Equipo).
const SMARTISP_TEAM_ROLES = ['admin'];

const SMARTISP_ROLE_LABELS = ['admin' => 'Administrador', 'customer' => 'Cliente'];

function smartispNormalizeRole($role): string {
    $value = strtolower(trim((string)$role));
    foreach (SMARTISP_ROLE_ALIASES as $canonical => $aliases) {
        if (in_array($value, $aliases, true)) return $canonical;
    }
    return 'customer';
}

function smartispRoleRank($role): int {
    return SMARTISP_ROLE_RANK[smartispNormalizeRole($role)] ?? 0;
}

// Admin pleno: el único que gestiona el equipo.
function smartispIsFullAdmin($role): bool {
    return smartispRoleRank($role) >= SMARTISP_ROLE_RANK['admin'];
}

function smartispRoleAliases(string $canonical): array {
    return SMARTISP_ROLE_ALIASES[$canonical] ?? [];
}

function smartispRoleLabel($role): string {
    return SMARTISP_ROLE_LABELS[smartispNormalizeRole($role)] ?? 'Cliente';
}
