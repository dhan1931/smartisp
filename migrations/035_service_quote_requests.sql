-- Tabla nueva: solicitudes del formulario de cotizacion de servicios.html (y la CTA de
-- nosotros.html que enlaza ahi). Hoy ese formulario solo abre WhatsApp con un mensaje
-- prellenado -- no queda ningun registro en el sistema, no hay forma de verlas desde el admin.
CREATE TABLE IF NOT EXISTS service_quote_requests (
  id VARCHAR(191) NOT NULL PRIMARY KEY,
  client_type VARCHAR(60) NOT NULL,
  service_interest VARCHAR(120) NOT NULL,
  description TEXT NULL,
  company_name VARCHAR(191) NULL,
  email VARCHAR(191) NULL,
  phone VARCHAR(40) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'nuevo',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status_created (status, created_at),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
