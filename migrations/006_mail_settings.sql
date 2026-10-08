-- Separa la configuración de correo de settings_rows (el cajón genérico clave/valor que
-- mezclaba SMTP con el contenido de la landing, logos y basura de prueba) a una tabla propia,
-- de fila única, con columnas reales. "Primero de correos" -- el resto de los parámetros
-- (branding/logo, contenido editable) sigue en settings_rows por ahora, es un paso aparte.

CREATE TABLE IF NOT EXISTS mail_settings (
  id TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
  admin_email VARCHAR(191) NULL,
  smtp_provider VARCHAR(40) NULL,
  smtp_host VARCHAR(191) NULL,
  smtp_port SMALLINT UNSIGNED NULL,
  smtp_user VARCHAR(191) NULL,
  smtp_pass VARCHAR(255) NULL,
  smtp_secure TINYINT(1) NOT NULL DEFAULT 1,
  smtp_from VARCHAR(255) NULL,
  resend_api_key VARCHAR(255) NULL,
  email_from VARCHAR(255) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_mail_settings_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO mail_settings (id, admin_email, smtp_provider, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, smtp_from, resend_api_key, email_from)
SELECT
  1,
  MAX(CASE WHEN setting_key = 'admin_email' THEN setting_value END),
  MAX(CASE WHEN setting_key = 'smtp_provider' THEN setting_value END),
  MAX(CASE WHEN setting_key = 'smtp_host' THEN setting_value END),
  NULLIF(MAX(CASE WHEN setting_key = 'smtp_port' THEN setting_value END), ''),
  MAX(CASE WHEN setting_key = 'smtp_user' THEN setting_value END),
  MAX(CASE WHEN setting_key = 'smtp_pass' THEN setting_value END),
  CASE WHEN MAX(CASE WHEN setting_key = 'smtp_secure' THEN setting_value END) IN ('false', '0') THEN 0 ELSE 1 END,
  MAX(CASE WHEN setting_key = 'smtp_from' THEN setting_value END),
  MAX(CASE WHEN setting_key = 'resend_api_key' THEN setting_value END),
  MAX(CASE WHEN setting_key = 'email_from' THEN setting_value END)
FROM settings_rows
WHERE setting_key IN ('admin_email','smtp_provider','smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','smtp_secure','resend_api_key','email_from')
ON DUPLICATE KEY UPDATE
  admin_email = VALUES(admin_email), smtp_provider = VALUES(smtp_provider), smtp_host = VALUES(smtp_host),
  smtp_port = VALUES(smtp_port), smtp_user = VALUES(smtp_user), smtp_pass = VALUES(smtp_pass),
  smtp_secure = VALUES(smtp_secure), smtp_from = VALUES(smtp_from), resend_api_key = VALUES(resend_api_key),
  email_from = VALUES(email_from);

INSERT IGNORE INTO mail_settings (id) VALUES (1);

DELETE FROM settings_rows
WHERE setting_key IN ('admin_email','smtp_provider','smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','smtp_secure','resend_api_key','email_from');
