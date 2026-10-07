/**
 * Sidebar compartido del área de administración (DEV-20261005-012/037-039 maqueta inicial;
 * DEV-20261006-010 lo convierte de navbar horizontal a sidebar fijo, por grupos, a partir del
 * mockup de referencia de la épica "Dashboard tipo SaaS").
 * Reemplaza los botones sueltos repetidos en cada página del panel por un solo componente, con
 * un único origen de verdad para la sesión: /api/auth/me (cookie de sesión del backend), no el
 * token en localStorage.
 *
 * Uso: <div id="smartisp-admin-nav" class="is-loading" data-active="catalogo"></div>
 *      <link rel="stylesheet" href="/assets/css/admin-nav.css">
 *      <script src="/assets/js/admin-nav.js" defer></script>
 *
 * Agregar una opción nueva al menú = agregar una entrada a LINKS (o un grupo nuevo a GROUPS).
 * Nada más que tocar: ninguna página individual define su propio menú.
 */
(function () {
  'use strict';

  // Grupos en el orden en que se muestran. 'key' no se renderiza; agrupa LINKS por su campo 'group'.
  var GROUPS = [
    { key: 'general', label: null },
    { key: 'operacion', label: 'Operación' },
    { key: 'catalogo', label: 'Catálogo' },
    { key: 'sistema', label: 'Sistema' }
  ];

  var LINKS = [
    { key: 'inicio', group: 'general', href: '/admin.html', label: 'Inicio', icon: 'home' },
    { key: 'pedidos', group: 'operacion', href: '/pedidos.html', label: 'Pedidos', icon: 'receipt' },
    { key: 'catalogo', group: 'catalogo', href: '/editor-catalogo.html', label: 'Catálogo', icon: 'package' },
    { key: 'categorias', group: 'catalogo', href: '/categorias.html', label: 'Categorías', icon: 'folder' },
    { key: 'config', group: 'sistema', href: '/configuracion.html', label: 'Configuración', icon: 'settings' }
  ];

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function buildLinkHtml(link, activeKey) {
    var classes = [];
    if (link.key === activeKey) classes.push('is-active');
    if (link.disabled) classes.push('is-disabled');
    var titleAttr = ' title="' + escapeHtml(link.title || link.label) + '"';
    return '<a href="' + link.href + '" class="' + classes.join(' ') + '"' + titleAttr + '>' +
      '<i data-lucide="' + link.icon + '" aria-hidden="true"></i><span class="label">' + escapeHtml(link.label) + '</span></a>';
  }

  function buildGroupsHtml(activeKey) {
    return GROUPS.map(function (group) {
      var links = LINKS.filter(function (l) { return l.group === group.key; });
      if (!links.length) return '';
      var heading = group.label ? '<div class="admin-nav-group-label">' + escapeHtml(group.label) + '</div>' : '';
      return '<div class="admin-nav-group">' + heading + links.map(function (l) { return buildLinkHtml(l, activeKey); }).join('') + '</div>';
    }).join('');
  }

  function render(root, user) {
    var activeKey = root.getAttribute('data-active') || '';
    var name = (user && (user.name || user.email)) || '';
    var role = user && user.role === 'admin' ? 'Administrador' : 'Cliente';
    var initial = escapeHtml((name || '?').trim().charAt(0).toUpperCase());
    var collapsed = false;

    try {
      collapsed = localStorage.getItem('smartisp.adminSidebarCollapsed') === '1';
    } catch (e) { /* almacenamiento bloqueado; se usa expandido */ }

    root.classList.remove('is-loading');
    document.body.classList.add('has-admin-sidebar');
    document.body.classList.toggle('admin-sidebar-collapsed', collapsed);
    root.innerHTML =
      '<div class="admin-nav-brand">' +
      '<i data-lucide="store" aria-hidden="true"></i>' +
      '<div><strong>SmartISP</strong><span>Panel Administrativo</span></div>' +
      '<button type="button" class="admin-nav-toggle" title="' + (collapsed ? 'Expandir menú' : 'Contraer menú') + '" aria-label="' + (collapsed ? 'Expandir menú' : 'Contraer menú') + '" aria-expanded="' + (!collapsed) + '">' +
      '<i data-lucide="' + (collapsed ? 'panel-left-open' : 'panel-left-close') + '" aria-hidden="true"></i>' +
      '</button>' +
      '</div>' +
      '<nav class="admin-nav-links">' + buildGroupsHtml(activeKey) + '</nav>' +
      '<div class="admin-nav-user">' +
      '<div class="admin-nav-user-row">' +
      '<span class="admin-nav-avatar">' + initial + '</span>' +
      '<div class="admin-nav-user-info"><strong>' + escapeHtml(name) + '</strong><span>' + role + '</span></div>' +
      '</div>' +
      '<a class="admin-nav-storelink" href="/tienda.html" title="Ver tienda"><i data-lucide="external-link" aria-hidden="true"></i><span>Ver tienda</span></a>' +
      '<button type="button" class="admin-nav-logout" title="Cerrar sesión"><i data-lucide="log-out" aria-hidden="true"></i><span>Cerrar sesión</span></button>' +
      '</div>';

    if (window.lucide) window.lucide.createIcons();

    root.querySelector('.admin-nav-toggle').addEventListener('click', function () {
      var nextCollapsed = !document.body.classList.contains('admin-sidebar-collapsed');
      document.body.classList.toggle('admin-sidebar-collapsed', nextCollapsed);
      this.setAttribute('title', nextCollapsed ? 'Expandir menú' : 'Contraer menú');
      this.setAttribute('aria-label', nextCollapsed ? 'Expandir menú' : 'Contraer menú');
      this.setAttribute('aria-expanded', String(!nextCollapsed));
      this.innerHTML = '<i data-lucide="' + (nextCollapsed ? 'panel-left-open' : 'panel-left-close') + '" aria-hidden="true"></i>';
      try {
        localStorage.setItem('smartisp.adminSidebarCollapsed', nextCollapsed ? '1' : '0');
      } catch (e) { /* almacenamiento bloqueado; no es critico */ }
      if (window.lucide) window.lucide.createIcons();
    });

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
