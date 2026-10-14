/* Banner de campaña reutilizable (hero de página): soporta los mismos display_mode que
   tienda.html (carousel, single, split, triple, grid, cards), portado y generalizado para
   que nosotros.html/servicios.html respeten la variante elegida en el panel de campañas
   en lugar de mostrar siempre un único banner estático. */
(function () {
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[char]));

  const campaignSlideImages = slide => {
    const images = Array.isArray(slide.images) ? [...slide.images].sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0)) : [];
    if (!images.length && slide.image_url) images.push({ image_url: slide.image_url, image_alt: slide.image_alt || slide.title });
    const primaryIndex = images.findIndex(image => image.is_primary === true || String(image.is_primary) === '1');
    return primaryIndex > 0 ? [images[primaryIndex], ...images.slice(0, primaryIndex), ...images.slice(primaryIndex + 1)] : images;
  };

  const campaignImageFocus = image => {
    const x = Math.max(0, Math.min(100, Number(image?.focal_x ?? 50)));
    const y = Math.max(0, Math.min(100, Number(image?.focal_y ?? 50)));
    const zoom = Math.max(100, Math.min(180, Number(image?.zoom_pct ?? 100))) / 100;
    return { x, y, zoom };
  };
  const applyCampaignImageFocus = (element, image) => {
    const focus = campaignImageFocus(image);
    element.style.objectPosition = `${focus.x}% ${focus.y}%`;
    element.style.transform = `scale(${focus.zoom})`;
    element.style.transformOrigin = `${focus.x}% ${focus.y}%`;
  };
  const campaignExternal = target => /^https:\/\//i.test(String(target || ''));
  const setCampaignLink = (link, target, fallback) => {
    link.href = target || fallback;
    if (campaignExternal(link.href)) { link.target = '_blank'; link.rel = 'noopener noreferrer'; }
    else { link.removeAttribute('target'); link.removeAttribute('rel'); }
  };
  const campaignButtonVisible = slide => !(slide.button_visible === false || slide.button_visible === 0 || slide.button_visible === '0');
  const campaignButton2Visible = slide => (slide.button2_visible === true || slide.button2_visible === 1 || slide.button2_visible === '1') && Boolean(slide.button2_text);
  const CAMPAIGN_FONT_SCALE = { sm: .8, md: 1, lg: 1.25, xl: 1.5 };
  const applyCampaignFontScale = (el, sizeKey) => {
    if (!el) return;
    const scale = CAMPAIGN_FONT_SCALE[sizeKey];
    if (!scale || scale === 1) { el.style.fontSize = ''; return; }
    const current = parseFloat(getComputedStyle(el).fontSize) || 16;
    el.style.fontSize = (current * scale).toFixed(1) + 'px';
  };
  const campaignTextColorStyle = slide => {
    const subtitle = /^#[0-9a-fA-F]{6}$/.test(slide.subtitle_color || '') ? `color:${slide.subtitle_color};` : '';
    const eyebrow = /^#[0-9a-fA-F]{6}$/.test(slide.eyebrow_color || '') ? `color:${slide.eyebrow_color};` : '';
    return { subtitle, eyebrow };
  };
  const campaignTitleHtml = slide => `<span${slide.title_color ? ` style="color:${escapeHtml(slide.title_color)}"` : ''}>${escapeHtml(slide.title || '')}</span>${slide.title_line2 ? `<br><span${slide.title_line2_color ? ` style="color:${escapeHtml(slide.title_line2_color)}"` : ''}>${escapeHtml(slide.title_line2)}</span>` : ''}`;
  const campaignEyebrowHtml = slide => `${escapeHtml(slide.eyebrow || '')}${slide.eyebrow_line2 ? `<br>${escapeHtml(slide.eyebrow_line2)}` : ''}`;

  // Franja de confianza (Productos/Clientes/Proyectos ejecutados/Soporte): tarjeta flotante
  // esquina inferior izquierda, cada item con un valor grande arriba y una etiqueta chica abajo
  // (ej. "+2.700" / "Productos disponibles"), como el mock. Productos siempre se calcula en vivo
  // desde el catalogo; los otros 3 usan el override del banner (texto de 2 lineas: valor\netiqueta)
  // o, si no hay, el default global guardado en Configuracion. Un solo fetch por carga de pagina.
  let trustStatsDataPromise = null;
  const loadTrustStatsData = () => {
    if (trustStatsDataPromise) return trustStatsDataPromise;
    trustStatsDataPromise = Promise.all([
      fetch('/api/auth/landing-content', { cache: 'no-store' }).then(r => r.ok ? r.json() : { content: [] }).catch(() => ({ content: [] })),
      fetch('/api/auth/catalog?page=1&limit=1', { cache: 'no-store' }).then(r => r.ok ? r.json() : { total: 0 }).catch(() => ({ total: 0 })),
    ]).then(([landing, catalog]) => {
      const map = {};
      (landing.content || []).forEach(row => { map[row.key] = row.value; });
      return {
        clients: map.trust_stats_clients_default || '',
        projects: map.trust_stats_projects_default || '',
        support: map.trust_stats_support_default || '',
        productsLabel: map.trust_stats_products_label_default || 'Productos disponibles',
        productsCount: Number(catalog.total) || 0,
      };
    });
    return trustStatsDataPromise;
  };
  const trustStatPair = text => {
    const [value, label] = String(text || '').split('\n');
    return { value: (value || '').trim(), label: (label || '').trim() };
  };
  const renderTrustStats = (slide, statsEl) => {
    if (!statsEl) return;
    if (!slide.show_trust_stats) { statsEl.hidden = true; statsEl.innerHTML = ''; return; }
    loadTrustStatsData().then(defaults => {
      const clients = trustStatPair(slide.trust_stats_clients_override || defaults.clients);
      const projects = trustStatPair(slide.trust_stats_projects_override || defaults.projects);
      const support = trustStatPair(slide.trust_stats_support_override || defaults.support);
      const items = [
        { value: `+${defaults.productsCount.toLocaleString('es-EC')}`, label: defaults.productsLabel },
        clients.value ? clients : null,
        projects.value ? projects : null,
        support.value ? support : null,
      ].filter(Boolean);
      statsEl.innerHTML = items.map(item => `<div class="store-campaign-stat"><strong>${escapeHtml(item.value)}</strong>${item.label ? `<span>${escapeHtml(item.label)}</span>` : ''}</div>`).join('');
      statsEl.hidden = !items.length;
    });
  };

  // Insignias cortas (icono + frase), 1 a 4, separadas por una linea vertical. A diferencia de
  // la franja de confianza, no tienen un default global: si el banner no trae ninguna, no se
  // muestra nada.
  const renderFeatureBadges = (slide, el) => {
    if (!el) return;
    const items = Array.isArray(slide.feature_badges) ? slide.feature_badges.filter(b => b && b.text) : [];
    el.innerHTML = items.map(item => {
      const color = /^#[0-9a-fA-F]{6}$/.test(item.color || '') ? item.color : '#087fa5';
      return `<div class="store-campaign-feature"><span class="store-campaign-feature-icon" style="--fb-color:${color}"><i data-lucide="${escapeHtml(item.icon || 'star')}"></i></span><span class="store-campaign-feature-text">${escapeHtml(item.text)}</span></div>`;
    }).join('');
    el.hidden = !items.length;
    if (items.length) window.lucide?.createIcons();
  };
  const campaignBulletMarkup = (style, color) => {
    if (style === 'check') return `<i data-lucide="check" width="14" style="color:${escapeHtml(color)}"></i>`;
    if (style === 'check-circle') return `<span class="store-campaign-bullet-mark store-campaign-bullet-check-circle" style="background:${escapeHtml(color)}"><i data-lucide="check"></i></span>`;
    return `<span class="store-campaign-bullet-mark store-campaign-bullet-${style}" style="background:${escapeHtml(color)}"></span>`;
  };
  const campaignSubtitleHtml = slide => {
    const text = String(slide.subtitle || '');
    if (!text) return '';
    const style = ['check', 'circle', 'square', 'check-circle'].includes(slide.bullet_style) ? slide.bullet_style : 'none';
    if (style === 'none') return escapeHtml(text).replace(/\n/g, '<br>');
    const color = /^#[0-9a-fA-F]{6}$/.test(slide.bullet_color || '') ? slide.bullet_color : '#16a34a';
    const items = text.split('\n').map(line => line.trim()).filter(Boolean);
    return `<ul class="store-campaign-bullets">${items.map(item => `<li>${campaignBulletMarkup(style, color)}<span>${escapeHtml(item)}</span></li>`).join('')}</ul>`;
  };
  const applyCampaignCardFontScales = (host, slides) => {
    host.querySelectorAll('[data-campaign-card]').forEach(card => {
      const slide = slides[Number(card.dataset.campaignCard)];
      if (!slide) return;
      applyCampaignFontScale(card.querySelector('.store-campaign-eyebrow'), slide.eyebrow_size);
      applyCampaignFontScale(card.querySelector('.store-campaign-title'), slide.title_size);
      applyCampaignFontScale(card.querySelector('.store-campaign-subtitle'), slide.subtitle_size);
    });
  };

  window.initStorefrontCampaignHero = function (zone, options = {}) {
    const fallbackUrl = options.fallbackUrl || '/tienda.html';
    const root = document.querySelector('.store-campaign-area');
    const banner = document.querySelector('#storeCampaign');
    if (!root || !banner) return;

    let slides = [];
    let displayMode = 'carousel';
    let slideIndex = 0;
    let rotationTimer = 0;
    const imageTimers = new Map();

    const clearImageTimers = (image = null) => {
      imageTimers.forEach((rotation, element) => {
        if (image && element !== image) return;
        clearInterval(rotation.timer); clearTimeout(rotation.timeout);
        imageTimers.delete(element);
      });
    };
    const startImageRotation = (image, slide) => {
      clearImageTimers(image);
      const images = campaignSlideImages(slide);
      if (images.length < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
      let index = 0;
      const baseOpacity = image.style.opacity || getComputedStyle(image).opacity || '1';
      const transitionMs = Math.max(150, Math.min(2000, Number(slide.image_transition_ms) || 1000));
      const fadeMs = transitionMs / 2;
      image.style.transition = `opacity ${fadeMs}ms ease`;
      const rotation = { timer: 0, timeout: 0 };
      rotation.timer = setInterval(() => {
        index = (index + 1) % images.length;
        image.style.opacity = '0';
        rotation.timeout = setTimeout(() => {
          rotation.timeout = 0;
          image.src = images[index].image_url;
          image.alt = images[index].image_alt || slide.title || '';
          applyCampaignImageFocus(image, images[index]);
          image.style.opacity = baseOpacity;
        }, fadeMs);
      }, Math.max(transitionMs + 100, Math.max(2, Math.min(20, Number(slide.image_interval_seconds) || 5)) * 1000));
      imageTimers.set(image, rotation);
    };
    const stopRotation = () => { if (rotationTimer) clearInterval(rotationTimer); rotationTimer = 0; clearImageTimers(); };
    const startRotation = seconds => {
      if (rotationTimer) clearInterval(rotationTimer);
      rotationTimer = 0;
      if (displayMode !== 'carousel' || slides.length < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
      rotationTimer = setInterval(() => renderSlide(slideIndex + 1), Math.max(4, Math.min(20, Number(seconds) || 7)) * 1000);
    };

    const renderSlide = index => {
      if (!slides.length || !['carousel', 'single'].includes(displayMode)) return;
      slideIndex = (index + slides.length) % slides.length;
      const slide = slides[slideIndex];
      const isCopyPanel = slide.card_layout === 'side';
      const copyBackground = /^#[0-9a-fA-F]{6}$/.test(slide.copy_background_color || '') ? slide.copy_background_color : '#ffffff';
      banner.classList.toggle('is-photo-background', !isCopyPanel);
      banner.classList.toggle('is-copy-panel', isCopyPanel);
      banner.style.setProperty('--campaign-image-width', `${Math.max(35, Math.min(65, Number(slide.image_width_pct ?? 55)))}%`);
      banner.style.setProperty('--copy-background', copyBackground);
      banner.classList.toggle('is-contain', slide.image_fit === 'contain');
      const image = banner.querySelector('#storeCampaignImage');
      clearImageTimers(image);
      const images = campaignSlideImages(slide);
      image.src = images[0]?.image_url || slide.image_url || '';
      image.alt = images[0]?.image_alt || slide.image_alt || slide.title || '';
      image.style.objectFit = slide.image_fit === 'contain' ? 'contain' : 'cover';
      image.style.opacity = String(Math.max(0, Math.min(100, Number(slide.image_opacity ?? 100))) / 100);
      applyCampaignImageFocus(image, images[0]);
      const overlayOpacity = Math.max(0, Math.min(100, Number(slide.overlay_opacity ?? 68))) / 100;
      banner.style.setProperty('--campaign-overlay-opacity', String(isCopyPanel ? overlayOpacity : Math.max(.55, overlayOpacity)));
      startImageRotation(image, slide);
      const eyebrowEl = banner.querySelector('#storeCampaignEyebrow');
      eyebrowEl.innerHTML = campaignEyebrowHtml(slide);
      eyebrowEl.hidden = !slide.eyebrow;
      const titleEl = banner.querySelector('#storeCampaignTitle'), subtitleEl = banner.querySelector('#storeCampaignSubtitle');
      const textColor = campaignTextColorStyle(slide);
      titleEl.innerHTML = campaignTitleHtml(slide); eyebrowEl.style.cssText = textColor.eyebrow;
      subtitleEl.innerHTML = campaignSubtitleHtml(slide); subtitleEl.style.cssText = textColor.subtitle;
      subtitleEl.hidden = !slide.subtitle;
      applyCampaignFontScale(eyebrowEl, slide.eyebrow_size);
      applyCampaignFontScale(titleEl, slide.title_size);
      applyCampaignFontScale(subtitleEl, slide.subtitle_size);
      renderTrustStats(slide, banner.querySelector('#storeCampaignStats'));
      renderFeatureBadges(slide, banner.querySelector('#storeCampaignFeatures'));
      window.lucide?.createIcons();
      banner.querySelector('#storeCampaignButton').textContent = slide.button_text || 'Explorar';
      const link1 = banner.querySelector('#storeCampaignLink');
      setCampaignLink(link1, slide.target_url, fallbackUrl);
      link1.hidden = !campaignButtonVisible(slide);
      const link2 = banner.querySelector('#storeCampaignLink2');
      link2.hidden = !campaignButton2Visible(slide);
      if (!link2.hidden) {
        banner.querySelector('#storeCampaignButton2').textContent = slide.button2_text;
        setCampaignLink(link2, slide.button2_url || slide.target_url, fallbackUrl);
      }
      const badge = banner.querySelector('#storeCampaignBadge');
      badge.textContent = slide.badge_label || '';
      badge.className = `store-campaign-badge ${['new', 'featured', 'dark'].includes(slide.badge_tone) ? slide.badge_tone : ''}`;
      badge.hidden = !slide.badge_label;
      const promo = banner.querySelector('#storeCampaignPromo');
      promo.hidden = !slide.promo_chip_label;
      if (slide.promo_chip_label) {
        setCampaignLink(promo, slide.promo_chip_target_url || slide.target_url, fallbackUrl);
        const icon = document.createElement('i');
        icon.dataset.lucide = ['sparkles', 'star', 'truck'].includes(slide.promo_chip_icon) ? slide.promo_chip_icon : 'tag';
        icon.setAttribute('aria-hidden', 'true');
        const label = document.createElement('span'); label.textContent = slide.promo_chip_label;
        promo.replaceChildren(icon, label);
      }
      banner.classList.add('is-visible');
      banner.querySelectorAll('.store-campaign-dot').forEach((dot, dotIndex) => dot.setAttribute('aria-current', dotIndex === slideIndex ? 'true' : 'false'));
      window.lucide?.createIcons();
    };

    const renderCard = (slide, index) => {
      const images = campaignSlideImages(slide);
      const image = images[0] || {};
      const isSide = slide.card_layout === 'side';
      const target = slide.target_url || fallbackUrl;
      const promoTarget = slide.promo_chip_target_url || target;
      const tone = ['new', 'featured', 'dark'].includes(slide.badge_tone) ? slide.badge_tone : '';
      const icon = ['sparkles', 'star', 'truck'].includes(slide.promo_chip_icon) ? slide.promo_chip_icon : 'tag';
      const focus = campaignImageFocus(image);
      const mediaStyle = `object-position:${focus.x}% ${focus.y}%;transform:scale(${focus.zoom});transform-origin:${focus.x}% ${focus.y}%`;
      const textColor = campaignTextColorStyle(slide);
      return `<article class="store-campaign-card${isSide ? ' is-layout-side' : ' is-layout-full'}" data-campaign-card="${index}"><img class="store-campaign-card-media" data-campaign-image data-slide-index="${index}" src="${escapeHtml(image.image_url || '')}" alt="${escapeHtml(image.image_alt || slide.title || '')}" style="${mediaStyle}"><span class="store-campaign-card-overlay"></span><a class="store-campaign-card-main" href="${escapeHtml(target)}" ${campaignExternal(target) ? 'target="_blank" rel="noopener noreferrer"' : ''}><span class="store-campaign-card-copy"><span class="store-campaign-eyebrow" style="${textColor.eyebrow}" ${slide.eyebrow ? '' : 'hidden'}>${campaignEyebrowHtml(slide)}</span><span class="store-campaign-title">${campaignTitleHtml(slide)}</span><span class="store-campaign-subtitle" style="${textColor.subtitle}" ${slide.subtitle ? '' : 'hidden'}>${campaignSubtitleHtml(slide)}</span><span class="store-campaign-cta" ${campaignButtonVisible(slide) ? '' : 'hidden'}>${escapeHtml(slide.button_text || 'Explorar')} →</span></span></a>${slide.badge_label ? `<span class="store-campaign-badge store-campaign-card-badge ${tone}">${escapeHtml(slide.badge_label)}</span>` : ''}${slide.promo_chip_label ? `<a class="store-campaign-card-promo" href="${escapeHtml(promoTarget)}" ${campaignExternal(promoTarget) ? 'target="_blank" rel="noopener noreferrer"' : ''}><i data-lucide="${icon}" width="14"></i>${escapeHtml(slide.promo_chip_label)}</a>` : ''}</article>`;
    };

    const startLayoutImageRotations = () => {
      clearImageTimers();
      banner.querySelectorAll('.store-campaign-card [data-campaign-image]').forEach(image => {
        const slide = slides[Number(image.dataset.slideIndex)];
        if (slide) startImageRotation(image, slide);
      });
    };

    const resumeAnimations = () => {
      if (['carousel', 'single'].includes(displayMode)) {
        startImageRotation(banner.querySelector('#storeCampaignImage'), slides[slideIndex]);
        startRotation(options.rotationSeconds);
      } else startLayoutImageRotations();
    };

    const load = async () => {
      try {
        const response = await fetch(`/api/auth/store-campaigns?zone=${encodeURIComponent(zone)}&_t=${Date.now()}`, { cache: 'no-store' });
        if (!response.ok) return;
        const data = await response.json();
        slides = Array.isArray(data.slides) ? data.slides : [];
        if (!slides.length) return;
        displayMode = ['carousel', 'single', 'split', 'triple', 'grid', 'cards'].includes(data.display_mode) ? data.display_mode : 'carousel';
        options.rotationSeconds = data.rotation_seconds;
        const heroHeightPct = Math.max(60, Math.min(100, Number(data.hero_height_pct) || 100));
        banner.style.setProperty('--hero-ar', (240 / heroHeightPct).toFixed(3));
        root.classList.toggle('is-full-width', data.layout_width === 'full');
        root.hidden = false;
        banner.classList.remove('is-single', 'is-contain', 'is-triple', 'is-layout', 'is-split', 'is-grid', 'is-cards');
        if (['split', 'triple', 'grid', 'cards'].includes(displayMode)) {
          banner.classList.add('is-layout', `is-${displayMode}`);
          const limit = { split: 2, triple: 3, grid: 4 }[displayMode] || slides.length;
          const slice = slides.slice(0, limit);
          banner.querySelectorAll(':scope > .store-campaign-card').forEach(el => el.remove());
          banner.insertAdjacentHTML('beforeend', slice.map((slide, index) => renderCard(slide, index)).join(''));
          banner.classList.add('is-visible');
          applyCampaignCardFontScales(banner, slice);
          startLayoutImageRotations();
          window.lucide?.createIcons();
        } else {
          banner.classList.toggle('is-single', displayMode === 'single');
          const dots = banner.querySelector('#storeCampaignDots');
          if (dots) dots.innerHTML = displayMode === 'carousel' ? slides.map((slide, index) => `<button class="store-campaign-dot" type="button" aria-label="Mostrar banner ${index + 1}: ${escapeHtml(slide.title)}" aria-current="${index === 0 ? 'true' : 'false'}" data-campaign-slide="${index}"></button>`).join('') : '';
          const controls = banner.querySelector('#storeCampaignControls');
          if (controls) controls.hidden = displayMode !== 'carousel' || slides.length < 2;
          banner.querySelector('#storeCampaignPrev')?.addEventListener('click', () => { renderSlide(slideIndex - 1); startRotation(data.rotation_seconds); });
          banner.querySelector('#storeCampaignNext')?.addEventListener('click', () => { renderSlide(slideIndex + 1); startRotation(data.rotation_seconds); });
          dots?.addEventListener('click', event => { const dot = event.target.closest('[data-campaign-slide]'); if (dot) { renderSlide(Number(dot.dataset.campaignSlide)); startRotation(data.rotation_seconds); } });
          renderSlide(0);
          startRotation(data.rotation_seconds);
        }
        banner.addEventListener('mouseenter', stopRotation);
        banner.addEventListener('mouseleave', resumeAnimations);
        banner.addEventListener('focusin', stopRotation);
        banner.addEventListener('focusout', resumeAnimations);
        document.addEventListener('visibilitychange', () => document.hidden ? stopRotation() : resumeAnimations());
      } catch (error) {
        console.warn('No se pudo cargar el banner de la página:', error);
      }
    };
    load();
    return { reload: load };
  };
})();
