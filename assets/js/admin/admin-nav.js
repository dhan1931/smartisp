/**
 * Barra lateral del panel: grupos con íconos, colapsable, avisos con conteos, buscador global
 * (Ctrl+K) y preferencias de tema y densidad. La sesión sale de /api/auth/me.
 *
 * Uso: <div id="smartisp-admin-nav" class="is-loading" data-active="productos"></div>
 *      admin-theme.js (sin defer, en <head>) + admin-nav.css + admin-nav.js (defer).
 * Una pantalla nueva = una entrada en LINKS.
 */
(function () {
  'use strict';

  var GROUPS = [
    { key: 'general', label: null },
    { key: 'operacion', label: 'Operación' },
    { key: 'catalogo', label: 'Catálogo' },
    { key: 'tienda', label: 'Tienda' },
    { key: 'analisis', label: 'Análisis' },
    { key: 'sistema', label: 'Sistema' }
  ];

  var LINKS = [
    { key: 'inicio', group: 'general', href: '/admin.html', label: 'Inicio', icon: 'layout-dashboard', hint: 'Resumen y alertas', shortcut: 'i' },
    { key: 'pedidos', group: 'operacion', href: '/pedidos.html', label: 'Pedidos', icon: 'receipt', hint: 'Estados, pagos y envíos', shortcut: 'p', badge: 'orders' },
    { key: 'cotizaciones', group: 'operacion', href: '/cotizaciones.html', label: 'Cotizaciones', icon: 'file-text', hint: 'Solicitudes de servicios', shortcut: 'q', badge: 'quotes' },
    { key: 'clientes', group: 'operacion', href: '/clientes.html', label: 'Clientes', icon: 'users', hint: 'Cuentas y compradores', shortcut: 'c' },
    { key: 'productos', group: 'catalogo', href: '/editor-catalogo.html', label: 'Productos', icon: 'package', hint: 'Catálogo, precios e importación', shortcut: 'r' },
    { key: 'categorias', group: 'catalogo', href: '/categorias.html', label: 'Categorías', icon: 'folder-tree', hint: 'Familias y categorías' },
    { key: 'inventario', group: 'catalogo', href: '/inventario.html', label: 'Inventario', icon: 'boxes', hint: 'Stock y reservas', shortcut: 'n', badge: 'stock' },
    { key: 'contenido', group: 'tienda', href: '/editor-landing.html', label: 'Contenido', icon: 'layout-template', hint: 'Página de presentación' },
    { key: 'portada', group: 'tienda', href: '/portada.html', label: 'Portada', icon: 'panels-top-left', hint: 'Selección comercial de la tienda' },
    { key: 'campanas', group: 'tienda', href: '/campanas.html', label: 'Campañas y banners', icon: 'megaphone', hint: 'Banners y promociones' },
    { key: 'estadisticas', group: 'analisis', href: '/estadisticas.html', label: 'Estadísticas', icon: 'chart-column', hint: 'Ventas, clientes y productos', shortcut: 'e' },
    { key: 'reportes', group: 'analisis', href: '/reportes.html', label: 'Reportes', icon: 'file-down', hint: 'Exportaciones CSV' },
    { key: 'config', group: 'sistema', href: '/configuracion.html', label: 'Configuración', icon: 'settings', hint: 'Marca, correo y visibilidad' }
  ];

  var ACTIONS = [
    { label: 'Nuevo producto', icon: 'package-plus', href: '/editor-catalogo.html?tab=tab-products&new=1', hint: 'Catálogo' },
    { label: 'Pedidos pendientes de pago', icon: 'credit-card', href: '/pedidos.html?status=pending', hint: 'Pedidos' },
    { label: 'Productos agotados', icon: 'package-x', href: '/inventario.html?filter=out', hint: 'Inventario' },
    { label: 'Productos con stock bajo', icon: 'triangle-alert', href: '/inventario.html?filter=low', hint: 'Inventario' },
    { label: 'Productos sin foto', icon: 'image-off', href: '/editor-catalogo.html?photo=without_photo', hint: 'Catálogo' },
    { label: 'Exportar pedidos (CSV)', icon: 'file-spreadsheet', href: '/reportes.html', hint: 'Reportes' },
    { label: 'Ver la tienda', icon: 'external-link', href: '/tienda.html', hint: 'Sitio público', external: true },
    { label: 'Cambiar tema claro/oscuro', icon: 'sun-moon', run: function () { cycleTheme(); }, hint: 'Preferencias' },
    { label: 'Alternar tablas compactas', icon: 'rows-3', run: function () { toggleDensity(); }, hint: 'Preferencias' }
  ];

  var STATUS_LABEL = { quote_requested: 'Cotización', pending: 'Pendiente de pago', paid: 'Pago confirmado', preparing: 'Preparando', shipped: 'Enviado', delivered: 'Entregado', cancelled: 'Cancelado', received: 'Recibido' };
  var ADMIN_ROLES = ['admin', 'administrator', 'administrador'];
  var prefs = window.SmartPrefs || { setCollapsed: function () {}, collapsed: function () { return false; }, theme: function () { return 'auto'; }, setTheme: function () {}, density: function () { return 'comfortable'; }, setDensity: function () {}, locked: function () { return false; } };

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }
  function icons() { if (window.lucide) window.lucide.createIcons(); }
  function token() { try { return localStorage.getItem('smartisp.adminToken') || sessionStorage.getItem('smartisp.adminToken'); } catch (e) { return null; } }
  function authHeaders() { var t = token(); return t ? { Authorization: 'Bearer ' + t, 'X-Admin-Token': t } : {}; }
  function getJson(action) {
    return fetch('/api/router.php?action=' + action, { credentials: 'include', cache: 'no-store', headers: authHeaders() })
      .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); });
  }
  var isMac = /Mac|iPhone|iPad/.test(navigator.platform || '');
  var money = function (v) { return '$' + Number(v || 0).toLocaleString('es-EC', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

  function linkHtml(link, activeKey) {
    var active = link.key === activeKey;
    return '<a href="' + link.href + '" class="admin-nav-link' + (active ? ' is-active' : '') + '" data-key="' + link.key + '"' + (active ? ' aria-current="page"' : '') + ' data-tip="' + esc(link.label) + '">' +
      '<i data-lucide="' + link.icon + '" aria-hidden="true"></i><span class="label">' + esc(link.label) + '</span>' +
      (link.badge ? '<span class="admin-nav-badge" data-badge="' + link.badge + '" hidden></span>' : '') + '</a>';
  }

  function groupsHtml(activeKey) {
    return GROUPS.map(function (group) {
      var links = LINKS.filter(function (l) { return l.group === group.key; });
      if (!links.length) return '';
      return '<div class="admin-nav-group">' + (group.label ? '<div class="admin-nav-group-label">' + esc(group.label) + '</div>' : '') +
        links.map(function (l) { return linkHtml(l, activeKey); }).join('') + '</div>';
    }).join('');
  }

  function themeMeta() {
    var t = prefs.theme();
    if (t === 'dark') return { icon: 'moon', label: 'Tema: oscuro' };
    if (t === 'light') return { icon: 'sun', label: 'Tema: claro' };
    return { icon: 'sun-moon', label: 'Tema: sistema' };
  }

  function cycleTheme() {
    if (prefs.locked()) { notify('Esta pantalla usa siempre el tema claro (previsualiza la tienda).'); }
    var order = ['auto', 'light', 'dark'];
    var next = order[(order.indexOf(prefs.theme()) + 1) % order.length];
    prefs.setTheme(next);
    var m = themeMeta();
    document.querySelectorAll('[data-nav-theme]').forEach(function (b) {
      b.innerHTML = '<i data-lucide="' + m.icon + '" aria-hidden="true"></i><span>' + m.label + '</span>';
      b.setAttribute('aria-label', m.label + '. Cambiar tema');
    });
    icons();
  }

  function toggleDensity() {
    var next = prefs.density() === 'compact' ? 'comfortable' : 'compact';
    prefs.setDensity(next);
    document.querySelectorAll('[data-nav-density]').forEach(function (b) {
      b.setAttribute('aria-pressed', String(next === 'compact'));
      b.querySelector('span').textContent = next === 'compact' ? 'Tablas: compactas' : 'Tablas: cómodas';
    });
    notify(next === 'compact' ? 'Tablas compactas activadas.' : 'Tablas cómodas activadas.');
  }

  function notify(message) {
    if (window.SmartAdmin && window.SmartAdmin.toast) { window.SmartAdmin.toast(message); return; }
    var el = document.getElementById('admin-nav-live');
    if (el) el.textContent = message;
  }

  // ---------- Avisos (conteos de la barra lateral) ----------
  var ALERTS_KEY = 'smartisp.adminAlerts';
  var alertsState = null;

  function alertItems(a) {
    if (!a) return [];
    var items = [];
    var plural = function (n, one, many) { return n + ' ' + (n === 1 ? one : many); };
    if (a.orders_pending_stale) items.push({ tone: 'bad', icon: 'clock-alert', href: '/pedidos.html?status=pending', title: plural(a.orders_pending_stale, 'pedido sin pagar', 'pedidos sin pagar') + ' hace más de ' + a.stale_days + ' días', text: 'Confirma el pago o contacta al cliente.' });
    else if (a.orders_pending) items.push({ tone: 'warn', icon: 'credit-card', href: '/pedidos.html?status=pending', title: plural(a.orders_pending, 'pedido pendiente de pago', 'pedidos pendientes de pago'), text: 'Revisa los comprobantes recibidos.' });
    if (a.orders_quote_requested) items.push({ tone: 'warn', icon: 'file-question', href: '/pedidos.html?status=quote_requested', title: plural(a.orders_quote_requested, 'cotización de carrito', 'cotizaciones de carrito') + ' por responder', text: 'Clientes esperando precio final.' });
    if (a.service_quotes_new) items.push({ tone: 'warn', icon: 'messages-square', href: '/cotizaciones.html?status=nuevo', title: plural(a.service_quotes_new, 'solicitud de servicio nueva', 'solicitudes de servicio nuevas'), text: 'Todavía sin contactar.' });
    if (a.out_of_stock) items.push({ tone: 'bad', icon: 'package-x', href: '/inventario.html?filter=out', title: plural(a.out_of_stock, 'producto agotado', 'productos agotados'), text: 'Visibles en la tienda sin unidades libres.' });
    if (a.low_stock) items.push({ tone: 'warn', icon: 'triangle-alert', href: '/inventario.html?filter=low', title: plural(a.low_stock, 'producto con stock bajo', 'productos con stock bajo'), text: 'Conviene reponer pronto.' });
    if (a.products_no_price) items.push({ tone: 'warn', icon: 'circle-dollar-sign', href: '/editor-catalogo.html?price=quote', title: plural(a.products_no_price, 'producto visible sin precio', 'productos visibles sin precio'), text: 'Se muestran como “consultar precio”.' });
    if (a.products_no_photo) items.push({ tone: 'info', icon: 'image-off', href: '/editor-catalogo.html?photo=without_photo', title: plural(a.products_no_photo, 'producto visible sin foto', 'productos visibles sin foto'), text: 'Una foto mejora la conversión.' });
    return items;
  }

  function paintAlerts(root, a) {
    alertsState = a;
    var counts = { orders: (a.orders_pending || 0) + (a.orders_quote_requested || 0), quotes: a.service_quotes_new || 0, stock: a.out_of_stock || 0 };
    root.querySelectorAll('[data-badge]').forEach(function (el) {
      var n = counts[el.getAttribute('data-badge')] || 0;
      el.hidden = !n;
      el.textContent = n > 99 ? '99+' : String(n);
      el.classList.toggle('is-bad', el.getAttribute('data-badge') === 'stock' || (el.getAttribute('data-badge') === 'orders' && a.orders_pending_stale > 0));
      var link = el.closest('a');
      if (link) link.setAttribute('aria-label', link.querySelector('.label').textContent + (n ? ' (' + n + ' por atender)' : ''));
    });
    var items = alertItems(a);
    var bell = root.querySelector('.admin-nav-bell');
    if (bell) {
      var dot = bell.querySelector('.admin-nav-bell-count');
      dot.hidden = !items.length;
      dot.textContent = String(items.length);
      bell.setAttribute('aria-label', items.length ? 'Avisos: ' + items.length + ' asuntos por atender' : 'Avisos: nada pendiente');
    }
    document.dispatchEvent(new CustomEvent('smartisp:alerts', { detail: a }));
  }

  function loadAlerts(root, force) {
    try {
      var cached = JSON.parse(sessionStorage.getItem(ALERTS_KEY) || 'null');
      if (cached && cached.data) paintAlerts(root, cached.data);
      if (!force && cached && Date.now() - cached.at < 60000) return;
    } catch (e) { /* sin caché */ }
    getJson('admin-insights-alerts').then(function (data) {
      try { sessionStorage.setItem(ALERTS_KEY, JSON.stringify({ at: Date.now(), data: data })); } catch (e) { /* sin caché */ }
      paintAlerts(root, data);
    }).catch(function () { /* los avisos son opcionales */ });
  }

  function renderAlertsPanel() {
    var items = alertItems(alertsState);
    var body = !alertsState ? '<p class="admin-pop-empty">Consultando…</p>' : (items.length ? items.map(function (it) {
      return '<a class="admin-pop-item tone-' + it.tone + '" href="' + it.href + '"><i data-lucide="' + it.icon + '" aria-hidden="true"></i><span><strong>' + esc(it.title) + '</strong><small>' + esc(it.text) + '</small></span></a>';
    }).join('') : '<p class="admin-pop-empty"><i data-lucide="circle-check" aria-hidden="true"></i> Todo al día. No hay nada urgente.</p>');
    return '<div class="admin-pop-head"><strong>Requiere atención</strong><a href="/admin.html">Ver resumen</a></div>' + body;
  }

  // ---------- Buscador global (Ctrl+K) ----------
  var palette = null;

  function normalize(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

  function buildPalette() {
    var el = document.createElement('div');
    el.className = 'admin-cmd';
    el.hidden = true;
    el.innerHTML = '<div class="admin-cmd-panel" role="dialog" aria-modal="true" aria-label="Buscar en el panel">' +
      '<div class="admin-cmd-input"><i data-lucide="search" aria-hidden="true"></i>' +
      '<input type="text" role="combobox" aria-expanded="true" aria-controls="admin-cmd-list" aria-autocomplete="list" autocomplete="off" spellcheck="false" placeholder="Busca pantallas, pedidos, productos o clientes…">' +
      '<kbd>Esc</kbd></div>' +
      '<div class="admin-cmd-list" id="admin-cmd-list" role="listbox" aria-label="Resultados"></div>' +
      '<div class="admin-cmd-foot"><span><kbd>↑</kbd><kbd>↓</kbd> moverse</span><span><kbd>Enter</kbd> abrir</span><span><kbd>G</kbd> + letra: ir a una pantalla</span></div></div>';
    document.body.appendChild(el);
    var input = el.querySelector('input');
    var list = el.querySelector('.admin-cmd-list');
    var state = { items: [], index: 0, remote: null, seq: 0, timer: null, opener: null };

    function localItems(q) {
      var nq = normalize(q);
      var screens = LINKS.filter(function (l) { return !nq || normalize(l.label + ' ' + l.hint).indexOf(nq) !== -1; })
        .map(function (l) { return { group: 'Pantallas', label: l.label, hint: l.hint, icon: l.icon, href: l.href, kbd: l.shortcut ? 'G ' + l.shortcut.toUpperCase() : '' }; });
      var actions = ACTIONS.filter(function (a) { return !nq || normalize(a.label + ' ' + a.hint).indexOf(nq) !== -1; })
        .map(function (a) { return { group: 'Acciones', label: a.label, hint: a.hint, icon: a.icon, href: a.href, run: a.run, external: a.external }; });
      return nq ? screens.concat(actions) : screens.slice(0, 6).concat(actions.slice(0, 5));
    }

    function remoteItems(data) {
      if (!data) return [];
      var out = [];
      (data.orders || []).forEach(function (o) {
        out.push({ group: 'Pedidos', icon: 'receipt', label: '#' + o.id + (o.customer_name ? ' · ' + o.customer_name : ''), hint: (STATUS_LABEL[o.status] || o.status || '') + (o.total != null ? ' · ' + money(o.total) : ''), href: '/pedidos.html?q=' + encodeURIComponent(o.id) });
      });
      (data.products || []).forEach(function (p) {
        out.push({ group: 'Productos', icon: 'package', label: p.name, hint: [p.sku, p.price > 0 ? money(p.price) : 'Sin precio', p.visible ? '' : 'Oculto'].filter(Boolean).join(' · '), href: '/editor-catalogo.html?tab=tab-products&q=' + encodeURIComponent(p.sku || p.name) });
      });
      (data.customers || []).forEach(function (c) {
        out.push({ group: 'Clientes', icon: 'user', label: c.name || c.email, hint: c.email, href: '/clientes.html?q=' + encodeURIComponent(c.email || c.name) });
      });
      return out;
    }

    function paint() {
      var q = input.value.trim();
      state.items = localItems(q).concat(remoteItems(state.remote));
      if (state.index >= state.items.length) state.index = Math.max(0, state.items.length - 1);
      var html = '';
      var lastGroup = '';
      state.items.forEach(function (it, i) {
        if (it.group !== lastGroup) { html += '<div class="admin-cmd-group" role="presentation">' + esc(it.group) + '</div>'; lastGroup = it.group; }
        html += '<div class="admin-cmd-item' + (i === state.index ? ' is-active' : '') + '" role="option" id="admin-cmd-' + i + '" data-i="' + i + '" aria-selected="' + (i === state.index) + '">' +
          '<i data-lucide="' + it.icon + '" aria-hidden="true"></i><span class="t"><strong>' + esc(it.label) + '</strong>' + (it.hint ? '<small>' + esc(it.hint) + '</small>' : '') + '</span>' +
          (it.kbd ? '<kbd>' + esc(it.kbd) + '</kbd>' : '') + '</div>';
      });
      if (q.length >= 2 && state.remote === null) html += '<div class="admin-cmd-status">Buscando en pedidos, productos y clientes…</div>';
      if (!state.items.length) html += '<div class="admin-cmd-status">Sin resultados para “' + esc(q) + '”.</div>';
      list.innerHTML = html;
      input.setAttribute('aria-activedescendant', state.items.length ? 'admin-cmd-' + state.index : '');
      icons();
      var active = list.querySelector('.is-active');
      if (active) active.scrollIntoView({ block: 'nearest' });
    }

    function search() {
      var q = input.value.trim();
      clearTimeout(state.timer);
      state.remote = q.length >= 2 ? null : {};
      state.index = 0;
      paint();
      if (q.length < 2) return;
      var seq = ++state.seq;
      state.timer = setTimeout(function () {
        getJson('admin-insights-search&q=' + encodeURIComponent(q)).then(function (data) {
          if (seq !== state.seq) return;
          state.remote = data;
          paint();
        }).catch(function () { if (seq === state.seq) { state.remote = {}; paint(); } });
      }, 220);
    }

    function choose(i) {
      var it = state.items[i];
      if (!it) return;
      close();
      if (it.run) { it.run(); return; }
      if (it.external) window.open(it.href, '_blank', 'noopener');
      else window.location.assign(it.href);
    }

    function open() {
      state.opener = document.activeElement;
      el.hidden = false;
      document.documentElement.classList.add('admin-cmd-open');
      input.value = '';
      state.remote = {};
      state.index = 0;
      paint();
      input.focus();
    }
    function close() {
      el.hidden = true;
      document.documentElement.classList.remove('admin-cmd-open');
      if (state.opener && state.opener.focus) state.opener.focus();
    }

    input.addEventListener('input', search);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); state.index = Math.min(state.items.length - 1, state.index + 1); paint(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); state.index = Math.max(0, state.index - 1); paint(); }
      else if (e.key === 'Enter') { e.preventDefault(); choose(state.index); }
      else if (e.key === 'Escape') { e.preventDefault(); close(); }
      else if (e.key === 'Tab') { e.preventDefault(); }
    });
    list.addEventListener('click', function (e) { var item = e.target.closest('[data-i]'); if (item) choose(Number(item.getAttribute('data-i'))); });
    list.addEventListener('mousemove', function (e) {
      var item = e.target.closest('[data-i]');
      if (item && Number(item.getAttribute('data-i')) !== state.index) { state.index = Number(item.getAttribute('data-i')); list.querySelectorAll('.admin-cmd-item').forEach(function (n) { n.classList.toggle('is-active', n === item); n.setAttribute('aria-selected', String(n === item)); }); }
    });
    el.addEventListener('mousedown', function (e) { if (e.target === el) close(); });
    return { open: open, close: close, el: el };
  }

  function openPalette() {
    if (!palette) palette = buildPalette();
    palette.open();
  }

  function isTyping(target) {
    return target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));
  }

  function modalOpen() {
    return Array.prototype.some.call(document.querySelectorAll('[aria-modal="true"], dialog[open]'), function (el) { return el.offsetParent !== null || el.getClientRects().length > 0; });
  }

  var keysBound = false;
  function bindKeys() {
    if (keysBound) return;
    keysBound = true;
    var pendingG = 0;
    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        if (palette && !palette.el.hidden) palette.close(); else openPalette();
        return;
      }
      if (e.ctrlKey || e.metaKey || e.altKey || isTyping(e.target) || modalOpen()) return;
      if (e.key === '/') {
        var search = document.querySelector('main .search input, main input[type="search"]');
        if (search) { e.preventDefault(); search.focus(); }
        return;
      }
      if (e.key === 'g' || e.key === 'G') { pendingG = Date.now(); return; }
      if (pendingG && Date.now() - pendingG < 1200) {
        pendingG = 0;
        var link = LINKS.filter(function (l) { return l.shortcut === e.key.toLowerCase(); })[0];
        if (link) { e.preventDefault(); window.location.assign(link.href); }
      }
    });
  }

  // ---------- Render ----------
  var currentMenu = null;
  var documentListenersBound = false;
  var initInFlight = false;

  function bindDocumentListeners() {
    if (documentListenersBound) return;
    documentListenersBound = true;
    document.addEventListener('click', function (event) {
      if (!currentMenu) return;
      if (currentMenu.root.classList.contains('is-mobile-open') && !currentMenu.root.contains(event.target)) currentMenu.setOpen(false);
      var pop = currentMenu.root.querySelector('.admin-pop');
      if (pop && !pop.hidden && !event.target.closest('.admin-pop, .admin-nav-bell')) currentMenu.setPop(false);
    });
    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape' || !currentMenu) return;
      var pop = currentMenu.root.querySelector('.admin-pop');
      if (pop && !pop.hidden) { currentMenu.setPop(false); currentMenu.root.querySelector('.admin-nav-bell').focus(); return; }
      if (currentMenu.root.classList.contains('is-mobile-open')) { currentMenu.setOpen(false); currentMenu.toggle.focus(); }
    });
  }

  function setBodyLayout(on) {
    document.body.classList.toggle('has-admin-sidebar', on);
    document.body.classList.toggle('admin-sidebar-collapsed', on && prefs.collapsed());
    document.documentElement.classList.toggle('admin-layout', on);
  }

  function render(root, user) {
    var activeKey = root.getAttribute('data-active') || '';
    var name = (user && (user.name || user.email)) || '';
    var initial = esc((name || '·').trim().charAt(0).toUpperCase());
    var collapsed = prefs.collapsed();
    var theme = themeMeta();
    var compact = prefs.density() === 'compact';
    var shortcut = isMac ? '⌘K' : 'Ctrl K';

    root.classList.remove('is-loading');
    setBodyLayout(true);
    root.innerHTML =
      '<div class="admin-nav-brand">' +
        '<button type="button" class="admin-nav-mobile-toggle" aria-label="Abrir menú" aria-expanded="false" aria-controls="admin-nav-body"><i data-lucide="menu" aria-hidden="true"></i></button>' +
        '<a class="admin-nav-logo" href="/admin.html" aria-label="Inicio del panel"><img src="/assets/img/logo-default.jpg" alt="SmartISP" width="1280" height="426"></a>' +
        '<span class="admin-nav-tag">Panel</span>' +
        '<button type="button" class="admin-nav-mobile-search" aria-label="Buscar (' + shortcut + ')"><i data-lucide="search" aria-hidden="true"></i></button>' +
      '</div>' +
      '<div class="admin-nav-body" id="admin-nav-body">' +
        '<button type="button" class="admin-nav-search" aria-label="Buscar pantallas, pedidos, productos o clientes (' + shortcut + ')" data-tip="Buscar (' + shortcut + ')">' +
          '<i data-lucide="search" aria-hidden="true"></i><span class="label">Buscar…</span><kbd>' + shortcut + '</kbd></button>' +
        '<nav class="admin-nav-links" aria-label="Navegación administrativa">' + groupsHtml(activeKey) + '</nav>' +
        '<div class="admin-nav-tools">' +
          '<button type="button" class="admin-nav-tool admin-nav-bell" aria-haspopup="dialog" aria-expanded="false" aria-label="Avisos" data-tip="Avisos"><i data-lucide="bell" aria-hidden="true"></i><span class="label">Avisos</span><span class="admin-nav-bell-count" hidden></span></button>' +
          '<button type="button" class="admin-nav-tool" data-nav-theme aria-label="' + theme.label + '. Cambiar tema" data-tip="' + theme.label + '"><i data-lucide="' + theme.icon + '" aria-hidden="true"></i><span>' + theme.label + '</span></button>' +
          '<button type="button" class="admin-nav-tool" data-nav-density aria-pressed="' + compact + '" data-tip="Densidad de tablas"><i data-lucide="rows-3" aria-hidden="true"></i><span>' + (compact ? 'Tablas: compactas' : 'Tablas: cómodas') + '</span></button>' +
          '<div class="admin-pop" role="dialog" aria-label="Avisos" hidden></div>' +
        '</div>' +
        '<div class="admin-nav-user">' +
          '<span class="admin-nav-avatar" aria-hidden="true">' + initial + '</span>' +
          '<div class="admin-nav-user-info"><strong>' + esc(name || 'Administrador') + '</strong><span>Administrador</span></div>' +
          '<a class="admin-nav-icon" href="/tienda.html" target="_blank" rel="noopener" aria-label="Ver tienda (pestaña nueva)" data-tip="Ver tienda"><i data-lucide="external-link" aria-hidden="true"></i></a>' +
          '<button type="button" class="admin-nav-icon admin-nav-logout" aria-label="Cerrar sesión" data-tip="Cerrar sesión"><i data-lucide="log-out" aria-hidden="true"></i></button>' +
        '</div>' +
      '</div>' +
      '<button type="button" class="admin-nav-collapse" aria-controls="admin-nav-body" aria-expanded="' + (!collapsed) + '" aria-label="' + (collapsed ? 'Expandir barra lateral' : 'Contraer barra lateral') + '"><i data-lucide="chevrons-left" aria-hidden="true"></i></button>' +
      '<p class="sr-only" id="admin-nav-live" aria-live="polite"></p>';

    icons();
    loadConfiguredLogo(root);

    var collapseBtn = root.querySelector('.admin-nav-collapse');
    collapseBtn.addEventListener('click', function () {
      var next = !prefs.collapsed();
      prefs.setCollapsed(next);
      document.body.classList.toggle('admin-sidebar-collapsed', next);
      collapseBtn.setAttribute('aria-expanded', String(!next));
      collapseBtn.setAttribute('aria-label', next ? 'Expandir barra lateral' : 'Contraer barra lateral');
      window.dispatchEvent(new Event('resize'));
    });

    var mobileToggle = root.querySelector('.admin-nav-mobile-toggle');
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
      document.documentElement.classList.toggle('admin-nav-open', open);
      mobileToggle.setAttribute('aria-expanded', String(open));
      mobileToggle.setAttribute('aria-label', open ? 'Cerrar menú' : 'Abrir menú');
      mobileToggle.innerHTML = '<i data-lucide="' + (open ? 'x' : 'menu') + '" aria-hidden="true"></i>';
      icons();
    };
    mobileToggle.addEventListener('click', function (event) { event.stopPropagation(); setMobileOpen(!root.classList.contains('is-mobile-open')); });
    root.querySelectorAll('.admin-nav-links a').forEach(function (link) { link.addEventListener('click', function () { setMobileOpen(false); }); });

    root.querySelector('.admin-nav-search').addEventListener('click', function () { setMobileOpen(false); openPalette(); });
    root.querySelector('.admin-nav-mobile-search').addEventListener('click', openPalette);
    root.querySelector('[data-nav-theme]').addEventListener('click', cycleTheme);
    root.querySelector('[data-nav-density]').addEventListener('click', toggleDensity);

    var bell = root.querySelector('.admin-nav-bell');
    var pop = root.querySelector('.admin-pop');
    var setPop = function (open) {
      pop.hidden = !open;
      bell.setAttribute('aria-expanded', String(open));
      if (open) { pop.innerHTML = renderAlertsPanel(); icons(); loadAlerts(root, true); }
    };
    bell.addEventListener('click', function (e) { e.stopPropagation(); setPop(pop.hidden); });
    document.addEventListener('smartisp:alerts', function () { if (!pop.hidden) { pop.innerHTML = renderAlertsPanel(); icons(); } });

    currentMenu = { root: root, setOpen: setMobileOpen, toggle: mobileToggle, setPop: setPop };
    bindDocumentListeners();
    bindKeys();
    loadAlerts(root, false);

    root.querySelector('.admin-nav-logout').addEventListener('click', function () {
      fetch('/api/auth/logout', { method: 'POST', credentials: 'include' }).finally(function () {
        try {
          ['smartisp.adminToken', 'smartisp.accountUser', 'smartisp.accountRemembered'].forEach(function (k) { localStorage.removeItem(k); sessionStorage.removeItem(k); });
          sessionStorage.removeItem(ALERTS_KEY);
        } catch (e) { /* almacenamiento bloqueado */ }
        window.location.href = '/login.html?admin=1';
      });
    });
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
        var darkImage = content.logo_dark_image || '';
        var image = darkImage || content.logo_image || content.landing_logo_image || '/assets/img/logo-default.jpg';
        if (/\/assets\/img\/logo\.(png|webp)(\?|$)/.test(image)) image = '/assets/img/logo-default.jpg';
        logo.replaceChildren();
        if (mode === 'image' && image) {
          var img = document.createElement('img');
          img.src = image;
          img.alt = brand;
          img.width = 300;
          img.height = 100;
          logo.classList.toggle('is-dark', !!darkImage);
          logo.appendChild(img);
          return;
        }
        var value = String(content.landing_logo_text || content.logo_text || brand || 'SmartISP').trim() || 'SmartISP';
        var splitAt = Math.ceil(value.length / 2);
        logo.classList.add('is-text');
        logo.appendChild(document.createTextNode(value.slice(0, splitAt)));
        var bold = document.createElement('b');
        bold.textContent = value.slice(splitAt);
        logo.appendChild(bold);
      })
      .catch(function () { /* se conserva el logo por defecto */ });
  }

  function redirectToLogin() {
    window.location.href = '/login.html?admin=1&redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
  }

  function renderNotice(root, title, message, retry) {
    root.classList.remove('is-loading');
    root.classList.add('is-access-notice');
    setBodyLayout(false);
    root.innerHTML = '<section class="admin-nav-notice" role="status"><i data-lucide="shield-alert" aria-hidden="true"></i>' +
      '<strong>' + esc(title) + '</strong><span>' + esc(message) + '</span>' +
      (retry ? '<button type="button" class="admin-nav-retry"><i data-lucide="refresh-cw" aria-hidden="true"></i> Reintentar</button>' : '') +
      '<a href="/tienda.html"><i data-lucide="store" aria-hidden="true"></i> Ver tienda</a>' +
      '<a href="/login.html?admin=1&redirect=' + encodeURIComponent(window.location.pathname + window.location.search) + '"><i data-lucide="log-in" aria-hidden="true"></i> Iniciar sesión de administrador</a></section>';
    icons();
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
    fetch('/api/auth/me', { credentials: 'include', cache: 'no-store', headers: authHeaders() })
      .finally(function () { initInFlight = false; })
      .then(function (res) {
        if (res.status === 401) { redirectToLogin(); return null; }
        if (!res.ok) throw new Error('No se pudo validar la sesión (' + res.status + ').');
        return res.json();
      })
      .then(function (data) {
        if (!data) return;
        var user = data.user;
        if (!user) { redirectToLogin(); return; }
        if (ADMIN_ROLES.indexOf(String(user.role || '').toLowerCase()) < 0) {
          renderNotice(root, 'Acceso administrativo', 'Esta cuenta no tiene permisos de administrador.', false);
          return;
        }
        try {
          if (data.token) localStorage.setItem('smartisp.adminToken', data.token);
          localStorage.setItem('smartisp.accountUser', JSON.stringify(user));
        } catch (e) { /* almacenamiento bloqueado */ }
        render(root, user);
      })
      .catch(function (error) { renderNotice(root, 'No se pudo validar la sesión', error.message || 'Comprueba tu conexión e inténtalo de nuevo.', true); });
  }

  window.SmartNav = {
    openSearch: openPalette,
    refreshAlerts: function () { var root = document.getElementById('smartisp-admin-nav'); if (root) loadAlerts(root, true); },
    links: LINKS
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
