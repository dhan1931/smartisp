-- password_resets_rows es una tabla muerta: columnas genericas 'COL 1'..'COL 4' de una
-- importacion vieja (hasta la fila de encabezados quedo insertada como dato), sin ninguna
-- referencia en el codigo (api/*.php). La tabla real y en uso es password_resets, que ya
-- tiene PRIMARY KEY, UNIQUE en token e indices propios.

DROP TABLE IF EXISTS password_resets_rows;
