/**
 * Navbar compartido del área de administración (DEV-20261005-012 / 037-039, maqueta inicial).
 * Reemplaza los botones sueltos repetidos en admin.html, editor-catalogo.html y
 * editor-landing.html por una sola barra, con un único origen de verdad para la sesión:
 * /api/auth/me (cookie de sesión del backend), no el token en localStorage.
 *
 * Uso: <div id="smartisp-admin-nav" class="is-loading" data-active="catalogo"></div>
 *      <link rel="stylesheet" href="/assets/css/admin-nav.css">
 *      <script src="/assets/js/admin-nav.js" defer></script>
 */
(function () {
  'use strict';

  var LINKS = [
    { key: 'inicio', href: '/admin.html', label: 'Inicio', icon: '🏠' },
    { key: 'catalogo', href: '/editor-catalogo.html', label: 'Catálogo', icon: '📦' },
    { key: 'contenido', href: '/editor-landing.html', label: 'Contenido', icon: '🎨' },
    { key: 'pedidos', href: '#', label: 'Pedidos', icon: '🧾', disabled: true, title: 'Próximamente (DEV-20261005-019)' }
  ];

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function buildLinksHtml(activeKey) {
    return LINKS.map(function (link) {
      var classes = [];
      if (link.key === activeKey) classes.push('is-active');
      if (link.disabled) classes.push('is-disabled');
      var titleAttr = link.title ? ' title="' + escapeHtml(link.title) + '"' : '';
      return '<a href="' + link.href + '" class="' + classes.join(' ') + '"' + titleAttr + '>' +
        '<span aria-hidden="true">' + link.icon + '</span><span class="label">' + escapeHtml(link.label) + '</span></a>';
    }).join('');
  }

  function render(root, user) {
    var activeKey = root.getAttribute('data-active') || '';
    var name = (user && (user.name || user.email)) || '';
    var role = user && user.role === 'admin' ? 'Administrador' : 'Cliente';

    root.classList.remove('is-loading');
    root.innerHTML =
      '<div class="admin-nav-inner">' +
      '<a class="admin-nav-brand" href="/admin.html">🌐 SmartISP · Panel</a>' +
      '<div class="admin-nav-links">' + buildLinksHtml(activeKey) + '</div>' +
      '<div class="admin-nav-user">' +
      '<span><strong>' + escapeHtml(name) + '</strong> · ' + role + '</span>' +
      '<a class="button secondary" href="/tienda.html" style="padding:6px 12px;border-radius:7px;color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.3);font-size:12px;font-weight:700;">Ver tienda</a>' +
      '<button type="button" class="admin-nav-logout">Cerrar sesión</button>' +
      '</div>' +
      '</div>';

    root.querySelector('.admin-nav-logout').addEventListener('click', function () {
      fetch('/api/auth/logout', { method: 'POST', credentials: 'include' }).finally(function () {
        try {
          localStorage.removeItem('smartisp.adminToken');
          localStorage.removeItem('smartisp.accountUser');
          localStorage.removeItem('smartisp.accountRemembered');
          sessionStorage.removeItem('smartisp.adminToken');
          sessionStorage.removeItem('smartisp.accountUser');
        } catch (e) { /* almacenamiento bloqueado (modo privado); no es crítico */ }
        window.location.href = '/login.html';
      });
    });
  }

  function redirectToLogin() {
    var back = encodeURIComponent(window.location.pathname + window.location.search);
    window.location.href = '/login.html?redirect=' + back;
  }

  function init() {
    var root = document.getElementById('smartisp-admin-nav');
    if (!root) return;

    fetch('/api/auth/me', { credentials: 'include', cache: 'no-store' })
      .then(function (res) { return res.ok ? res.json() : { user: null }; })
      .then(function (data) {
        var user = data && data.user;
        if (!user || user.role !== 'admin') {
          redirectToLogin();
          return;
        }
        // Puente transicional (DEV-20261005-002 aún pendiente): hasta que todas las páginas del
        // panel dejen de leer localStorage, se mantiene la misma clave sincronizada con la sesión
        // real. La fuente de verdad del rol sigue siendo /api/auth/me, no este valor guardado.
        try {
          if (data.token) localStorage.setItem('smartisp.adminToken', data.token);
          localStorage.setItem('smartisp.accountUser', JSON.stringify(user));
        } catch (e) { /* almacenamiento bloqueado; no es critico para el navbar */ }
        render(root, user);
      })
      .catch(function () { redirectToLogin(); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
