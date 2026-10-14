-- Fila de 1 a 4 "insignias" cortas (icono + frase), separadas por una linea vertical, como en
-- el mock (Experiencia en el mercado / Soluciones a tu medida / Respaldo y soporte). Distinto de
-- la franja de confianza (numeros): aca no hay valor numerico, solo icono + texto. Se guarda como
-- JSON en una sola columna (no una tabla hija) porque son como mucho 4 items chicos, propios de
-- ese banner, sin necesidad de ordenarlos/consultarlos por separado.
ALTER TABLE storefront_campaign_slides
  ADD COLUMN IF NOT EXISTS feature_badges TEXT NULL;
