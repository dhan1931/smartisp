-- Registro de actividad del equipo (api/lib/audit.php): lo escribe router.php en cada POST/PUT/DELETE
-- de un admin, además de los inicios de sesión y cambios de contraseña. Nunca guarda el cuerpo completo.
CREATE TABLE IF NOT EXISTS admin_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id VARCHAR(191) NULL,
  user_email VARCHAR(191) NULL,
  action VARCHAR(80) NOT NULL,
  method VARCHAR(8) NULL,
  target VARCHAR(191) NULL,
  summary VARCHAR(500) NULL,
  status SMALLINT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_created (created_at),
  INDEX idx_audit_user_created (user_id, created_at),
  INDEX idx_audit_action_created (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cuentas desactivadas (login y resolveAuthUser las rechazan) y último acceso para la pantalla Equipo.
ALTER TABLE users_rows ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE users_rows ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL;
-- update-profile ya escribe updated_at; en bases sin la columna fallaba.
ALTER TABLE users_rows ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
