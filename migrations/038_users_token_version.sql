-- Revocación de sesiones: sube al cambiar o restablecer la contraseña y se compara con la
-- versión guardada en la sesión y en el token admin (api/router.php, resolveAuthUser). Las
-- sesiones abiertas antes de esta migración quedan en 0 y siguen válidas.
ALTER TABLE users_rows ADD COLUMN IF NOT EXISTS token_version INT NOT NULL DEFAULT 0;
