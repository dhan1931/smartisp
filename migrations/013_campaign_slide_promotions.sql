-- Accesos promocionales pertenecen a cada banner, no a una lista desconectada.
ALTER TABLE storefront_campaign_slides
  ADD COLUMN promo_chip_label VARCHAR(60) NULL,
  ADD COLUMN promo_chip_icon VARCHAR(40) NOT NULL DEFAULT 'tag',
  ADD COLUMN promo_chip_target_url VARCHAR(500) NULL;
