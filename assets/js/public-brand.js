(function () {
  const reveal = () => {
    document.documentElement.classList.add('public-brand-ready');
  };

  const splitLogoText = text => {
    const value = String(text || 'smartisp').trim() || 'smartisp';
    const mid = Math.ceil(value.length / 2);
    return value.slice(0, mid) + '<b>' + value.slice(mid) + '</b><span style="color:#f5a524">.</span>';
  };

  const applyLogo = content => {
    if (!content) return;
    window.__publicBrandManaged = true;
    const mode = content.landing_logo_type || (content.landing_logo_image ? 'image' : content.logo_type || (content.logo_image ? 'image' : 'text'));
    const brand = content.landing_logo_text || content.brand_name || content.logo_text || 'SmartISP';
    const lightImg = content.landing_logo_image || content.logo_image || '';
    const darkImg = content.landing_logo_dark_image || content.logo_dark_image || lightImg;
    const text = content.landing_logo_text || content.logo_text || brand;
    const lightHeight = parseInt(content.landing_logo_height || content.logo_height, 10) || 38;
    const darkHeight = parseInt(content.landing_logo_dark_height || content.logo_dark_height, 10) || 42;
    const darkInvert = content.landing_logo_dark_invert === 'true' || content.landing_logo_dark_invert === true || content.logo_dark_invert === 'true' || content.logo_dark_invert === true;

    document.querySelectorAll('.logo, [data-public-logo]').forEach(el => {
      const isFooter = !!el.closest('footer');
      const img = isFooter ? darkImg : lightImg;
      const height = isFooter ? darkHeight : lightHeight;
      if (mode === 'image' && img) {
        const filter = isFooter && darkInvert ? 'filter:brightness(0) invert(1);' : '';
        el.innerHTML = '<img src="' + img + '" alt="' + brand + '" style="height:' + height + 'px;max-height:140px;width:auto;object-fit:contain;vertical-align:middle;display:inline-block;' + filter + '">';
      } else {
        el.innerHTML = splitLogoText(text);
      }
    });
    reveal();
  };

  const fallbackTimer = setTimeout(reveal, 900);

  fetch('/api/auth/site-content', { cache: 'no-store' })
    .then(response => response.ok ? response.json() : null)
    .then(data => {
      if (!data || !Array.isArray(data.content)) {
        reveal();
        return;
      }
      clearTimeout(fallbackTimer);
      applyLogo(Object.fromEntries(data.content.map(item => [item.key, item.value])));
    })
    .catch(() => {
      clearTimeout(fallbackTimer);
      reveal();
    });
})();
