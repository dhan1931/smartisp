<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'rowsFound' => 0,
        'error' => 'Método no permitido. Solo se acepta GET.'
    ]);
    exit;
}

$supabaseUrl = getenv('SUPABASE_URL');
$supabaseKey = getenv('SUPABASE_ANON_KEY') ?: getenv('SUPABASE_KEY');

if (!$supabaseUrl || !$supabaseKey) {
    // Buscar en archivo .env en la raíz si las variables de entorno del servidor no están expuestas a PHP
    $envPath = dirname(__DIR__, 2) . '/.env';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#' || strpos($line, '=') === false) continue;
            list($key, $val) = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\0\x0B\"'");
            if ($key === 'SUPABASE_URL' && !$supabaseUrl) $supabaseUrl = $val;
            if (($key === 'SUPABASE_ANON_KEY' || $key === 'SUPABASE_KEY') && !$supabaseKey) $supabaseKey = $val;
        }
    }
}

if (!$supabaseUrl || !$supabaseKey) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'rowsFound' => 0,
        'error' => 'Cliente de Supabase no configurado. Faltan variables de entorno SUPABASE_URL o SUPABASE_ANON_KEY.'
    ]);
    exit;
}

$targetUrl = rtrim($supabaseUrl, '/') . '/rest/v1/products?select=id,name,price&limit=100';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'apikey: ' . $supabaseKey,
    'Authorization: Bearer ' . $supabaseKey,
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 8);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$rawResponse = curl_exec($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'rowsFound' => 0,
        'error' => 'Error de conexión con Supabase: ' . $curlErr
    ]);
    exit;
}

if ($httpStatus >= 200 && $httpStatus < 300) {
    $data = json_decode($rawResponse, true);
    $count = is_array($data) ? count($data) : 0;
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'rowsFound' => $count
    ]);
} else {
    http_response_code(500);
    $errObj = json_decode($rawResponse, true);
    $msg = (is_array($errObj) && isset($errObj['message'])) ? $errObj['message'] : 'Error al consultar productos en Supabase';
    echo json_encode([
        'success' => false,
        'rowsFound' => 0,
        'error' => $msg
    ]);
}
