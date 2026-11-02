<?php
/**
 * Copia al servidor las fotos de proveedor que aún apuntan a otro sitio. Pensado para el cron de Hostinger:
 *
 *   php ~/public_html/api/cron/archivar-fotos.php                 diario: las pendientes
 *   php ~/public_html/api/cron/archivar-fotos.php --reintentar    semanal: también las que fallaron antes
 *
 * --minutos=N limita la duración (1–60, por defecto 10). Por HTTP no responde: .htaccess bloquea api/*.php.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/image-archive.php';

$pdo = getDbConnection();
if (!$pdo) {
    fwrite(STDERR, "No se pudo conectar a la base de datos (revisa .env).\n");
    exit(1);
}
$pTable = getProductsTableName($pdo);
$minutes = 10;
foreach ($argv as $arg) {
    if (preg_match('/^--minutos=(\d+)$/', $arg, $m)) $minutes = max(1, min(60, (int)$m[1]));
}
if (in_array('--reintentar', $argv, true)) smartispArchiveSaveFailed($pdo, []);

@set_time_limit(0);
$deadline = microtime(true) + $minutes * 60;
$saved = 0;
$failed = 0;
do {
    $res = smartispArchiveBatch($pdo, $pTable, 25, 60.0);
    $saved += $res['saved'];
    $failed += count($res['failed']);
} while ($res['pending'] > 0 && ($res['saved'] || $res['failed']) && microtime(true) < $deadline);

echo date('Y-m-d H:i:s') . " Guardadas $saved · sin respuesta del proveedor $failed · pendientes {$res['pending']} · ya en el servidor {$res['local']}\n";
