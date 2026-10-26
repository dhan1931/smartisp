document.addEventListener('DOMContentLoaded', () => SmartAdmin.boot(async user => {
  const { $, esc, money, api, post, toast, icons, state: stateHtml, skeletonRows, relTime, utc, remembered, remember } = SmartAdmin;
  const showMessage = (element, text, error = false) => { element.textContent = text; element.classList.remove('show'); element.classList.toggle('error', error); requestAnimationFrame(() => element.classList.add('show')); };

  const STATUS_META = {
    quote_requested: { label: 'Cotización solicitada' },
    pending:         { label: 'Pendiente de pago' },
    paid:            { label: 'Pago confirmado' },
    preparing:       { label: 'Preparando' },
    shipped:         { label: 'Enviado / Retiro' },
    delivered:       { label: 'Entregado' },
    cancelled:       { label: 'Cancelado' },
    received:        { label: 'Recibido (legado)' },
  };
  const STATUS_FLOW = ['quote_requested', 'pending', 'paid', 'preparing', 'shipped', 'delivered', 'cancelled'];
  // Qué hace cada estado además de avisar al cliente (pago e inventario, ver api/lib/shop.php).
  const STATUS_EFFECTS = {
    paid: 'Registra el pago como cobrado: el pedido pasa a contar en las ventas del dashboard.',
    shipped: 'Descuenta del inventario las unidades de los productos con stock controlado.',
    delivered: 'Descuenta del inventario las unidades de los productos con stock controlado.',
    cancelled: 'Libera el stock apartado (o lo devuelve al inventario si ya se había enviado).',
  };
  const PAYMENT_LABELS = { paid: 'Cobrado', confirmed: 'Cobrado', pending: 'Pendiente de cobro', quote_pending: 'Cotización (sin cobro)', failed: 'Fallido', refunded: 'Reembolsado' };
  const ACTIVITY_META = {
    note:   { label: 'Nota interna', icon: 'lock' },
    email:  { label: 'Correo', icon: 'mail' },
    system: { label: 'Sistema', icon: 'info' },
  };

  const statusLabel = status => STATUS_META[status]?.label || status || 'Sin estado';
  const statusBadge = status => {
    const key = String(status || 'none').toLowerCase().replace(/[^a-z_]/g, '');
    return `<span class="badge status-badge st-${key}">${esc(statusLabel(status))}</span>`;
  };
  const orderItems = o => Array.isArray(o.items?.items) ? o.items.items : (Array.isArray(o.items) ? o.items : []);
  // Las fechas se muestran siempre en hora de Ecuador, aunque el navegador esté en otra zona.
  const ecTime = value => {
    const d = utc(value);
    return d ? d.toLocaleString('es-EC', { timeZone: 'America/Guayaquil', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
  };
  // wa.me pide el número internacional sin "+": 09XXXXXXXX y 9XXXXXXXX son celulares de Ecuador.
  const waNumber = phone => {
    const raw = String(phone || '').trim();
    let digits = raw.replace(/\D/g, '');
    if (!raw.startsWith('+') && /^0\d{9}$/.test(digits)) digits = '593' + digits.slice(1);
    else if (!raw.startsWith('+') && /^9\d{8}$/.test(digits)) digits = '593' + digits;
    return digits.length >= 10 ? digits : '';
  };

  // La URL (?status=, ?q=) manda; si no trae nada, se recupera la última vista usada.
  const initialParams = new URLSearchParams(location.search);
  const fromUrl = initialParams.has('status') || initialParams.has('q');
  const saved = fromUrl ? {} : remembered('pedidos', {});
  const state = { page: 1, limit: 20, status: initialParams.get('status') || saved.status || 'all', q: (initialParams.get('q') || saved.q || '').trim(), totalPages: 1 };
  if (state.q) $('#searchInput').value = state.q;
  const saveView = () => remember('pedidos', { status: state.status, q: state.q });
  $('#adminIdentity').textContent = `Sesión: ${[user.name, user.surname].filter(Boolean).join(' ') || user.email}`;

  let ordersSeq = 0;
  let pageOrders = [];
  const selected = new Set();
  let bulkBusy = false;

  const syncTabs = () => document.querySelectorAll('.status-tab').forEach(btn => {
    const active = btn.dataset.status === state.status;
    btn.classList.toggle('active', active);
    btn.setAttribute('aria-pressed', String(active));
  });

  const syncSelection = () => {
    const ids = pageOrders.map(o => String(o.id));
    const count = ids.filter(id => selected.has(id)).length;
    const all = $('#selectAll');
    all.checked = ids.length > 0 && count === ids.length;
    all.indeterminate = count > 0 && count < ids.length;
    all.disabled = bulkBusy || ids.length === 0;
    document.querySelectorAll('#ordersBody .row-check').forEach(box => { box.checked = selected.has(box.value); box.disabled = bulkBusy; });
    $('#bulkBar').hidden = selected.size === 0;
    if (!bulkBusy) $('#bulkCount').textContent = `${selected.size} pedido${selected.size === 1 ? '' : 's'} seleccionado${selected.size === 1 ? '' : 's'}`;
    $('#bulkApply').disabled = bulkBusy || !$('#bulkStatus').value;
    $('#bulkClear').disabled = bulkBusy;
    $('#bulkStatus').disabled = bulkBusy;
  };

  const renderStats = stats => {
    const byStatus = Object.fromEntries((stats.by_status || []).map(s => [s.status, s.total]));
    const cards = [
      { label: 'Total pedidos', num: stats.total || 0, icon: 'receipt' },
      { label: 'Hoy', num: stats.today_count || 0, icon: 'calendar-days' },
      { label: 'Pendientes de pago', num: byStatus.pending || 0, icon: 'credit-card', status: 'pending', warn: (byStatus.pending || 0) > 0 },
      { label: 'Por preparar', num: byStatus.paid || 0, icon: 'package', status: 'paid' },
      { label: 'Completados', num: stats.completed_count || 0, icon: 'circle-check' },
      { label: 'Ingresos confirmados', num: money(stats.revenue || 0), icon: 'banknote' },
    ];
    $('#statsRow').innerHTML = cards.map(c => c.status
      ? `<button type="button" class="stat ${c.warn ? 'is-warn' : ''}" data-status="${c.status}" title="Filtrar: ${esc(c.label)}"><span><i data-lucide="${c.icon}" aria-hidden="true"></i>${esc(c.label)}</span><strong>${c.num}</strong></button>`
      : `<div class="stat"><span><i data-lucide="${c.icon}" aria-hidden="true"></i>${esc(c.label)}</span><strong>${c.num}</strong></div>`).join('');
    const tabs = [{ key: 'all', label: 'Todos', count: stats.total || 0 }]
      .concat(STATUS_FLOW.map(k => ({ key: k, label: STATUS_META[k].label, count: byStatus[k] || 0 })));
    $('#statusTabs').innerHTML = tabs.map(t => `
      <button type="button" class="chip status-tab" data-status="${t.key}" aria-pressed="false">
        ${esc(t.label)} <span class="count">${t.count}</span>
      </button>`).join('');
    icons();
    syncTabs();
  };

  const loadStats = async () => {
    try { renderStats(await api('admin-orders-stats')); }
    catch (e) { console.warn('Error cargando estadisticas de pedidos:', e); }
  };

  const renderOrdersTable = orders => {
    pageOrders = orders;
    // La selección solo abarca lo que se ve: cambiar de filtro o de página la limpia.
    const visible = new Set(orders.map(o => String(o.id)));
    [...selected].forEach(id => { if (!visible.has(id)) selected.delete(id); });
    const body = $('#ordersBody');
    const filtered = state.q || state.status !== 'all';
    $('#emptyState').innerHTML = filtered
      ? stateHtml('search', 'No hay pedidos que coincidan con el filtro', 'Prueba con otro estado o borra la búsqueda.')
      : stateHtml('empty', 'Todavía no hay pedidos', 'Los pedidos de la tienda aparecerán aquí en cuanto entren.');
    $('#emptyState').classList.remove('is-error');
    $('#emptyState').classList.toggle('hidden', orders.length > 0);
    $('#ordersTableWrap').classList.toggle('hidden', orders.length === 0);
    body.innerHTML = orders.map(o => {
      const itemCount = orderItems(o).reduce((sum, it) => sum + Number(it.quantity || 1), 0);
      const customerName = o.customer_name || o.shipping?.name || '—';
      const customerEmail = o.customer_email || o.shipping?.email || '';
      const created = utc(o.created_at);
      const absolute = created ? created.toLocaleString('es-EC', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
      return `
        <tr data-order="${esc(o.id)}">
          <td class="sel" data-label="Seleccionar"><input type="checkbox" class="row-check" value="${esc(o.id)}" aria-label="Seleccionar pedido ${esc(o.id)}"></td>
          <td class="order-id" data-label="Pedido">${esc(o.id)}</td>
          <td class="order-when" data-label="Fecha" data-export="${esc(absolute)}"><span title="${esc(absolute)}">${created ? esc(relTime(o.created_at)) : '—'}</span></td>
          <td data-label="Cliente" data-export="${esc(customerName + (customerEmail ? ' <' + customerEmail + '>' : ''))}"><span class="order-customer">${esc(customerName)}</span><span class="order-email">${esc(customerEmail)}</span></td>
          <td class="num" data-label="Ítems">${itemCount} ud.</td>
          <td class="num" data-label="Total">${money(o.total)}</td>
          <td data-label="Estado">${statusBadge(o.status)}</td>
          <td data-label="Acciones"><div class="row-actions"><button class="button small row-link" type="button" data-id="${esc(o.id)}">Ver detalle <i data-lucide="arrow-right" aria-hidden="true"></i></button>
            ${['cancelled', 'canceled'].includes(String(o.status || '').toLowerCase()) ? `<button class="icon-button row-delete" type="button" data-delete-id="${esc(o.id)}" aria-label="Eliminar pedido cancelado ${esc(o.id)}" title="Eliminar pedido cancelado"><i data-lucide="trash-2" aria-hidden="true"></i></button>` : ''}
          </div></td>
        </tr>`;
    }).join('');
    syncSelection();
    icons();
  };

  const loadOrders = async () => {
    const seq = ++ordersSeq;
    if (!$('#ordersBody').children.length) { $('#ordersTableWrap').classList.remove('hidden'); $('#ordersBody').innerHTML = skeletonRows(8, 6); }
    try {
      const qs = new URLSearchParams({ page: state.page, limit: state.limit, status: state.status, q: state.q });
      const res = await api(`admin-orders?${qs.toString()}`);
      if (seq !== ordersSeq) return;
      state.totalPages = res.totalPages || 1;
      renderOrdersTable(res.orders || []);
      $('#pageInfo').textContent = `Página ${res.page} de ${state.totalPages} · ${res.total} pedido(s)`;
      $('#prevPageBtn').disabled = state.page <= 1;
      $('#nextPageBtn').disabled = state.page >= state.totalPages;
    } catch (e) {
      if (seq !== ordersSeq) return;
      pageOrders = [];
      selected.clear();
      syncSelection();
      $('#ordersTableWrap').classList.add('hidden');
      $('#ordersBody').innerHTML = '';
      $('#emptyState').innerHTML = stateHtml('error', 'No se pudieron cargar los pedidos', e.message);
      $('#emptyState').classList.add('is-error');
      $('#emptyState').classList.remove('hidden');
      icons();
    }
  };

  const deleteCancelledOrder = async (id, button) => {
    if (!(await SmartAdmin.confirm('Eliminar pedido cancelado', `¿Eliminar definitivamente el pedido cancelado ${id}? Esta acción no se puede deshacer.`, 'Eliminar'))) return;
    button.disabled = true;
    try {
      await post('admin-order-delete', { id });
      await Promise.all([loadOrders(), loadStats()]);
    } catch (error) {
      toast(error.message, true);
    } finally {
      button.disabled = false;
    }
  };

  // Cambio de estado en bloque: la misma acción que el detalle, un pedido a la vez, para que cada
  // uno pase por sus validaciones (stock, estado repetido) y su correo al cliente.
  $('#bulkStatus').innerHTML = '<option value="">Elegir estado…</option>' + STATUS_FLOW.map(s => `<option value="${s}">${esc(statusLabel(s))}</option>`).join('');
  const applyBulk = async () => {
    const status = $('#bulkStatus').value;
    const ids = pageOrders.map(o => String(o.id)).filter(id => selected.has(id));
    if (!status || !ids.length || bulkBusy) return;
    const effect = STATUS_EFFECTS[status] ? ` ${STATUS_EFFECTS[status]}` : '';
    if (!(await SmartAdmin.confirm('Cambiar estado en bloque', `¿Pasar ${ids.length} pedido${ids.length === 1 ? '' : 's'} a "${statusLabel(status)}"? Cada cliente recibirá el aviso por correo.${effect}`, 'Cambiar estado'))) return;
    bulkBusy = true;
    syncSelection();
    const results = [];
    for (const [index, id] of ids.entries()) {
      $('#bulkCount').textContent = `Actualizando ${index + 1} de ${ids.length}…`;
      try {
        await post('admin-order-status', { id, status });
        results.push({ id, ok: true });
        selected.delete(id);
      } catch (e) {
        results.push({ id, ok: false, same: e.status === 409 && /ya está en ese estado/i.test(e.message), error: e.message });
      }
    }
    bulkBusy = false;
    results.filter(r => r.same).forEach(r => selected.delete(r.id));
    const done = results.filter(r => r.ok), same = results.filter(r => r.same), failed = results.filter(r => !r.ok && !r.same);
    const list = rows => `<ul class="bulk-list">${rows.map(r => `<li><strong>${esc(r.id)}</strong>${r.error && !r.same ? ` <span>${esc(r.error)}</span>` : ''}</li>`).join('')}</ul>`;
    SmartAdmin.modal({
      title: 'Resultado del cambio en bloque',
      subtitle: `Nuevo estado: ${statusLabel(status)}`,
      body: `<div class="bulk-summary">
        <p class="bulk-line ok"><i data-lucide="circle-check" aria-hidden="true"></i> ${done.length} actualizado${done.length === 1 ? '' : 's'}</p>
        ${same.length ? `<p class="bulk-line"><i data-lucide="info" aria-hidden="true"></i> ${same.length} ya estaba${same.length === 1 ? '' : 'n'} en ese estado</p>${list(same)}` : ''}
        ${failed.length ? `<p class="bulk-line bad"><i data-lucide="circle-alert" aria-hidden="true"></i> ${failed.length} no se pudo cambiar (siguen seleccionados)</p>${list(failed)}` : ''}
      </div>`,
      actions: [{ label: 'Cerrar', kind: 'primary' }],
    });
    await Promise.all([loadOrders(), loadStats()]);
  };
  $('#bulkStatus').addEventListener('change', syncSelection);
  $('#bulkApply').addEventListener('click', applyBulk);
  $('#bulkClear').addEventListener('click', () => { selected.clear(); syncSelection(); });
  $('#selectAll').addEventListener('change', e => {
    pageOrders.forEach(o => { if (e.target.checked) selected.add(String(o.id)); else selected.delete(String(o.id)); });
    syncSelection();
  });

  $('#statusTabs').addEventListener('click', e => {
    const btn = e.target.closest('.status-tab');
    if (!btn) return;
    state.status = btn.dataset.status;
    state.page = 1;
    saveView();
    syncTabs();
    loadOrders();
  });
  $('#statsRow').addEventListener('click', e => {
    const card = e.target.closest('button[data-status]');
    if (!card) return;
    state.status = state.status === card.dataset.status ? 'all' : card.dataset.status;
    state.page = 1;
    saveView();
    syncTabs();
    loadOrders();
  });
  $('#ordersBody').addEventListener('change', e => {
    const box = e.target.closest('.row-check');
    if (!box) return;
    if (box.checked) selected.add(box.value); else selected.delete(box.value);
    syncSelection();
  });
  $('#ordersBody').addEventListener('click', e => {
    const view = e.target.closest('.row-link');
    if (view) { openOrderModal(view.dataset.id); return; }
    const del = e.target.closest('.row-delete');
    if (del) { deleteCancelledOrder(del.dataset.deleteId, del); return; }
    const row = e.target.closest('tr[data-order]');
    if (row && !e.target.closest('a, button, input, label, td.sel')) openOrderModal(row.dataset.order);
  });
  $('#refreshOrders').addEventListener('click', async e => {
    const btn = e.currentTarget;
    btn.disabled = true; btn.classList.add('is-busy');
    await Promise.all([loadOrders(), loadStats()]);
    btn.disabled = false; btn.classList.remove('is-busy');
  });

  let searchDebounce = null;
  $('#searchInput').addEventListener('input', e => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => { state.q = e.target.value.trim(); state.page = 1; saveView(); loadOrders(); }, 350);
  });
  $('#prevPageBtn').addEventListener('click', () => { if (state.page > 1) { state.page--; loadOrders(); } });
  $('#nextPageBtn').addEventListener('click', () => { if (state.page < state.totalPages) { state.page++; loadOrders(); } });

  let currentModalOrder = null;
  let updatingStatus = false;
  let savingNote = false;
  let modalSeq = 0;
  let lastFocus = null;
  const syncStatusSubmitButton = () => {
    const selectedStatus = String($('#statusSelect').value || '').toLowerCase();
    const currentStatus = String(currentModalOrder?.status || '').toLowerCase();
    $('#statusHint').textContent = selectedStatus !== currentStatus ? (STATUS_EFFECTS[selectedStatus] || '') : '';
    $('#statusSubmitBtn').disabled = updatingStatus || !currentModalOrder || selectedStatus === currentStatus;
  };
  const syncNoteButton = () => { $('#noteSubmitBtn').disabled = savingNote || !currentModalOrder || !$('#noteText').value.trim(); };

  // Creación, cambios de estado, correos y notas en un solo orden cronológico.
  const renderTimeline = res => {
    const o = res.order;
    const entries = [];
    if (o.created_at) entries.push({ at: o.created_at, kind: 'created', icon: 'shopping-cart', title: 'Pedido creado', meta: o.customer_name || o.shipping?.name || '' });
    (res.events || []).forEach(ev => entries.push({
      at: ev.created_at, kind: 'status', icon: 'refresh-cw',
      title: ev.from_status ? `${statusLabel(ev.from_status)} → ${statusLabel(ev.to_status)}` : `Estado: ${statusLabel(ev.to_status)}`,
      meta: ev.actor || '', text: ev.note ? (ev.from_status ? `Mensaje al cliente: ${ev.note}` : ev.note) : '',
    }));
    (res.activity || []).forEach(a => {
      const meta = ACTIVITY_META[a.kind] || { label: a.kind, icon: 'info' };
      entries.push({ at: a.created_at, kind: a.kind, icon: meta.icon, title: meta.label, meta: a.actor || '', text: a.body });
    });
    entries.forEach((entry, index) => { entry.order = index; entry.time = utc(entry.at)?.getTime() || 0; });
    entries.sort((a, b) => a.time - b.time || a.order - b.order);
    $('#modalTimeline').innerHTML = entries.length
      ? entries.map(e => `
        <div class="timeline-item tl-${esc(e.kind)}">
          <span class="tl-icon" aria-hidden="true"><i data-lucide="${e.icon}"></i></span>
          <div><strong>${esc(e.title)}</strong>
          <span class="muted"> · ${esc(ecTime(e.at))}${e.meta ? ' · ' + esc(e.meta) : ''}</span>
          ${e.text ? `<div class="note">${esc(e.text)}</div>` : ''}</div>
        </div>`).join('')
      : '<div class="muted">Sin actividad registrada aún.</div>';
  };

  const renderContactActions = o => {
    const shipping = o.shipping || {};
    const email = o.customer_email || shipping.email || '';
    const phone = o.customer_phone || shipping.phone || '';
    const name = (o.customer_name || shipping.name || '').split(' ')[0];
    const wa = waNumber(phone);
    const message = `Hola${name ? ' ' + name : ''}, te escribimos de SmartISP por tu pedido ${o.id} (estado: ${statusLabel(o.status)}).`;
    const subject = `Tu pedido ${o.id} en SmartISP`;
    $('#modalActions').innerHTML = [
      wa ? `<a class="button small" href="https://wa.me/${esc(wa)}?text=${encodeURIComponent(message)}" target="_blank" rel="noopener" id="orderWhatsapp"><i data-lucide="message-circle" aria-hidden="true"></i> WhatsApp</a>` : '',
      email ? `<a class="button small" href="mailto:${esc(email)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(message)}" id="orderMail"><i data-lucide="mail" aria-hidden="true"></i> Correo</a>` : '',
      `<a class="button small" href="/comprobante.html?id=${encodeURIComponent(o.id)}" target="_blank" rel="noopener" id="orderPrint"><i data-lucide="printer" aria-hidden="true"></i> Imprimir / PDF</a>`,
    ].join('');
  };

  const renderOrderModal = res => {
    const o = res.order;
    currentModalOrder = o;
    const items = orderItems(o);
    const shipping = o.shipping || {};
    $('#modalOrderTitle').textContent = `Pedido ${o.id}`;
    $('#modalOrderDate').textContent = o.created_at ? ecTime(o.created_at) + ' · ' + relTime(o.created_at) : '';
    $('#modalCustomer').innerHTML = `
      ${esc(o.customer_name || shipping.name || '—')}<br>
      <span class="muted">${esc(o.customer_email || shipping.email || '')}</span><br>
      <span class="muted">${esc(o.customer_phone || shipping.phone || '')}</span>`;
    $('#modalShipping').innerHTML = `
      ${esc(shipping.city || '—')}<br>
      <span class="muted">${esc(shipping.address || '')}</span>
      ${shipping.notes ? `<br><span class="muted">Nota: ${esc(shipping.notes)}</span>` : ''}`;
    renderContactActions(o);
    $('#modalItems').innerHTML = items.length
      ? items.map(it => `
        <div class="item-row">
          <span>${esc(it.name || 'Producto')} <span class="muted">× ${Number(it.quantity || 1)}</span></span>
          <strong>${it.price > 0 ? money(it.price * (it.quantity || 1)) : 'Cotizar'}</strong>
        </div>`).join('')
      : '<div class="muted">Sin ítems registrados.</div>';
    $('#modalTotal').textContent = money(o.total);
    $('#modalPayment').textContent = o.payment_status ? `Pago: ${PAYMENT_LABELS[o.payment_status] || o.payment_status}${o.payment_provider ? ' · ' + o.payment_provider : ''}` : '';
    $('#statusSelect').innerHTML = STATUS_FLOW.concat(STATUS_META[o.status] && !STATUS_FLOW.includes(o.status) ? [o.status] : [])
      .map(s => `<option value="${s}" ${s === o.status ? 'selected' : ''}>${esc(STATUS_META[s]?.label || s)}</option>`).join('');
    syncStatusSubmitButton();
    $('#statusNote').value = '';
    syncNoteButton();
    renderTimeline(res);
    icons();
  };

  const reloadModal = async orderId => {
    const res = await api(`admin-order?id=${encodeURIComponent(orderId)}`);
    if (currentModalOrder && currentModalOrder.id === orderId && !$('#orderModal').hidden) renderOrderModal(res);
  };

  const openOrderModal = async id => {
    const seq = ++modalSeq;
    try {
      const res = await api(`admin-order?id=${encodeURIComponent(id)}`);
      if (seq !== modalSeq) return;
      if (currentModalOrder?.id !== res.order.id) $('#noteText').value = '';
      renderOrderModal(res);
      $('#statusMessage').classList.remove('show');
      $('#noteMessage').classList.remove('show');
      if ($('#orderModal').hidden) {
        lastFocus = document.activeElement;
        $('#orderModal').hidden = false;
        $('#closeOrderModalBtn').focus();
      }
    } catch (e) {
      toast('No se pudo cargar el detalle del pedido: ' + e.message, true);
    }
  };

  const closeOrderModal = () => {
    if ($('#orderModal').hidden) return;
    modalSeq++;
    $('#orderModal').hidden = true;
    if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
  };

  $('#closeOrderModalBtn').addEventListener('click', closeOrderModal);
  $('#orderModal').addEventListener('click', e => { if (e.target.id === 'orderModal') closeOrderModal(); });
  document.addEventListener('keydown', e => {
    if ($('#orderModal').hidden) return;
    if (e.key === 'Escape') { closeOrderModal(); return; }
    if (e.key !== 'Tab') return;
    const focusable = [...$('#orderModal').querySelectorAll('button, select, textarea, a[href], input')].filter(el => !el.disabled && el.offsetParent !== null);
    if (!focusable.length) return;
    const first = focusable[0], last = focusable[focusable.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });
  $('#statusSelect').addEventListener('change', syncStatusSubmitButton);
  $('#noteText').addEventListener('input', syncNoteButton);

  $('#noteForm').addEventListener('submit', async e => {
    e.preventDefault();
    const note = $('#noteText').value.trim();
    if (!currentModalOrder || savingNote || !note) return;
    const orderId = currentModalOrder.id;
    savingNote = true;
    syncNoteButton();
    try {
      await post('admin-order-note', { id: orderId, note });
      $('#noteText').value = '';
      showMessage($('#noteMessage'), 'Nota guardada. Solo la ve el equipo.');
      try { await reloadModal(orderId); } catch (reloadError) { /* la nota ya se guardó */ }
    } catch (err) {
      showMessage($('#noteMessage'), err.message, true);
    } finally {
      savingNote = false;
      syncNoteButton();
    }
  });

  $('#statusForm').addEventListener('submit', async e => {
    e.preventDefault();
    if (!currentModalOrder || updatingStatus) return;
    if (String($('#statusSelect').value).toLowerCase() === String(currentModalOrder.status || '').toLowerCase()) {
      showMessage($('#statusMessage'), 'El pedido ya está en ese estado.', true);
      return;
    }
    const orderId = currentModalOrder.id;
    updatingStatus = true;
    syncStatusSubmitButton();
    try {
      await post('admin-order-status', { id: orderId, status: $('#statusSelect').value, note: $('#statusNote').value.trim() });
      try { await reloadModal(orderId); } catch (reloadError) { /* el estado ya se guardó; la lista de abajo lo refleja */ }
      showMessage($('#statusMessage'), 'Estado actualizado. Se intentó notificar al cliente por correo.');
      await Promise.all([loadOrders(), loadStats()]);
    } catch (err) {
      showMessage($('#statusMessage'), err.message, true);
    } finally {
      updatingStatus = false;
      syncStatusSubmitButton();
    }
  });

  await loadStats();
  syncTabs();
  await loadOrders();
}));
