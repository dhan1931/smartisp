/* Mi cuenta: página completa (/mi-cuenta) y contenido del modal de las páginas públicas (/mi-cuenta?embed=1). */
(function () {
  'use strict';

  const app = document.querySelector('#accountApp');
  if (!app) return;
  const params = new URLSearchParams(location.search);
  const embedded = params.has('embed');

  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = value => '$' + Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const slug = text => String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80) || 'articulo';
  const productUrl = item => `/producto/${encodeURIComponent(item.productId || item.id || '')}-${slug(item.name)}`;
  const icons = () => window.lucide && window.lucide.createIcons();
  const store = {
    get: key => { try { return localStorage.getItem(key) || sessionStorage.getItem(key); } catch { return null; } },
    set: (key, value, session) => { try { (session ? sessionStorage : localStorage).setItem(key, value); } catch {} },
    remove: key => { try { localStorage.removeItem(key); sessionStorage.removeItem(key); } catch {} },
  };

  const api = async (action, options = {}) => {
    const response = await fetch(`/api/auth/${action}`, { credentials: 'include', headers: { 'Content-Type': 'application/json' }, ...options });
    let data = {};
    try { data = await response.json(); } catch {}
    if (!response.ok) throw new Error(data.error || 'No se pudo completar la operación. Intenta de nuevo.');
    return data;
  };
  const post = (action, body) => api(action, { method: 'POST', body: JSON.stringify(body || {}) });

  const STATUS = {
    quote_requested: ['Cotización solicitada', 0], pending: ['Pendiente', 0], received: ['Recibido', 0], paid: ['Pagado', 1],
    preparing: ['En preparación', 1], processing: ['En proceso', 1], shipped: ['Enviado', 2], delivered: ['Entregado', 3],
    completed: ['Completado', 3], cancelled: ['Cancelado', -1], canceled: ['Cancelado', -1], refunded: ['Reembolsado', -1],
  };
  const STEPS = ['Recibido', 'En preparación', 'Enviado', 'Entregado'];
  const statusOf = order => STATUS[String(order.status || '').toLowerCase()] || [order.status || 'Pendiente', 0];
  const orderDate = order => {
    const raw = String(order.created_at || order.createdAt || '').trim();
    const date = new Date(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(raw) ? `${raw.replace(' ', 'T')}Z` : raw);
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('es-EC', { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'America/Guayaquil' });
  };
  const orderLines = order => (Array.isArray(order.items) ? order.items : Array.isArray(order.items?.items) ? order.items.items : []);

  const SECTIONS = [
    ['resumen', 'layout-dashboard', 'Resumen'],
    ['pedidos', 'package', 'Mis pedidos'],
    ['favoritos', 'heart', 'Favoritos'],
    ['datos', 'user-round', 'Mis datos'],
    ['seguridad', 'shield-check', 'Seguridad'],
  ];
  const state = { user: null, orders: [], ordersError: false, wishlist: [], openOrder: null };
  const section = () => { const id = location.hash.slice(1); return SECTIONS.some(s => s[0] === id) ? id : 'resumen'; };

  const notify = () => { if (embedded) window.parent.postMessage({ type: 'smartisp:account-ready', name: state.user?.name || '' }, location.origin); };
  const syncHeaderName = () => {
    document.querySelectorAll('a[href="/mi-cuenta"] span').forEach(span => { span.textContent = state.user?.name || 'Mi cuenta'; });
  };
  const rememberUser = (user, remember) => {
    store.remove('smartisp.accountUser');
    store.set('smartisp.accountUser', JSON.stringify({ id: user.id, name: user.name, email: user.email, role: user.role }), !remember);
    if (remember) store.set('smartisp.accountRemembered', 'true'); else store.remove('smartisp.accountRemembered');
  };
  const safeRedirect = () => {
    const target = params.get('redirect') || '';
    return /^\/(?!\/)[\w\-./?=&%#]*$/.test(target) ? target : '';
  };

  // ---------- Sin sesión ----------
  const guestView = (tab = 'login', message = '') => {
    const tabs = [['login', 'Iniciar sesión'], ['register', 'Crear cuenta']];
    app.innerHTML = `
      <section class="account-guest">
        <div class="account-guest-intro">
          <span class="account-eyebrow">Mi cuenta</span>
          <h1>${tab === 'register' ? 'Crea tu cuenta SmartISP' : tab === 'reset' ? 'Recupera tu contraseña' : 'Bienvenido de nuevo'}</h1>
          <ul>
            <li><i data-lucide="package" width="18"></i> Sigue el estado de tus pedidos</li>
            <li><i data-lucide="heart" width="18"></i> Guarda tus productos favoritos</li>
            <li><i data-lucide="zap" width="18"></i> Compra más rápido con tus datos guardados</li>
          </ul>
        </div>
        <div class="account-card account-guest-card">
          ${tab === 'reset' ? '' : `<div class="account-tabs" role="tablist">${tabs.map(([id, label]) => `<button type="button" role="tab" aria-selected="${tab === id}" data-tab="${id}">${label}</button>`).join('')}</div>`}
          <p class="account-msg" ${message ? '' : 'hidden'}>${esc(message)}</p>
          ${tab === 'login' ? `
          <form data-form="login" novalidate>
            <label>Correo electrónico<input type="email" name="email" autocomplete="email" required></label>
            <label>Contraseña<span class="account-password"><input type="password" name="password" autocomplete="current-password" required><button type="button" data-toggle-password aria-label="Mostrar contraseña"><i data-lucide="eye" width="18"></i></button></span></label>
            <div class="account-row"><label class="account-check"><input type="checkbox" name="remember" checked> Mantener la sesión</label><button type="button" class="account-link" data-tab="reset">¿Olvidaste tu contraseña?</button></div>
            <button class="account-primary" type="submit">Iniciar sesión</button>
          </form>` : tab === 'register' ? `
          <form data-form="register" novalidate>
            <div class="account-grid-2"><label>Nombre<input name="name" autocomplete="given-name" required maxlength="100"></label><label>Apellido<input name="surname" autocomplete="family-name" maxlength="100"></label></div>
            <label>Correo electrónico<input type="email" name="email" autocomplete="email" required></label>
            <label>Celular / WhatsApp<input type="tel" name="phone" autocomplete="tel" maxlength="30" placeholder="09XXXXXXXX"></label>
            <label>Contraseña<span class="account-password"><input type="password" name="password" autocomplete="new-password" minlength="6" required><button type="button" data-toggle-password aria-label="Mostrar contraseña"><i data-lucide="eye" width="18"></i></button></span><small>Mínimo 6 caracteres.</small></label>
            <p class="account-legal">Al crear tu cuenta aceptas los <a href="/terminos" target="_top">términos y condiciones</a> y la <a href="/privacidad" target="_top">política de privacidad</a>.</p>
            <button class="account-primary" type="submit">Crear cuenta</button>
          </form>` : `
          <form data-form="reset" novalidate>
            <p class="account-help">Te enviaremos un enlace a tu correo para crear una contraseña nueva.</p>
            <label>Correo electrónico<input type="email" name="email" autocomplete="email" required></label>
            <button class="account-primary" type="submit">Enviar enlace</button>
            <button type="button" class="account-link account-back" data-tab="login"><i data-lucide="arrow-left" width="16"></i> Volver a iniciar sesión</button>
          </form>`}
        </div>
      </section>`;
    icons();
    app.querySelector('input')?.focus({ preventScroll: true });
  };

  // ---------- Con sesión ----------
  const progressHtml = order => {
    const [, step] = statusOf(order);
    if (step < 0) return '';
    return `<ol class="account-steps">${STEPS.map((label, i) => `<li class="${i <= step ? 'is-done' : ''}">${label}</li>`).join('')}</ol>`;
  };
  const orderCard = order => {
    const [label, step] = statusOf(order);
    const lines = orderLines(order);
    const open = state.openOrder === String(order.id);
    const shipping = order.shipping && typeof order.shipping === 'object' ? order.shipping : {};
    return `
      <article class="account-order ${open ? 'is-open' : ''}">
        <button type="button" class="account-order-head" data-order="${esc(order.id)}" aria-expanded="${open}">
          <span><strong>Pedido #${esc(order.id)}</strong><small>${orderDate(order)} · ${lines.length} producto${lines.length === 1 ? '' : 's'}</small></span>
          <span class="account-order-side"><span class="account-badge ${step < 0 ? 'is-off' : step >= 3 ? 'is-ok' : ''}">${esc(label)}</span><b>${money(order.total)}</b><i data-lucide="chevron-down" width="18"></i></span>
        </button>
        ${open ? `<div class="account-order-body">
          ${progressHtml(order)}
          <ul class="account-lines">${lines.map(line => `<li><a href="${productUrl(line)}" target="_top">${esc(line.name)}</a><span>${Number(line.quantity) || 1} × ${money(line.price)}</span></li>`).join('')}</ul>
          ${shipping.address || shipping.city ? `<p class="account-ship"><i data-lucide="map-pin" width="16"></i> ${esc([shipping.address, shipping.city].filter(Boolean).join(', '))}</p>` : ''}
          <div class="account-actions"><button type="button" class="account-secondary" data-reorder="${esc(order.id)}"><i data-lucide="rotate-ccw" width="16"></i> Volver a comprar</button></div>
        </div>` : ''}
      </article>`;
  };
  const ordersHtml = (limit = 0) => {
    if (state.ordersError) return '<div class="account-empty">No se pudo cargar tu historial de pedidos. <button type="button" class="account-link" data-reload>Reintentar</button></div>';
    if (!state.orders.length) return '<div class="account-empty"><i data-lucide="package-open" width="28"></i><p>Aún no tienes pedidos.</p><a class="account-primary" href="/tienda.html" target="_top">Ir a la tienda</a></div>';
    return (limit ? state.orders.slice(0, limit) : state.orders).map(orderCard).join('');
  };
  const wishlistHtml = () => state.wishlist.length ? `<div class="account-wishlist">${state.wishlist.map(item => `
      <article class="account-fav">
        <a href="${productUrl(item)}" target="_top"><img src="${esc(item.image || '')}" alt="" loading="lazy" onerror="this.style.visibility='hidden'"></a>
        <div><a href="${productUrl(item)}" target="_top"><strong>${esc(item.name)}</strong></a><small>${esc(item.category || '')}</small><b>${Number(item.price) > 0 ? money(item.price) : 'Solicitar cotización'}</b></div>
        <div class="account-fav-actions">
          <button type="button" class="account-secondary" data-fav-cart="${esc(item.productId)}"><i data-lucide="shopping-cart" width="16"></i> Al carrito</button>
          <button type="button" class="account-icon" data-fav-remove="${esc(item.productId)}" aria-label="Quitar de favoritos"><i data-lucide="trash-2" width="16"></i></button>
        </div>
      </article>`).join('')}</div>`
    : '<div class="account-empty"><i data-lucide="heart" width="28"></i><p>Todavía no guardaste productos. Toca el corazón de cualquier producto en la tienda para verlo aquí.</p><a class="account-primary" href="/tienda.html" target="_top">Explorar productos</a></div>';

  const sectionHtml = id => {
    const u = state.user;
    if (id === 'pedidos') return `<h2>Mis pedidos</h2>${ordersHtml()}`;
    if (id === 'favoritos') return `<h2>Favoritos</h2><p class="account-help">Se guardan mientras tu sesión esté abierta.</p>${wishlistHtml()}`;
    if (id === 'datos') return `<h2>Mis datos</h2>
      <form class="account-card" data-form="profile" novalidate>
        <div class="account-grid-2"><label>Nombre<input name="name" value="${esc(u.name)}" autocomplete="given-name" required maxlength="100"></label><label>Apellido<input name="surname" value="${esc(u.surname)}" autocomplete="family-name" maxlength="100"></label></div>
        <label>Correo electrónico<input type="email" name="email" value="${esc(u.email)}" autocomplete="email" required></label>
        <label>Celular / WhatsApp<input type="tel" name="phone" value="${esc(u.phone)}" autocomplete="tel" maxlength="30"></label>
        <label data-email-password hidden>Contraseña actual<input type="password" name="currentPassword" autocomplete="current-password"><small>Para cambiar tu correo confirma tu contraseña.</small></label>
        <p class="account-msg" hidden></p>
        <button class="account-primary" type="submit">Guardar cambios</button>
      </form>`;
    if (id === 'seguridad') return `<h2>Seguridad</h2>
      <form class="account-card" data-form="password" novalidate>
        <h3>Cambiar contraseña</h3>
        <label>Contraseña actual<input type="password" name="currentPassword" autocomplete="current-password" required></label>
        <div class="account-grid-2"><label>Nueva contraseña<input type="password" name="newPassword" autocomplete="new-password" minlength="6" required></label><label>Repite la nueva<input type="password" name="confirmation" autocomplete="new-password" minlength="6" required></label></div>
        <p class="account-msg" hidden></p>
        <button class="account-primary" type="submit">Actualizar contraseña</button>
      </form>
      <div class="account-card">
        <h3>Sesiones</h3>
        <p class="account-help">Si usaste tu cuenta en otro equipo, puedes cerrarla allí sin salir de este.</p>
        <p class="account-msg" data-sessions-msg hidden></p>
        <div class="account-actions"><button type="button" class="account-secondary" data-revoke>Cerrar sesión en otros dispositivos</button><button type="button" class="account-danger" data-logout>Cerrar sesión</button></div>
      </div>`;
    const last = state.orders[0];
    return `<h2>Hola, ${esc(u.name || 'cliente')}</h2>
      <div class="account-stats">
        <a href="#pedidos" class="account-stat"><i data-lucide="package" width="22"></i><b>${state.orders.length}</b><span>Pedido${state.orders.length === 1 ? '' : 's'}</span></a>
        <a href="#favoritos" class="account-stat"><i data-lucide="heart" width="22"></i><b>${state.wishlist.length}</b><span>Favorito${state.wishlist.length === 1 ? '' : 's'}</span></a>
        <a href="#datos" class="account-stat"><i data-lucide="mail" width="22"></i><b class="account-stat-text">${esc(u.email)}</b><span>Tu correo</span></a>
      </div>
      ${last ? `<h3>Último pedido</h3>${orderCard(last)}` : `<div class="account-empty"><i data-lucide="shopping-bag" width="28"></i><p>Cuando hagas tu primer pedido podrás seguirlo desde aquí.</p><a class="account-primary" href="/tienda.html" target="_top">Ir a la tienda</a></div>`}
      ${state.orders.length > 1 ? '<a class="account-link" href="#pedidos">Ver todos mis pedidos →</a>' : ''}`;
  };

  const memberView = () => {
    const current = section();
    app.innerHTML = `
      <div class="account-layout">
        <aside class="account-side">
          <div class="account-user"><span class="account-avatar">${esc((state.user.name || '?').trim().charAt(0).toUpperCase())}</span><span><strong>${esc([state.user.name, state.user.surname].filter(Boolean).join(' '))}</strong><small>${esc(state.user.email)}</small></span></div>
          <nav class="account-nav" aria-label="Secciones de mi cuenta">
            ${SECTIONS.map(([id, icon, label]) => `<a href="#${id}" ${id === current ? 'aria-current="page"' : ''}><i data-lucide="${icon}" width="18"></i>${label}${id === 'pedidos' && state.orders.length ? `<em>${state.orders.length}</em>` : id === 'favoritos' && state.wishlist.length ? `<em>${state.wishlist.length}</em>` : ''}</a>`).join('')}
            <button type="button" data-logout><i data-lucide="log-out" width="18"></i>Cerrar sesión</button>
          </nav>
          ${embedded ? '<a class="account-full" href="/mi-cuenta" target="_top"><i data-lucide="maximize-2" width="16"></i> Abrir en página completa</a>' : ''}
        </aside>
        <section class="account-main">${sectionHtml(current)}</section>
      </div>`;
    icons();
  };

  const render = () => { if (state.user) memberView(); else guestView(); syncHeaderName(); notify(); };

  const loadData = async () => {
    const [orders, wishlist] = await Promise.allSettled([api('customer-orders'), api('customer-wishlist')]);
    state.orders = orders.status === 'fulfilled' ? (orders.value.orders || []) : [];
    state.ordersError = orders.status !== 'fulfilled';
    state.wishlist = wishlist.status === 'fulfilled' ? (wishlist.value.wishlist || []) : [];
  };

  const signedIn = async (user, remember = true) => {
    state.user = user;
    rememberUser(user, remember);
    const target = safeRedirect();
    if (target) { location.href = target; return; }
    await loadData();
    render();
  };

  const showMsg = (form, text, ok = false) => {
    const el = form.querySelector('.account-msg') || app.querySelector('.account-msg');
    if (!el) return;
    el.textContent = text;
    el.hidden = !text;
    el.classList.toggle('is-ok', ok);
  };
  const values = form => Object.fromEntries(new FormData(form).entries());

  const addToCart = items => {
    const cartApi = window.SmartStorefrontCart;
    if (!cartApi) return false;
    const cart = cartApi.read();
    items.forEach(item => {
      const id = String(item.productId || item.id || '');
      if (!id) return;
      const existing = cart.find(line => String(line.id) === id);
      const qty = Math.max(1, Math.min(99, Number(item.quantity) || 1));
      if (existing) existing.quantity = Math.min(99, (Number(existing.quantity) || 1) + qty);
      else cart.push({ id, sku: item.sku || '', name: item.name, price: Number(item.price) || 0, image: item.image || '', category: item.category || '', quantity: qty });
    });
    cartApi.write(cart);
    cartApi.render && cartApi.render();
    if (embedded) window.top.location.href = '/tienda.html?openCart=1';
    else cartApi.open && cartApi.open();
    return true;
  };

  app.addEventListener('click', async event => {
    const target = event.target.closest('button, a');
    if (!target) return;
    if (target.dataset.tab) { guestView(target.dataset.tab); return; }
    if (target.hasAttribute('data-toggle-password')) {
      const input = target.parentElement.querySelector('input');
      input.type = input.type === 'password' ? 'text' : 'password';
      target.setAttribute('aria-label', input.type === 'password' ? 'Mostrar contraseña' : 'Ocultar contraseña');
      return;
    }
    if (target.dataset.order !== undefined) {
      state.openOrder = state.openOrder === target.dataset.order ? null : target.dataset.order;
      memberView();
      return;
    }
    if (target.dataset.reorder !== undefined) {
      const order = state.orders.find(o => String(o.id) === target.dataset.reorder);
      if (order) addToCart(orderLines(order));
      return;
    }
    if (target.dataset.favCart !== undefined) {
      const item = state.wishlist.find(i => String(i.productId) === target.dataset.favCart);
      if (item) addToCart([item]);
      return;
    }
    if (target.dataset.favRemove !== undefined) {
      target.disabled = true;
      try {
        const result = await api(`customer-wishlist?productId=${encodeURIComponent(target.dataset.favRemove)}`, { method: 'DELETE' });
        state.wishlist = result.wishlist || state.wishlist.filter(i => String(i.productId) !== target.dataset.favRemove);
        memberView();
      } catch { target.disabled = false; }
      return;
    }
    if (target.hasAttribute('data-reload')) { await loadData(); memberView(); return; }
    if (target.hasAttribute('data-revoke')) {
      const msg = app.querySelector('[data-sessions-msg]');
      target.disabled = true;
      try { await post('revoke-other-sessions'); msg.textContent = 'Listo: se cerró tu sesión en los demás dispositivos.'; msg.classList.add('is-ok'); }
      catch (error) { msg.textContent = error.message; msg.classList.remove('is-ok'); }
      msg.hidden = false; target.disabled = false;
      return;
    }
    if (target.hasAttribute('data-logout')) {
      target.disabled = true;
      try { await post('logout'); } catch {}
      store.remove('smartisp.accountUser'); store.remove('smartisp.accountRemembered');
      Object.assign(state, { user: null, orders: [], wishlist: [], openOrder: null });
      history.replaceState(null, '', location.pathname + location.search);
      render();
    }
  });

  app.addEventListener('input', event => {
    if (event.target.name !== 'email' || !event.target.closest('form[data-form="profile"]')) return;
    const changed = event.target.value.trim().toLowerCase() !== String(state.user.email || '').toLowerCase();
    const field = app.querySelector('[data-email-password]');
    field.hidden = !changed;
    field.querySelector('input').required = changed;
  });

  app.addEventListener('submit', async event => {
    const form = event.target.closest('form[data-form]');
    if (!form) return;
    event.preventDefault();
    if (!form.reportValidity()) return;
    const button = form.querySelector('[type="submit"]');
    const data = values(form);
    button.disabled = true;
    showMsg(form, '');
    try {
      if (form.dataset.form === 'login') {
        const result = await post('login', { email: data.email, password: data.password });
        await signedIn(result.user, Boolean(data.remember));
      } else if (form.dataset.form === 'register') {
        const result = await post('register', data);
        await signedIn(result.user, true);
      } else if (form.dataset.form === 'reset') {
        await post('request-password-reset', { email: data.email });
        showMsg(form, 'Si el correo está registrado, te llegará un enlace en unos minutos. Revisa también la carpeta de spam.', true);
      } else if (form.dataset.form === 'profile') {
        const result = await post('update-profile', data);
        state.user = { ...state.user, ...(result.user || data) };
        rememberUser(state.user, store.get('smartisp.accountRemembered') === 'true');
        memberView(); syncHeaderName();
        showMsg(app.querySelector('form[data-form="profile"]'), 'Tus datos se guardaron.', true);
        return;
      } else if (form.dataset.form === 'password') {
        if (data.newPassword !== data.confirmation) throw new Error('Las contraseñas nuevas no coinciden.');
        await post('change-password', data);
        form.reset();
        showMsg(form, 'Tu contraseña se actualizó.', true);
      }
    } catch (error) {
      showMsg(form, error.message);
    } finally {
      if (form.isConnected) button.disabled = false;
    }
  });

  window.addEventListener('hashchange', () => { if (state.user) { memberView(); if (!embedded) app.scrollIntoView({ block: 'start', behavior: 'smooth' }); } });
  window.addEventListener('message', async event => {
    if (event.origin !== location.origin || event.data?.type !== 'smartisp:open-account') return;
    if (state.user) { await loadData(); memberView(); }
    notify();
  });

  (async () => {
    try {
      const result = await api('session');
      if (result.user) {
        state.user = result.user;
        await loadData();
      } else {
        store.remove('smartisp.accountUser');
      }
    } catch {}
    if (!state.user && params.get('registro') === '1') { guestView('register'); syncHeaderName(); notify(); return; }
    render();
  })();
})();
