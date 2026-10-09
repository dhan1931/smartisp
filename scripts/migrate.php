<?php
/**
 * Runner de migraciones (DEV-20261005-023). Aplica, en orden, los .sql de migrations/ que
 * todavía no estén registrados en schema_migrations.
 *
 *   php scripts/migrate.php            aplica las pendientes
 *   php scripts/migrate.php --dry-run  solo muestra cuales se aplicarian, sin tocar la base
 *   php scripts/migrate.php --status   lista aplicadas/pendientes
 *
 * Requiere las mismas variables de entorno que api/config.php (MYSQL_HOST, etc. o .env).
 */
declare(strict_types=1);

require __DIR__ . '/../api/db.php';

$dryRun = in_array('--dry-run', $argv, true);
$statusOnly = in_array('--status', $argv, true);

$pdo = getDbConnection();
if (!$pdo) {
    fwrite(STDERR, "No se pudo conectar a la base de datos. Revisa MYSQL_HOST/MYSQL_DATABASE/MYSQL_USER/MYSQL_PASSWORD (.env).\n");
    exit(1);
}

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(191) NOT NULL UNIQUE,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$applied = $pdo->query("SELECT migration FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
$appliedSet = array_flip($applied);

$dir = __DIR__ . '/../migrations';
$files = glob($dir . '/*.sql');
sort($files, SORT_STRING);

if ($statusOnly) {
    foreach ($files as $file) {
        $name = basename($file);
        echo (isset($appliedSet[$name]) ? '[aplicada]  ' : '[pendiente] ') . $name . "\n";
    }
    exit(0);
}

$pending = array_filter($files, fn($f) => !isset($appliedSet[basename($f)]));

if (empty($pending)) {
    echo "Sin migraciones pendientes (" . count($applied) . " ya aplicadas).\n";
    exit(0);
}

foreach ($pending as $file) {
    $name = basename($file);
    echo ($dryRun ? "[dry-run] se aplicaria: " : "aplicando: ") . $name . "\n";
    if ($dryRun) continue;

    $sql = file_get_contents($file);
    // Quita las LINEAS de comentario antes de dividir (no el bloque completo despues: una
    // sentencia que empieza justo despues de un comentario, sin ';' entre medio, quedaba en el
    // mismo trozo que el comentario y se descartaba entera por error en la version anterior).
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    // Divide por ';' al final de linea: suficiente para los DDL simples de este proyecto (sin
    // triggers/procedimientos con ';' internos).
    $statements = array_filter(array_map('trim', preg_split('/;\s*(\r?\n|$)/', $sql)));
    $statements = array_values($statements);

    // Sin transacción real: en MySQL/MariaDB cada DDL (ALTER/CREATE) hace commit implícito, así
    // que envolverlo en beginTransaction()/rollBack() no protege nada y además oculta el error
    // real (PDO lanza "no hay transacción activa" al intentar el rollback). Si una migración
    // falla a mitad de camino, puede quedar parcialmente aplicada; por eso cada ALTER de estas
    // migraciones se escribió para poder reintentarse sin romper nada (MODIFY COLUMN es
    // idempotente; ADD INDEX/PRIMARY KEY no, ver nota en el catch).
    // Codigos de MySQL/MariaDB que significan "esto que se pedia ya existe" (reintentar una
    // migracion parcialmente aplicada, p. ej. porque una sentencia posterior fallo la vez
    // anterior) y no un error real: 1068 PK duplicada, 1061 nombre de indice duplicado,
    // 1050 la tabla ya existe, 1091 no existe lo que se queria borrar/quitar.
    $benignCodes = ['1068', '1061', '1050', '1060', '1091'];
    try {
        foreach ($statements as $i => $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                $code = $e->errorInfo[1] ?? null;
                if ($code !== null && in_array((string)$code, $benignCodes, true)) {
                    echo "  (ya aplicado, se omite) sentencia " . ($i + 1) . ": " . $e->getMessage() . "\n";
                    continue;
                }
                fwrite(STDERR, "  ERROR en $name, sentencia " . ($i + 1) . ":\n");
                fwrite(STDERR, "  " . trim(preg_replace('/\s+/', ' ', $stmt)) . "\n");
                fwrite(STDERR, "  " . $e->getMessage() . "\n");
                throw $e;
            }
        }
        $ins = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:m)');
        $ins->execute([':m' => $name]);
        echo "  ok\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Migraciones posteriores no se intentaron.\n");
        exit(1);
    }
}

echo "Listo: " . count($pending) . " migracion(es) aplicada(s).\n";
