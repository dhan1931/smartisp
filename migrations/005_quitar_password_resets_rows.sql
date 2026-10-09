-- Retirada (2026-10-09): esta migracion originalmente borraba password_resets_rows
-- (columnas genericas 'COL 1'..'COL 4' de una importacion vieja, sin referencia en
-- api/*.php). Se decidio conservar la tabla; este archivo queda sin accion a proposito
-- para que scripts/migrate.php no la elimine si vuelve a correr.

SELECT 1;
