<?php
declare(strict_types=1);

function smartispNormalizeRole($role): string {
    $value = strtolower(trim((string)$role));
    if (in_array($value, ['admin', 'administrator', 'administrador'], true)) return 'admin';
    if (in_array($value, ['customer', 'client', 'cliente', 'user', 'usuario', ''], true)) return 'customer';
    return 'customer';
}
