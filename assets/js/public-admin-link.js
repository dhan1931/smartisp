(() => {
  'use strict';

  const actions = document.querySelector('.storefront-actions, .nav-actions');
  if (!actions) return;

  const link = actions.querySelector('#adminNavLink') || (() => {
    const anchor = document.createElement('a');
    anchor.className = 'storefront-admin-link';
    anchor.href = '/admin.html';
    anchor.hidden = true;
    anchor.setAttribute('aria-label', 'Abrir panel de administración');
    anchor.title = 'Panel de administración';
    const icon = document.createElement('i');
    icon.dataset.lucide = 'layout-dashboard';
    icon.setAttribute('aria-hidden', 'true');
    const label = document.createElement('span');
    label.textContent = 'Panel admin';
    anchor.append(icon, label);
    const cart = actions.querySelector('.storefront-cart, #openCart');
    actions.insertBefore(anchor, cart || null);
    return anchor;
  })();

  const setVisible = visible => {
    if (link.classList.contains('admin-nav-link')) {
      link.classList.toggle('is-visible', visible);
      link.style.setProperty('display', visible ? 'inline-flex' : 'none', 'important');
    } else {
      link.hidden = !visible;
    }
  };
  setVisible(false);

  const headers = {};
  try {
    const token = localStorage.getItem('smartisp.adminToken') || sessionStorage.getItem('smartisp.adminToken');
    if (token) headers.Authorization = `Bearer ${token}`;
  } catch {}

  fetch('/api/auth/me', { credentials: 'include', cache: 'no-store', headers })
    .then(response => response.ok ? response.json() : null)
    .then(data => {
      const role = String(data?.user?.role || '').trim().toLowerCase();
      setVisible(['admin', 'administrator', 'administrador'].includes(role));
      if (window.lucide) window.lucide.createIcons();
    })
    .catch(() => setVisible(false));
})();
