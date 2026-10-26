/**
 * Sidebar compartido del área de administración (DEV-20261005-012/037-039 maqueta inicial;
 * DEV-20261006-010 lo convierte de navbar horizontal a sidebar fijo, por grupos, a partir del
 * mockup de referencia de la épica "Dashboard tipo SaaS").
 * Reemplaza los botones sueltos repetidos en cada página del panel por un solo componente, con
 * un único origen de verdad para la sesión: /api/auth/me (cookie de sesión del backend), no el
 * token en localStorage.
 *
 * Uso: <div id="smartisp-admin-nav" class="is-loading" data-active="productos"></div>
 *      <link rel="stylesheet" href="/assets/css/admin/admin-nav.css">
 *      <script src="/assets/js/admin/admin-nav.js" defer></script>
 *
 * Agregar una opción nueva al menú = agregar una entrada a LINKS (o un grupo nuevo a GROUPS).
 * Nada más que tocar: ninguna página individual define su propio menú.
 */
(function () {
  'use strict';

  // Grupos en el orden en que se muestran. 'key' no se renderiza; agrupa LINKS por su campo 'group'.
  // Estructura alineada a la epica "Dashboard tipo SaaS" (mockup de referencia): Productos,
  // Categorias e Inventario son conceptos separados, no una sola pantalla de "Catalogo".
  var GROUPS = [
    { key: 'general', label: null },
    { key: 'operacion', label: 'Operación' },
    { key: 'catalogo', label: 'Catálogo' },
    { key: 'tienda', label: 'Tienda' },
    { key: 'analisis', label: 'Análisis' },
    { key: 'sistema', label: 'Sistema' }
  ];

  // Los 'disabled: true' son pantallas del mockup que todavia no existen (sin pagina real
  // detras): se muestran para comunicar la estructura objetivo, pero no son un link muerto --
  // admin-nav.css les quita el click (pointer-events: none) y las atenua visualmente.
  var LINKS = [
    { key: 'inicio', group: 'general', href: '/admin.html', label: 'Inicio', icon: 'home' },
    { key: 'pedidos', group: 'operacion', href: '/pedidos.html', label: 'Pedidos', icon: 'receipt' },
    { key: 'cotizaciones', group: 'operacion', href: '/cotizaciones.html', label: 'Cotizaciones', icon: 'file-text' },
    { key: 'clientes', group: 'operacion', href: '/clientes.html', label: 'Clientes', icon: 'users' },
    { key: 'productos', group: 'catalogo', href: '/editor-catalogo.html', label: 'Productos', icon: 'package' },
    { key: 'categorias', group: 'catalogo', href: '/categorias.html', label: 'Categorías', icon: 'folder' },
    { key: 'inventario', group: 'catalogo', href: '/inventario.html', label: 'Inventario', icon: 'boxes' },
    { key: 'contenido', group: 'tienda', href: '/editor-landing.html', label: 'Contenido', icon: 'layout-template' },
    { key: 'portada', group: 'tienda', href: '/portada.html', label: 'Portada', icon: 'layout-dashboard' },
    { key: 'campanas', group: 'tienda', href: '/campanas.html', label: 'Campañas y banners', icon: 'megaphone' },
    { key: 'estadisticas', group: 'analisis', href: '/estadisticas.html', label: 'Estadísticas', icon: 'bar-chart-3' },
    { key: 'reportes', group: 'analisis', href: '/reportes.html', label: 'Reportes', icon: 'file-down' },
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
    var currentAttr = link.key === activeKey ? ' aria-current="page"' : '';
    return '<a href="' + link.href + '" class="' + classes.join(' ') + '"' + titleAttr + currentAttr + '>' +
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

  function loadConfiguredLogo(root) {
    fetch('/api/auth/site-content', { credentials: 'include', cache: 'no-store' })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        if (!data || !Array.isArray(data.content)) return;
        var content = Object.fromEntries(data.content.map(function (item) { return [item.key, item.value]; }));
        var logo = root.querySelector('.admin-nav-logo');
        if (!logo) return;

        var mode = content.logo_type || content.landing_logo_type || 'image';
        var brand = content.landing_logo_text || content.brand_name || content.logo_text || 'SmartISP';
        var image = content.logo_image || content.landing_logo_image || '/assets/img/logo-default.jpg';
        if (/\/assets\/img\/logo\.(png|webp)(\?|$)/.test(image)) image = '/assets/img/logo-default.jpg';
        var height = parseInt(content.landing_logo_height || content.logo_height, 10) || 38;
        logo.replaceChildren();
        if (mode === 'image' && image) {
          var img = document.createElement('img');
          img.src = image;
          img.alt = brand;
          img.width = 300;
          img.height = 100;
          img.style.height = height + 'px';
          logo.appendChild(img);
          return;
        }

        var text = content.landing_logo_text || content.logo_text || brand;
        var value = String(text || 'SmartISP').trim() || 'SmartISP';
        var splitAt = Math.ceil(value.length / 2);
        logo.appendChild(document.createTextNode(value.slice(0, splitAt)));
        var bold = document.createElement('b');
        bold.textContent = value.slice(splitAt);
        logo.appendChild(bold);
        var dot = document.createElement('span');
        dot.className = 'admin-nav-logo-dot';
        dot.textContent = '.';
        logo.appendChild(dot);
      })
      .catch(function () { /* Se conserva el nombre de marca de respaldo. */ });
  }

  // render() puede volver a correr (Reintentar): el fondo y los listeners de document se crean una sola vez
  // y apuntan al menú vigente.
  var currentMenu = null;
  var documentListenersBound = false;
  var initInFlight = false;

  function bindDocumentListeners() {
    if (documentListenersBound) return;
    documentListenersBound = true;
    document.addEventListener('click', function (event) {
      if (currentMenu && currentMenu.root.classList.contains('is-mobile-open') && !currentMenu.root.contains(event.target)) currentMenu.setOpen(false);
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && currentMenu && currentMenu.root.classList.contains('is-mobile-open')) {
        currentMenu.setOpen(false);
        currentMenu.toggle.focus();
      }
    });
  }

  function render(root, user) {
    var activeKey = root.getAttribute('data-active') || '';
    var name = (user && (user.name || user.email)) || '';
    var role = user && ['admin', 'administrator', 'administrador'].indexOf(String(user.role || '').toLowerCase()) >= 0 ? 'Administrador' : 'Cliente';
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
      '<button type="button" class="admin-nav-mobile-toggle" title="Abrir menú" aria-label="Abrir menú" aria-expanded="false" aria-controls="admin-nav-links"><i data-lucide="menu" aria-hidden="true"></i></button>' +
      '<span class="admin-nav-logo"><img src="/assets/img/logo-default.jpg" alt="SmartISP" width="1280" height="426"></span>' +
      '<div><strong>Panel Administrativo</strong></div>' +
      '<button type="button" class="admin-nav-toggle" title="' + (collapsed ? 'Expandir barra lateral' : 'Contraer barra lateral') + '" aria-label="' + (collapsed ? 'Expandir barra lateral' : 'Contraer barra lateral') + '" aria-controls="admin-nav-links" aria-expanded="' + (!collapsed) + '">' +
      '<i data-lucide="' + (collapsed ? 'panel-left-open' : 'panel-left-close') + '" aria-hidden="true"></i>' +
      '</button>' +
      '</div>' +
      '<nav class="admin-nav-links" id="admin-nav-links" aria-label="Navegación administrativa">' + buildGroupsHtml(activeKey) + '</nav>' +
      '<div class="admin-nav-user">' +
      '<div class="admin-nav-user-row">' +
      '<span class="admin-nav-avatar">' + initial + '</span>' +
      '<div class="admin-nav-user-info"><strong>' + escapeHtml(name) + '</strong><span>' + role + '</span></div>' +
      '</div>' +
      '<a class="admin-nav-storelink" href="/tienda.html" title="Ver tienda"><i data-lucide="external-link" aria-hidden="true"></i><span>Ver tienda</span></a>' +
      '<button type="button" class="admin-nav-logout" title="Cerrar sesión"><i data-lucide="log-out" aria-hidden="true"></i><span>Cerrar sesión</span></button>' +
      '</div>';

    if (window.lucide) window.lucide.createIcons();

    loadConfiguredLogo(root);
    root.querySelector('.admin-nav-toggle').addEventListener('click', function (event) {
      event.stopPropagation();
      var nextCollapsed = !document.body.classList.contains('admin-sidebar-collapsed');
      document.body.classList.toggle('admin-sidebar-collapsed', nextCollapsed);
      this.setAttribute('title', nextCollapsed ? 'Expandir barra lateral' : 'Contraer barra lateral');
      this.setAttribute('aria-label', nextCollapsed ? 'Expandir barra lateral' : 'Contraer barra lateral');
      this.setAttribute('aria-expanded', String(!nextCollapsed));
      this.innerHTML = '<i data-lucide="' + (nextCollapsed ? 'panel-left-open' : 'panel-left-close') + '" aria-hidden="true"></i>';
      try {
        localStorage.setItem('smartisp.adminSidebarCollapsed', nextCollapsed ? '1' : '0');
      } catch (e) { /* almacenamiento bloqueado; no es critico */ }
      if (window.lucide) window.lucide.createIcons();
    });

    var mobileToggle = root.querySelector('.admin-nav-mobile-toggle');
    var brand = root.querySelector('.admin-nav-brand');
    var backdrop = document.querySelector('.admin-nav-backdrop');
    if (!backdrop) {
      backdrop = document.createElement('button');
      backdrop.type = 'button';
      backdrop.className = 'admin-nav-backdrop';
      backdrop.setAttribute('aria-label', 'Cerrar menú');
      backdrop.tabIndex = -1;
      root.insertAdjacentElement('afterend', backdrop);
      backdrop.addEventListener('click', function () { if (currentMenu) currentMenu.setOpen(false); });
    }
    var setMobileOpen = function (open) {
      root.classList.toggle('is-mobile-open', open);
      mobileToggle.setAttribute('aria-expanded', String(open));
      mobileToggle.setAttribute('aria-label', open ? 'Cerrar menú' : 'Abrir menú');
      mobileToggle.setAttribute('title', open ? 'Cerrar menú' : 'Abrir menú');
      mobileToggle.innerHTML = '<i data-lucide="' + (open ? 'x' : 'menu') + '" aria-hidden="true"></i>';
      if (window.lucide) window.lucide.createIcons();
    };
    mobileToggle.addEventListener('click', function (event) {
      event.stopPropagation();
      setMobileOpen(!root.classList.contains('is-mobile-open'));
    });
    brand.addEventListener('click', function (event) {
      if (event.target.closest('button')) return;
      if (window.matchMedia('(max-width: 900px)').matches) {
        setMobileOpen(!root.classList.contains('is-mobile-open'));
      } else {
        root.querySelector('.admin-nav-toggle').click();
      }
    });
    root.querySelectorAll('.admin-nav-links a').forEach(function (link) {
      link.addEventListener('click', function () { setMobileOpen(false); });
    });
    currentMenu = { root: root, setOpen: setMobileOpen, toggle: mobileToggle };
    bindDocumentListeners();

    root.querySelector('.admin-nav-logout').addEventListener('click', function () {
      fetch('/api/auth/logout', { method: 'POST', credentials: 'include' }).finally(function () {
        try {
          localStorage.removeItem('smartisp.adminToken');
          localStorage.removeItem('smartisp.accountUser');
          localStorage.removeItem('smartisp.accountRemembered');
          sessionStorage.removeItem('smartisp.adminToken');
          sessionStorage.removeItem('smartisp.accountUser');
        } catch (e) { /* almacenamiento bloqueado (modo privado); no es crítico */ }
        window.location.href = '/login.html?admin=1';
      });
    });
  }

  function redirectToLogin() {
    var back = encodeURIComponent(window.location.pathname + window.location.search);
    window.location.href = '/login.html?admin=1&redirect=' + back;
  }

  function renderNotice(root, title, message, retry) {
    root.classList.remove('is-loading');
    root.classList.add('is-access-notice');
    document.body.classList.remove('has-admin-sidebar', 'admin-sidebar-collapsed');
    root.innerHTML = '<section class="admin-nav-notice" role="status"><i data-lucide="shield-alert" aria-hidden="true"></i>' +
      '<strong>' + escapeHtml(title) + '</strong><span>' + escapeHtml(message) + '</span>' +
      (retry ? '<button type="button" class="admin-nav-retry" aria-label="Reintentar" title="Reintentar"><i data-lucide="refresh-cw" aria-hidden="true"></i> Reintentar</button>' : '') +
      '<a href="/tienda.html"><i data-lucide="store" aria-hidden="true"></i> Ver tienda</a>' +
      '<a href="/login.html?admin=1&redirect=' + encodeURIComponent(window.location.pathname + window.location.search) + '"><i data-lucide="log-in" aria-hidden="true"></i> Iniciar sesión de administrador</a></section>';
    if (window.lucide) window.lucide.createIcons();
    var retryButton = root.querySelector('.admin-nav-retry');
    if (retryButton) retryButton.addEventListener('click', function () {
      if (initInFlight) return;
      retryButton.disabled = true;
      root.classList.remove('is-access-notice');
      root.classList.add('is-loading');
      init();
    });
  }

  function init() {
    var root = document.getElementById('smartisp-admin-nav');
    if (!root || initInFlight) return;
    initInFlight = true;

    var authHeaders = {};
    try {
      var token = localStorage.getItem('smartisp.adminToken') || sessionStorage.getItem('smartisp.adminToken');
      if (token) authHeaders.Authorization = 'Bearer ' + token;
    } catch (e) { /* cookies siguen siendo suficientes si el almacenamiento está bloqueado */ }

    fetch('/api/auth/me', { credentials: 'include', cache: 'no-store', headers: authHeaders })
      .finally(function () { initInFlight = false; })
      .then(function (res) {
        if (res.status === 401) { redirectToLogin(); return null; }
        if (!res.ok) throw new Error('No se pudo validar la sesión (' + res.status + ').');
        return res.json();
      })
      .then(function (data) {
        if (!data) return;
        var user = data && data.user;
        if (!user) {
          redirectToLogin();
          return;
        }
        if (['admin', 'administrator', 'administrador'].indexOf(String(user.role || '').toLowerCase()) < 0) {
          renderNotice(root, 'Acceso administrativo', 'Esta cuenta no tiene permisos de administrador.', false);
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
      .catch(function (error) { renderNotice(root, 'No se pudo validar la sesión', error.message || 'Comprueba tu conexión e inténtalo de nuevo.', true); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
