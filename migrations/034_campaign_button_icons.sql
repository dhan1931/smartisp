-- Icono opcional en el boton principal y/o el segundo boton del banner (ninguno/whatsapp/
-- instagram/correo/otra red). Si el icono es "whatsapp", el boton redirige automaticamente al
-- numero de WhatsApp configurado por defecto (ver settings_rows: contact_whatsapp_number,
-- gestionado desde Configuracion igual que los defaults de la franja de confianza) con un
-- mensaje de consulta general, en vez de usar el destino normal del boton.
ALTER TABLE storefront_campaign_slides
  ADD COLUMN IF NOT EXISTS button_icon VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS button2_icon VARCHAR(20) NULL;
