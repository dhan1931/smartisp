<?php
// Configuración de MySQL. Los valores NO viven en el código: salen de variables de entorno o del archivo .env
// de la raíz del proyecto (ignorado por git; copia .env.example). Si falta algo, la conexión falla con un error claro.
if (!function_exists('smartispCargarEnv')) {
    function smartispCargarEnv(): void {
        static $cargado = false;
        if ($cargado) return;
        $cargado = true;
        $ruta = dirname(__DIR__) . '/.env';
        if (!is_readable($ruta)) return;
        foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            $linea = trim($linea);
            if ($linea === '' || $linea[0] === '#' || strpos($linea, '=') === false) continue;
            [$k, $v] = array_map('trim', explode('=', $linea, 2));
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = substr($v, 1, -1);
            if ($k !== '' && getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
        }
    }
}
smartispCargarEnv();

$cfg = [
    'host'     => getenv('MYSQL_HOST')     ?: (getenv('DB_HOST')     ?: ''),
    'port'     => (int) (getenv('MYSQL_PORT') ?: (getenv('DB_PORT') ?: 3306)),
    'database' => getenv('MYSQL_DATABASE') ?: (getenv('DB_NAME')     ?: ''),
    'username' => getenv('MYSQL_USER')     ?: (getenv('DB_USER')     ?: ''),
    'password' => getenv('MYSQL_PASSWORD') ?: (getenv('DB_PASSWORD') ?: ''),
    'charset'  => 'utf8mb4',
];
foreach (['host', 'database', 'username', 'password'] as $k) {
    if ($cfg[$k] === '') {
        error_log("SmartISP: falta la configuración de MySQL ($k). Define MYSQL_HOST, MYSQL_DATABASE, MYSQL_USER y MYSQL_PASSWORD en el entorno o en .env.");
    }
}
return $cfg;
