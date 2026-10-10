<?php
/**
 * Backfill de una sola vez (DEV-20261009): asigna macro_group_id a las categorias reales que
 * la migracion 017 no pudo mapear por coincidencia exacta de nombre, replicando en PHP la misma
 * logica de coincidencia por palabras clave que antes vivia hardcodeada en tienda.html
 * (MACRO_CATEGORIES / getMacroCategory). Después de correr esto una vez, la asignacion queda
 * guardada en categories_rows.macro_group_id -- admin la controla desde ahi en adelante, ya no
 * hay matching por texto en tiempo de ejecucion.
 *
 *   php scripts/backfill-macro-groups.php            aplica
 *   php scripts/backfill-macro-groups.php --dry-run  solo muestra que asignaria
 */
declare(strict_types=1);

require __DIR__ . '/../api/db.php';

$dryRun = in_array('--dry-run', $argv, true);

$pdo = getDbConnection();
if (!$pdo) {
    fwrite(STDERR, "No se pudo conectar a la base de datos.\n");
    exit(1);
}

$keywordsByGroup = [
    'computacion' => ['computador','computadores','notebook','notebooks','laptop','laptops','portatil','portatiles','desktop','escritorio','tableta','tabletas','tablet','all-in-one','workstation'],
    'componentes' => ['componente','componentes','disco','discos','ssd','hdd','nvme','ram','memoria','memorias','procesador','procesadores','tarjeta madre','placa','motherboard','gpu','almacenamiento'],
    'redes' => ['red','redes','comunicaciones','router','switch','cables','cable','adaptador','adaptadores','wifi','access point','fibra','patch cord','conector','mikrotik','ubiquiti'],
    'monitores' => ['monitor','monitores','pantalla','pantallas','tv','televisor','televisores','display','proyector','proyectores'],
    'perifericos' => ['periferico','perifericos','teclado','teclados','mouse','raton','audio','auricular','auriculares','audifono','audifonos','parlante','parlantes','mochila','mochilas','soporte','soportes','accesorio','accesorios','funda','maletin','webcam'],
    'impresion' => ['impresora','impresoras','multifuncional','multifuncionales','toner','cartucho','cartuchos','tinta','consumible','consumibles','pos','punto de venta','puntos de venta','codigo de barras','escaner','escaneres'],
    'seguridad' => ['seguridad','vigilancia','camara','camaras','cctv','dvr','nvr','alarma','alarmas','biometrico','sensor','sensores','control de acceso','intrusion'],
    'energia' => ['energia','ups','bateria','baterias','regulador','reguladores','inversor','inversores','proteccion de poder','clima','climatizacion','ventilacion','ventilador','aire'],
    'telefonia' => ['celular','celulares','smartphone','smartphones','telefono','telefonos','reloj','smartwatch','vestible','vestibles','wearable'],
    'gaming' => ['gaming','videojuegos','videojuego','consola','consolas','playstation','xbox','nintendo','silla gaming','sillas','linea blanca','electrodomesticos','electrodomestico','hogar'],
    'software' => ['software','licencia','licencias','antivirus','sistema operativo','office','windows'],
];

function normalizeMacroText(string $text): string {
    // iconv('UTF-8','ASCII//TRANSLIT', ...) es inconsistente entre builds/locales de PHP (en
    // este entorno deja apostrofes en vez de quitar el acento limpio, p. ej. "c'amaras" en vez
    // de "camaras"), asi que se reemplazan los acentos de forma explicita.
    $map = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ñ'=>'n','Ü'=>'u'];
    return trim(mb_strtolower(strtr($text, $map), 'UTF-8'));
}

// Overrides manuales para nombres reales donde el matching generico por palabra clave
// elegiria mal (p. ej. "Accesorios para Computadores" matchea "computador" y cae en
// computacion, pero por significado es un accesorio) o no encuentra nada util.
$manualOverrides = [
    'Accesorios para Computadores' => 'perifericos',
    'Networking' => 'redes',
    'Servidores' => 'computacion',
];

$rows = $pdo->query("SELECT id, name FROM categories_rows WHERE macro_group_id IS NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$update = $pdo->prepare('UPDATE categories_rows SET macro_group_id = :group WHERE id = :id');
$assigned = 0;
$unmatched = [];

foreach ($rows as $row) {
    $norm = normalizeMacroText((string)$row['name']);
    $match = $manualOverrides[$row['name']] ?? null;

    // 1. Coincidencia exacta con alguna palabra clave
    if ($match === null) foreach ($keywordsByGroup as $groupId => $keywords) {
        foreach ($keywords as $kw) {
            if ($norm === normalizeMacroText($kw)) { $match = $groupId; break 2; }
        }
    }
    // 2. Subcadena / inclusion (misma logica de respaldo que tenia el JS)
    if ($match === null) {
        foreach ($keywordsByGroup as $groupId => $keywords) {
            foreach ($keywords as $kw) {
                $normKw = normalizeMacroText($kw);
                if (str_contains($norm, $normKw) || str_contains($normKw, $norm)) { $match = $groupId; break 2; }
            }
        }
    }

    $groupId = $match ?? 'otros';
    if ($match === null) $unmatched[] = $row['name'];

    echo ($dryRun ? "[dry-run] " : "") . $row['name'] . " -> " . $groupId . ($match === null ? " (sin coincidencia, va a 'otros')" : "") . "\n";
    if (!$dryRun) {
        $update->execute([':group' => $groupId, ':id' => $row['id']]);
        $assigned++;
    }
}

echo "\n" . ($dryRun ? "Se asignarian " : "Asignadas ") . count($rows) . " categorias (" . count($unmatched) . " sin coincidencia de palabra clave, van a 'otros').\n";
