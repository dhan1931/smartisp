(() => {
  'use strict';

  const $ = selector => document.querySelector(selector);
  const state = {
    page: Math.max(1, Number(new URLSearchParams(location.search).get('page')) || 1),
    limit: 24,
    category: new URLSearchParams(location.search).get('category') || '',
    subcategory: new URLSearchParams(location.search).get('subcategory') || '',
    macro: new URLSearchParams(location.search).get('macro') || '',
    query: new URLSearchParams(location.search).get('q') || '',
    minPrice: new URLSearchParams(location.search).get('min_price') || '',
    maxPrice: new URLSearchParams(location.search).get('max_price') || '',
    sort: new URLSearchParams(location.search).get('sort') || 'relevance',
    total: 0,
    totalPages: 1,
    categories: [],
    catalogTotal: 0,
    groups: [],
    listView: false
  };
  if (!['relevance', 'low', 'high'].includes(state.sort)) state.sort = 'relevance';

  const safeIcon = value => /^[a-z0-9-]+$/i.test(String(value || '')) ? String(value) : 'package';
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const money = value => Number(value) > 0 ? '$' + Number(value).toLocaleString('es-EC', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : 'Cotizar';
  const productSlug = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 90);
  const imageIsSafe = value => {
    const image = String(value || '').trim();
    return (image.startsWith('/') && !image.startsWith('//') && !image.includes('..')) || /^https:\/\//i.test(image);
  };
  const icon = (name, attrs = '') => `<i data-lucide="${safeIcon(name)}" ${attrs}></i>`;
  const refreshIcons = () => window.lucide?.createIcons();

  const readCategoryList = () => state.categories
    .filter(category => !state.macro || category.macroGroupId === state.macro)
    .map(category => category.name);

  const updateUrl = () => {
    const params = new URLSearchParams();
    if (state.macro) params.set('macro', state.macro);
    if (state.category) params.set('category', state.category);
    if (state.subcategory) params.set('subcategory', state.subcategory);
    if (state.query) params.set('q', state.query);
    if (state.minPrice) params.set('min_price', state.minPrice);
    if (state.maxPrice) params.set('max_price', state.maxPrice);
    if (state.sort !== 'relevance') params.set('sort', state.sort);
    if (state.page > 1) params.set('page', String(state.page));
    const query = params.toString();
    history.replaceState(null, '', `${location.pathname}${query ? `?${query}` : ''}#catalogo`);
  };

  const groupCategories = categories => {
    const groups = new Map();
    categories.forEach(category => {
      const id = String(category.macroGroupId || `category:${category.name}`);
      const name = String(category.macroGroupName || category.name);
      if (!groups.has(id)) groups.set(id, { id, name, icon: category.macroGroupIcon || category.icon, count: 0, categories: [] });
      const group = groups.get(id);
      group.count += Number(category.productCount || 0);
      group.categories.push(category);
    });
    return [...groups.values()].sort((a, b) => b.count - a.count || a.name.localeCompare(b.name, 'es'));
  };

  const renderFamilies = () => {
    const rail = $('#categoryFamilyRail');
    const families = state.groups;
    rail.innerHTML = families.map(group => {
      const representative = group.categories.find(category => imageIsSafe(category.bannerImageUrl)) || group.categories[0];
      const image = imageIsSafe(representative?.bannerImageUrl)
        ? `<img src="${escapeHtml(representative.bannerImageUrl)}" alt="" loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false">${icon(group.icon, 'class="family-tile-icon" hidden')}`
        : `<span class="family-tile-icon">${icon(group.icon)}</span>`;
      return `<button class="family-tile${state.macro === group.id ? ' is-active' : ''}" type="button" data-family="${escapeHtml(group.id)}" aria-pressed="${state.macro === group.id}">${image}<strong>${escapeHtml(group.name)}</strong><small>${group.count.toLocaleString('es-EC')} productos</small></button>`;
    }).join('');
    rail.querySelectorAll('[data-family]').forEach(button => button.addEventListener('click', () => {
      state.macro = state.macro === button.dataset.family ? '' : button.dataset.family;
      state.category = ''; state.subcategory = ''; state.page = 1;
      updateUrl(); renderFamilies(); renderSidebar(); loadProducts();
    }));
    refreshIcons();
  };

  const categoryIsActive = name => state.category === name;
  const renderSidebar = () => {
    $('#catalogProductTotal').textContent = (state.catalogTotal || state.total).toLocaleString('es-EC');
    $('#showAllCategories').classList.toggle('is-active', !state.macro && !state.category && !state.subcategory);
    const categories = state.categories.filter(category => !state.macro || category.macroGroupId === state.macro);
    $('#categoryFilterList').innerHTML = categories.map((category, index) => {
      const subs = Array.isArray(category.subcategories) ? category.subcategories.filter(Boolean) : [];
      const subsId = `category-subs-${index}`;
      return `<section class="catalog-category-entry" data-category-search="${escapeHtml(`${category.name} ${subs.join(' ')}`.toLocaleLowerCase())}">
        <div class="catalog-category-row"><button class="catalog-category-select${categoryIsActive(category.name) ? ' is-active' : ''}" type="button" data-category="${escapeHtml(category.name)}">${icon(category.icon)}<span>${escapeHtml(category.name)}</span><small>${Number(category.productCount || 0).toLocaleString('es-EC')}</small></button>${subs.length ? `<button class="catalog-category-expand" type="button" aria-label="Ver subcategorías de ${escapeHtml(category.name)}" aria-expanded="${state.category === category.name}" aria-controls="${subsId}">${icon('chevron-down')}</button>` : ''}</div>
        ${subs.length ? `<div class="catalog-subcategories" id="${subsId}" ${state.category === category.name ? '' : 'hidden'}>${subs.map(sub => `<button class="catalog-subcategory-select${state.subcategory === sub ? ' is-active' : ''}" type="button" data-category="${escapeHtml(category.name)}" data-subcategory="${escapeHtml(sub)}">${escapeHtml(sub)}</button>`).join('')}</div>` : ''}
      </section>`;
    }).join('') || '<div class="catalog-loading">No hay categorías disponibles.</div>';

    $('#categoryFilterList').querySelectorAll('[data-category]:not([data-subcategory])').forEach(button => button.addEventListener('click', () => {
      state.category = state.category === button.dataset.category ? '' : button.dataset.category;
      state.subcategory = ''; state.macro = ''; state.page = 1;
      updateUrl(); renderFamilies(); renderSidebar(); loadProducts();
    }));
    $('#categoryFilterList').querySelectorAll('[data-subcategory]').forEach(button => button.addEventListener('click', () => {
      state.category = button.dataset.category; state.subcategory = button.dataset.subcategory; state.macro = ''; state.page = 1;
      updateUrl(); renderFamilies(); renderSidebar(); loadProducts();
    }));
    $('#categoryFilterList').querySelectorAll('.catalog-category-expand').forEach(button => button.addEventListener('click', () => {
      const panel = document.getElementById(button.getAttribute('aria-controls'));
      const expanded = button.getAttribute('aria-expanded') === 'true';
      button.setAttribute('aria-expanded', String(!expanded));
      panel.hidden = expanded;
    }));
    refreshIcons();
  };

  const renderHeroProducts = () => {
    const items = state.categories.filter(category => imageIsSafe(category.bannerImageUrl)).slice(0, 3);
    if (!items.length) return;
    $('#categoryHeroProducts').innerHTML = items.map(category => `<div class="hero-category-product"><img src="${escapeHtml(category.bannerImageUrl)}" alt="${escapeHtml(category.bannerAlt || category.name)}" loading="lazy"></div>`).join('');
  };

  const productCard = product => {
    const id = String(product.id || '');
    const name = String(product.name || 'Producto SmartISP');
    const image = String(product.imageUrl || '');
    const productUrl = `/producto/${encodeURIComponent(id)}-${productSlug(name)}`;
    const cartProduct = { id, name, price: Number(product.price || 0), category: product.category || '', image, imageUrl: image, sku: product.sku || '' };
    const encoded = btoa(Array.from(new TextEncoder().encode(JSON.stringify(cartProduct)), byte => String.fromCharCode(byte)).join(''));
    const description = String(product.description || product.subcategory || '').trim();
    return `<article class="category-product-card">
      <a class="category-product-media" href="${productUrl}" aria-label="Ver ${escapeHtml(name)}">${imageIsSafe(image) ? `<img src="${escapeHtml(image)}" alt="${escapeHtml(name)}" loading="lazy" onerror="this.hidden=true">` : ''}</a>
      <div class="category-product-copy"><span class="category-product-category">${escapeHtml(product.category || 'SmartISP')}</span><h2><a href="${productUrl}">${escapeHtml(name)}</a></h2><p class="category-product-description">${escapeHtml(description)}</p><strong class="category-product-price">${money(product.price)}</strong>${product.sku ? `<span class="category-product-sku">SKU: ${escapeHtml(product.sku)}</span>` : ''}<button class="category-product-add" type="button" data-add-to-cart="${encoded}">${icon('shopping-cart')}Agregar</button></div>
    </article>`;
  };

  const renderPagination = () => {
    const nav = $('#categoryPagination');
    if (state.totalPages <= 1) { nav.innerHTML = ''; return; }
    const start = Math.max(1, Math.min(state.page - 2, state.totalPages - 4));
    const end = Math.min(state.totalPages, start + 4);
    const buttons = [];
    buttons.push(`<button type="button" data-page="${state.page - 1}" ${state.page <= 1 ? 'disabled' : ''} aria-label="Página anterior">${icon('chevron-left')}</button>`);
    for (let page = start; page <= end; page++) buttons.push(`<button type="button" data-page="${page}" ${page === state.page ? 'aria-current="page"' : ''}>${page}</button>`);
    buttons.push(`<button type="button" data-page="${state.page + 1}" ${state.page >= state.totalPages ? 'disabled' : ''} aria-label="Página siguiente">${icon('chevron-right')}</button>`);
    nav.innerHTML = buttons.join('');
    nav.querySelectorAll('[data-page]').forEach(button => button.addEventListener('click', () => {
      const page = Number(button.dataset.page);
      if (page < 1 || page > state.totalPages) return;
      state.page = page; updateUrl(); loadProducts();
      $('#catalogo').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));
    refreshIcons();
  };

  const loadProducts = async () => {
    const grid = $('#categoryProductsGrid');
    grid.innerHTML = '<div class="catalog-loading">Cargando productos…</div>';
    const params = new URLSearchParams({ page: String(state.page), limit: String(state.limit), details: '1' });
    if (state.category) params.set('category', state.category);
    if (state.subcategory) params.set('subcategory', state.subcategory);
    if (!state.category && state.macro) params.set('category_list', JSON.stringify(readCategoryList()));
    if (state.query) params.set('q', state.query);
    if (state.minPrice) params.set('min_price', state.minPrice);
    if (state.maxPrice) params.set('max_price', state.maxPrice);
    if (state.sort === 'low' || state.sort === 'high') params.set('sort', state.sort);

    try {
      const response = await fetch(`/api/auth/catalog?${params.toString()}`, { cache: 'no-store' });
      if (!response.ok) throw new Error(`No se pudo cargar el catálogo (${response.status}).`);
      const data = await response.json();
      state.total = Number(data.total || 0);
      state.totalPages = Math.max(1, Number(data.totalPages || 1));
      if (Array.isArray(data.categories) && data.categories.length) {
        state.categories = data.categories.filter(category => String(category.name || '').trim() && Number(category.productCount || 0) > 0);
        state.groups = groupCategories(state.categories);
        state.catalogTotal = state.groups.reduce((sum, group) => sum + group.count, 0);
        if (!state.category && !state.subcategory && !state.macro && !state.query && !state.minPrice && !state.maxPrice) state.catalogTotal = state.total;
        if (state.macro && !state.groups.some(group => group.id === state.macro)) state.macro = '';
        renderFamilies(); renderSidebar(); renderHeroProducts();
      }
      const products = Array.isArray(data.products) ? data.products : [];
      const first = (state.page - 1) * state.limit + 1;
      const last = Math.min(state.page * state.limit, state.total);
      $('#categoryResultCount').textContent = state.total ? `Mostrando ${first}–${last} de ${state.total.toLocaleString('es-EC')} productos` : 'No hay productos con estos filtros';
      $('#activeCategoryLabel').hidden = !(state.category || state.subcategory || state.macro);
      $('#activeCategoryLabel').textContent = state.subcategory || state.category || state.groups.find(group => group.id === state.macro)?.name || '';
      grid.innerHTML = products.length ? products.map(productCard).join('') : '<div class="category-product-empty">No encontramos productos con esta selección. Prueba otra categoría o limpia los filtros.</div>';
      grid.classList.toggle('is-list', state.listView);
      renderPagination();
      renderSidebar();
      refreshIcons();
    } catch (error) {
      grid.innerHTML = `<div class="category-product-empty">${escapeHtml(error.message)} <button type="button" id="retryCatalog">Reintentar</button></div>`;
      $('#retryCatalog')?.addEventListener('click', loadProducts);
    }
  };

  const loadCategoriesIfNeeded = async () => {
    if (state.categories.length) return;
    try {
      const response = await fetch('/api/auth/catalog?page=1&limit=1', { cache: 'no-store' });
      if (!response.ok) return;
      const data = await response.json();
      state.categories = (data.categories || []).filter(category => String(category.name || '').trim() && Number(category.productCount || 0) > 0);
      state.groups = groupCategories(state.categories);
      renderFamilies(); renderSidebar(); renderHeroProducts();
    } catch {}
  };

  $('#showAllCategories').addEventListener('click', () => {
    state.category = ''; state.subcategory = ''; state.macro = ''; state.page = 1;
    updateUrl(); renderFamilies(); renderSidebar(); loadProducts();
  });
  $('#clearCategoryFilters').addEventListener('click', () => {
    state.category = ''; state.subcategory = ''; state.macro = ''; state.query = ''; state.minPrice = ''; state.maxPrice = ''; state.page = 1;
    $('#productSearch').value = ''; $('#minPrice').value = ''; $('#maxPrice').value = '';
    updateUrl(); renderFamilies(); renderSidebar(); loadProducts();
  });
  $('#categorySearch').addEventListener('input', event => {
    const query = event.target.value.trim().toLocaleLowerCase();
    $('#categoryFilterList').querySelectorAll('[data-category-search]').forEach(entry => { entry.hidden = query !== '' && !entry.dataset.categorySearch.includes(query); });
  });
  let productSearchTimer;
  $('#productSearch').addEventListener('input', event => {
    clearTimeout(productSearchTimer);
    productSearchTimer = setTimeout(() => {
      state.query = event.target.value.trim(); state.page = 1; updateUrl(); loadProducts();
    }, 300);
  });
  $('#applyPriceFilter').addEventListener('click', () => {
    const min = $('#minPrice').value.trim(); const max = $('#maxPrice').value.trim();
    if ((min && (!Number.isFinite(Number(min)) || Number(min) < 0)) || (max && (!Number.isFinite(Number(max)) || Number(max) < 0)) || (min && max && Number(min) > Number(max))) {
      $('#minPrice').setCustomValidity('Revisa los límites: el mínimo no puede superar al máximo.'); $('#minPrice').reportValidity(); return;
    }
    $('#minPrice').setCustomValidity(''); state.minPrice = min; state.maxPrice = max; state.page = 1;
    updateUrl(); loadProducts();
  });
  $('#categorySort').addEventListener('change', event => { state.sort = event.target.value; state.page = 1; updateUrl(); loadProducts(); });
  $('#gridViewButton').addEventListener('click', () => {
    state.listView = false; $('#categoryProductsGrid').classList.remove('is-list');
    $('#gridViewButton').classList.add('is-active'); $('#gridViewButton').setAttribute('aria-pressed', 'true');
    $('#listViewButton').classList.remove('is-active'); $('#listViewButton').setAttribute('aria-pressed', 'false');
  });
  $('#listViewButton').addEventListener('click', () => {
    state.listView = true; $('#categoryProductsGrid').classList.add('is-list');
    $('#listViewButton').classList.add('is-active'); $('#listViewButton').setAttribute('aria-pressed', 'true');
    $('#gridViewButton').classList.remove('is-active'); $('#gridViewButton').setAttribute('aria-pressed', 'false');
  });

  const params = new URLSearchParams(location.search);
  $('#productSearch').value = state.query;
  $('#categorySort').value = ['relevance', 'low', 'high'].includes(state.sort) ? state.sort : 'relevance';
  $('#minPrice').value = state.minPrice; $('#maxPrice').value = state.maxPrice;
  loadCategoriesIfNeeded().finally(loadProducts);
})();
