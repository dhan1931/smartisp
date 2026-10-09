(function () {
  const logoUrl = '/assets/img/logo.webp?v=canonical-brand-20261009';
  const renderCanonicalLogo = () => {
    window.__publicBrandManaged = true;
    document.documentElement.classList.add('public-brand-ready');
    document.querySelectorAll('.logo, [data-public-logo]').forEach(el => {
      const image = document.createElement('img');
      image.src = logoUrl;
      image.alt = 'SmartISP · Infraestructura y Soluciones TI';
      image.width = 300;
      image.height = 100;
      image.decoding = 'async';
      image.style.cssText = 'display:block;width:auto;height:46px;max-width:min(100%,240px);object-fit:contain;vertical-align:middle';
      if (el.closest('footer')) image.style.cssText += ';padding:4px 6px;background:#fff;border-radius:3px';
      image.addEventListener('error', () => image.remove(), { once: true });
      el.replaceChildren(image);
      el.setAttribute('aria-label', 'SmartISP');
    });
  };

  renderCanonicalLogo();

  fetch('/api/auth/site-content', { cache: 'no-store' })
    .then(response => response.ok ? response.json() : null)
    .then(data => {
      if (!data || !Array.isArray(data.content)) return;
      const content = Object.fromEntries(data.content.map(item => [item.key, item.value]));
      const productColumns = content.storefront_product_columns === '6' ? '6' : '4';
      document.documentElement.dataset.storeProductColumns = productColumns;
      document.documentElement.style.setProperty('--store-product-media-ratio', productColumns === '4' ? '1' : '1.15');
    })
    .catch(() => {});
})();
