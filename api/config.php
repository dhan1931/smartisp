<?php
// Configuración de credenciales de base de datos MySQL en Hostinger
return [
    'host'     => getenv('MYSQL_HOST')     ?: (getenv('DB_HOST')     ?: 'localhost'),
    'port'     => getenv('MYSQL_PORT')     ?: (getenv('DB_PORT')     ?: 3306),
    'database' => getenv('MYSQL_DATABASE') ?: (getenv('DB_NAME')     ?: 'u606699314_smart_isp'),
    'username' => getenv('MYSQL_USER')     ?: (getenv('DB_USER')     ?: 'u606699314_dhan'),
    'password' => getenv('MYSQL_PASSWORD') ?: (getenv('DB_PASSWORD') ?: 'Dhan193111'),
    'charset'  => 'utf8mb4'
];
