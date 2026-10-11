(function () {
  const logoUrl = '/assets/img/logo-default.jpg?v=brand-20261011';
  const renderCanonicalLogo = (content = {}) => {
    window.__publicBrandManaged = true;
    document.documentElement.classList.add('public-brand-ready');
    document.querySelectorAll('.logo, [data-public-logo]').forEach(el => {
      if(content.logo_type==='text'){el.textContent=content.logo_text||content.brand_name||'SmartISP';el.setAttribute('aria-label','SmartISP');return;}
      const image = document.createElement('img');
      const configured = el.closest('footer') ? (content.logo_dark_image || content.logo_image) : content.logo_image;
      const safe = configured && (/^https:\/\//i.test(configured) || /^\/(?!\/)/.test(configured)) && !/[\x00-\x1f\\]/.test(configured);
      image.src = safe && !/\/assets\/img\/logo\.(png|webp)(\?|$)/.test(configured) ? configured : logoUrl;
      image.alt = 'SmartISP · Infraestructura y Soluciones TI';
      image.width = 300;
      image.height = 100;
      image.decoding = 'async';
      image.style.cssText = 'display:block;width:auto;height:46px;max-width:min(100%,240px);object-fit:contain;vertical-align:middle';
      const height=Number(el.closest('footer')?content.logo_dark_height:content.logo_height);
      if(Number.isFinite(height)&&height>=20&&height<=140)image.style.height=`${height}px`;
      if (el.closest('footer')) image.style.cssText += ';padding:4px 6px;background:#fff;border-radius:3px';
      image.addEventListener('error', () => { if(image.src.includes('logo-default.jpg'))el.textContent='SmartISP';else image.src=logoUrl; }, { once: true });
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
      renderCanonicalLogo(content);
      const productColumns = content.storefront_product_columns === '6' ? '6' : '4';
      document.documentElement.dataset.storeProductColumns = productColumns;
      document.documentElement.style.setProperty('--store-product-media-ratio', productColumns === '4' ? '1' : '1.15');
    })
    .catch(() => {});
})();
