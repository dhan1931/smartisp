-- users_rows no tenia PRIMARY KEY ni nada que impidiera dos filas con el mismo email (podria
-- haber dos cuentas para la misma persona, o un id repetido, sin que la base lo impidiera).
-- Verificado antes de escribir esto: 5 usuarios, todos con id y email (en minusculas/sin
-- espacios) unicos y no vacios.

UPDATE users_rows
SET created_at = SUBSTRING_INDEX(SUBSTRING_INDEX(created_at, '.', 1), '+', 1)
WHERE created_at LIKE '%.%' OR created_at LIKE '%+%';

ALTER TABLE users_rows
  MODIFY COLUMN id VARCHAR(191) NOT NULL,
  MODIFY COLUMN email VARCHAR(191) NOT NULL,
  MODIFY COLUMN role VARCHAR(40) NOT NULL DEFAULT 'customer',
  MODIFY COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE users_rows ADD PRIMARY KEY (id);
ALTER TABLE users_rows ADD UNIQUE INDEX idx_email (email);
