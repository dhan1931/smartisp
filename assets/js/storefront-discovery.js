(() => {
  const menu = document.querySelector('#storefrontNavigation');
  const openButton = document.querySelector('.storefront-menu-button');
  const closeButton = document.querySelector('.storefront-menu-close');
  const backdrop = document.querySelector('.storefront-menu-backdrop');
  const categoryMenu = document.querySelector('#storefrontCategoryMenu');
  const categoryTrigger = document.querySelector('.storefront-category-trigger');
  const categoryPanel = document.querySelector('#storefrontCategoryPanel');
  const categoryList = document.querySelector('#storefrontCategoryList');
  const categorySearch = document.querySelector('.storefront-category-search input');
  if (!menu || !openButton) return;

  const setOpen = open => {
    menu.classList.toggle('is-open', open);
    if (backdrop) backdrop.hidden = !open;
    document.body.classList.toggle('storefront-menu-open', open);
    openButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    openButton.setAttribute('aria-label', open ? 'Cerrar categorías' : 'Abrir categorías');
    if (window.lucide) window.lucide.createIcons();
  };

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
  const slug = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 90) || 'categoria';
  const renderCategories = categories => {
    if (!categoryList || !Array.isArray(categories) || !categories.length) return;
    const total = categories.reduce((sum, category) => sum + Number(category.productCount || 0), 0);
    const all = `<a href="/tienda.html#catalogo" data-store-category="todo el catalogo"><span>Todo el catálogo</span><small>${total.toLocaleString('es-EC')}</small><i data-lucide="chevron-right" width="18"></i></a>`;
    const rows = categories.map((category, index) => {
      const name = String(category.name || '').trim();
      const subs = Array.isArray(category.subcategories) ? category.subcategories.filter(Boolean) : [];
      const subId = `storeSubcategories${index}`;
      const href = `/categoria/${encodeURIComponent(slug(name))}/`;
      return `<section class="storefront-category-entry" data-category-entry="${esc(`${name} ${subs.join(' ')}`.toLocaleLowerCase())}"><div class="storefront-category-row"><a href="${href}" data-store-category="${esc(name.toLocaleLowerCase())}"><span>${esc(name)}</span><small>${Number(category.productCount || 0).toLocaleString('es-EC')}</small></a>${subs.length ? `<button class="storefront-category-expand" type="button" aria-label="Ver subcategorías de ${esc(name)}" aria-expanded="false" aria-controls="${subId}"><i data-lucide="chevron-down" width="19"></i></button>` : ''}</div>${subs.length ? `<div class="storefront-subcategories" id="${subId}" hidden>${subs.map(sub => `<a href="/categoria/${encodeURIComponent(slug(sub))}/" data-store-category="${esc(sub.toLocaleLowerCase())}">${esc(sub)}</a>`).join('')}</div>` : ''}</section>`;
    }).join('');
    categoryList.innerHTML = all + rows;
    window.lucide?.createIcons();
  };

  openButton.addEventListener('click', () => setOpen(!menu.classList.contains('is-open')));
  closeButton?.addEventListener('click', () => setOpen(false));
  backdrop?.addEventListener('click', () => setOpen(false));
  menu.querySelectorAll('a').forEach(link => link.addEventListener('click', () => setOpen(false)));
  categoryTrigger?.addEventListener('click', () => {
    const open = categoryPanel?.hidden;
    if (categoryPanel) categoryPanel.hidden = !open;
    categoryTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    categoryMenu?.classList.toggle('is-open', Boolean(open));
  });
  document.addEventListener('click', event => {
    if (categoryMenu && !categoryMenu.contains(event.target) && window.innerWidth > 760) {
      if (categoryPanel) categoryPanel.hidden = true;
      categoryTrigger?.setAttribute('aria-expanded', 'false');
      categoryMenu.classList.remove('is-open');
    }
  });
  categoryList?.addEventListener('click', event => {
    const button = event.target.closest('.storefront-category-expand');
    if (!button) return;
    const expanded = button.getAttribute('aria-expanded') === 'true';
    button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    const panel = document.getElementById(button.getAttribute('aria-controls'));
    if (panel) panel.hidden = expanded;
  });
  categorySearch?.addEventListener('input', () => {
    const term = categorySearch.value.trim().toLocaleLowerCase();
    categoryList?.querySelectorAll('[data-category-entry]').forEach(entry => {
      const visible = !term || entry.dataset.categoryEntry.includes(term);
      entry.hidden = !visible;
    });
    const allLink = categoryList?.querySelector(':scope > a');
    if (allLink) allLink.hidden = !!term && !allLink.dataset.storeCategory.includes(term);
  });
  categoryList?.addEventListener('click', event => {
    if (event.target.closest('a')) setOpen(false);
  });

  const cacheKey = 'smartisp-storefront-categories-v1';
  try {
    const cached = JSON.parse(sessionStorage.getItem(cacheKey) || 'null');
    if (cached && Date.now() - cached.savedAt < 5 * 60 * 1000) renderCategories(cached.categories);
  } catch {}
  fetch('/api/auth/catalog?page=1&limit=1', { cache: 'no-store' })
    .then(response => response.ok ? response.json() : null)
    .then(data => {
      if (!Array.isArray(data?.categories) || !data.categories.length) return;
      renderCategories(data.categories);
      try { sessionStorage.setItem(cacheKey, JSON.stringify({ savedAt: Date.now(), categories: data.categories })); } catch {}
    })
    .catch(() => {});

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && menu.classList.contains('is-open')) setOpen(false);
  });
  window.addEventListener('resize', () => {
    if (window.innerWidth > 760) {
      setOpen(false);
      if (categoryPanel) categoryPanel.hidden = true;
      categoryTrigger?.setAttribute('aria-expanded', 'false');
    }
  }, { passive: true });
  if (window.lucide) window.lucide.createIcons();
})();
