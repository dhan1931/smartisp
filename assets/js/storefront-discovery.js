(() => {
  const menu = document.querySelector('#storefrontNavigation');
  const openButton = document.querySelector('.storefront-menu-button');
  const closeButton = document.querySelector('.storefront-menu-close');
  const categoryMenu = document.querySelector('#storefrontCategoryMenu');
  const categoryTrigger = document.querySelector('.storefront-category-trigger');
  const categoryPanel = document.querySelector('#storefrontCategoryPanel');
  if (!menu || !openButton) return;

  const setOpen = open => {
    menu.classList.toggle('is-open', open);
    document.body.classList.toggle('storefront-menu-open', open);
    openButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    openButton.setAttribute('aria-label', open ? 'Cerrar navegación' : 'Abrir navegación');
    if (window.lucide) window.lucide.createIcons();
  };

  const setCategoriesOpen = open => {
    if (!categoryMenu || !categoryTrigger || !categoryPanel) return;
    categoryMenu.classList.toggle('is-open', open);
    categoryTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    categoryPanel.hidden = !open;
  };

  openButton.addEventListener('click', () => setOpen(!menu.classList.contains('is-open')));
  closeButton?.addEventListener('click', () => setOpen(false));
  menu.querySelectorAll('a').forEach(link => link.addEventListener('click', () => setOpen(false)));
  categoryTrigger?.addEventListener('click', () => setCategoriesOpen(categoryPanel.hidden));
  document.addEventListener('click', event => {
    if (categoryMenu && !categoryMenu.contains(event.target)) setCategoriesOpen(false);
  });
  const categorySearch = categoryMenu?.querySelector('input[type="search"]');
  categorySearch?.addEventListener('input', () => {
    const term = categorySearch.value.trim().toLocaleLowerCase();
    categoryMenu.querySelectorAll('[data-store-category]').forEach(link => {
      link.hidden = !link.dataset.storeCategory.includes(term);
    });
  });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (categoryPanel && !categoryPanel.hidden) setCategoriesOpen(false);
    else if (menu.classList.contains('is-open')) setOpen(false);
  });
  window.addEventListener('resize', () => {
    if (window.innerWidth > 760) setOpen(false);
  }, { passive: true });
  if (window.lucide) window.lucide.createIcons();
})();
