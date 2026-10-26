document.addEventListener('DOMContentLoaded', () => SmartAdmin.boot(async user => {
  const { $, esc, money, api, post, toast, icons } = SmartAdmin;
  const showMessage = (element, text, error = false) => { element.textContent = text; element.classList.remove('show'); element.classList.toggle('error', error); requestAnimationFrame(() => element.classList.add('show')); };

  const STATUS_META = {
    quote_requested: { label: 'Cotización solicitada', color: '#7c3aed', bg: '#f3e8ff' },
    pending:         { label: 'Pendiente de pago',      color: '#b45309', bg: '#fef3c7' },
    paid:            { label: 'Pago confirmado',        color: '#047857', bg: '#d1fae5' },
    preparing:       { label: 'Preparando',             color: '#0369a1', bg: '#e0f2fe' },
    shipped:         { label: 'Enviado / Retiro',       color: '#1d4ed8', bg: '#dbeafe' },
    delivered:       { label: 'Entregado',              color: '#15803d', bg: '#dcfce7' },
    cancelled:       { label: 'Cancelado',              color: '#b91c1c', bg: '#fee2e2' },
    received:        { label: 'Recibido (legado)',      color: '#6b7280', bg: '#f1f5f9' },
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

  const statusBadge = status => {
    const meta = STATUS_META[status] || { label: status || 'Sin estado', color: '#475569', bg: '#f1f5f9' };
    return `<span class="status-badge" style="color:${meta.color};background:${meta.bg};">${esc(meta.label)}</span>`;
  };
  const orderItems = o => Array.isArray(o.items?.items) ? o.items.items : (Array.isArray(o.items) ? o.items : []);

  const initialParams = new URLSearchParams(location.search);
  // ?q= abre la lista ya filtrada (los pedidos recientes del dashboard enlazan así).
  const state = { page: 1, limit: 20, status: initialParams.get('status') || 'all', q: (initialParams.get('q') || '').trim(), totalPages: 1 };
  if (state.q) $('#searchInput').value = state.q;
  $('#adminIdentity').textContent = `${user.name || ''} ${user.surname || ''} - ${user.email}`;

  const emptyText = $('#emptyState').textContent;
  let ordersSeq = 0;

  const syncTabs = () => document.querySelectorAll('.status-tab').forEach(btn => {
    const active = btn.dataset.status === state.status;
    btn.classList.toggle('active', active);
    btn.setAttribute('aria-pressed', String(active));
  });

  const renderStats = stats => {
    const byStatus = Object.fromEntries((stats.by_status || []).map(s => [s.status, s.total]));
    const cards = [
      { label: 'Total pedidos', num: stats.total || 0 },
      { label: 'Hoy', num: stats.today_count || 0 },
      { label: 'Pendientes de pago', num: byStatus.pending || 0 },
      { label: 'Por preparar', num: byStatus.paid || 0 },
      { label: 'Completados', num: stats.completed_count || 0 },
      { label: 'Ingresos confirmados', num: money(stats.revenue || 0) },
    ];
    $('#statsRow').innerHTML = cards.map(c => `<div class="stat-card"><div class="num">${c.num}</div><div class="label">${esc(c.label)}</div></div>`).join('');
    const tabs = [{ key: 'all', label: 'Todos', count: stats.total || 0 }]
      .concat(STATUS_FLOW.map(k => ({ key: k, label: STATUS_META[k].label, count: byStatus[k] || 0 })));
    $('#statusTabs').innerHTML = tabs.map(t => `
      <button type="button" class="status-tab" data-status="${t.key}" aria-pressed="false">
        ${esc(t.label)} <span class="cnt">${t.count}</span>
      </button>`).join('');
    syncTabs();
  };

  const loadStats = async () => {
    try { renderStats(await api('admin-orders-stats')); }
    catch (e) { console.warn('Error cargando estadisticas de pedidos:', e); }
  };

  const renderOrdersTable = orders => {
    const body = $('#ordersBody');
    $('#emptyState').textContent = emptyText;
    $('#emptyState').classList.remove('is-error');
    $('#emptyState').classList.toggle('hidden', orders.length > 0);
    $('#ordersTableWrap').classList.toggle('hidden', orders.length === 0);
    body.innerHTML = orders.map(o => {
      const itemCount = orderItems(o).reduce((sum, it) => sum + Number(it.quantity || 1), 0);
      const customerName = o.customer_name || o.shipping?.name || '—';
      const customerEmail = o.customer_email || o.shipping?.email || '';
      const date = o.created_at ? new Date(o.created_at.replace(' ', 'T')).toLocaleDateString('es-EC', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
      return `
        <tr>
          <td class="order-id">${esc(o.id)}</td>
          <td>${date}</td>
          <td>${esc(customerName)}<br><span style="color:var(--muted);font-size:11.5px;">${esc(customerEmail)}</span></td>
          <td>${itemCount} ud.</td>
          <td>${money(o.total)}</td>
          <td>${statusBadge(o.status)}</td>
          <td><div class="row-actions"><button class="row-link" type="button" data-id="${esc(o.id)}">Ver detalle <i data-lucide="arrow-right" style="width:12px;height:12px;"></i></button>
            ${['cancelled', 'canceled'].includes(String(o.status || '').toLowerCase()) ? `<button class="row-delete" type="button" data-delete-id="${esc(o.id)}" aria-label="Eliminar pedido cancelado ${esc(o.id)}" title="Eliminar pedido cancelado"><i data-lucide="trash-2" style="width:15px;height:15px;"></i></button>` : ''}
          </div></td>
        </tr>`;
    }).join('');
    icons();
  };

  const loadOrders = async () => {
    const seq = ++ordersSeq;
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
      $('#ordersTableWrap').classList.add('hidden');
      $('#emptyState').textContent = 'Error al cargar pedidos: ' + e.message;
      $('#emptyState').classList.add('is-error');
      $('#emptyState').classList.remove('hidden');
    }
  };

  const deleteCancelledOrder = async (id, button) => {
    if (!window.confirm(`Eliminar definitivamente el pedido cancelado ${id}? Esta accion no se puede deshacer.`)) return;
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

  $('#statusTabs').addEventListener('click', e => {
    const btn = e.target.closest('.status-tab');
    if (!btn) return;
    state.status = btn.dataset.status;
    state.page = 1;
    syncTabs();
    loadOrders();
  });
  $('#ordersBody').addEventListener('click', e => {
    const view = e.target.closest('.row-link');
    if (view) { openOrderModal(view.dataset.id); return; }
    const del = e.target.closest('.row-delete');
    if (del) deleteCancelledOrder(del.dataset.deleteId, del);
  });

  let searchDebounce = null;
  $('#searchInput').addEventListener('input', e => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => { state.q = e.target.value.trim(); state.page = 1; loadOrders(); }, 350);
  });
  $('#prevPageBtn').addEventListener('click', () => { if (state.page > 1) { state.page--; loadOrders(); } });
  $('#nextPageBtn').addEventListener('click', () => { if (state.page < state.totalPages) { state.page++; loadOrders(); } });

  let currentModalOrder = null;
  let updatingStatus = false;
  let modalSeq = 0;
  let lastFocus = null;
  const syncStatusSubmitButton = () => {
    const selectedStatus = String($('#statusSelect').value || '').toLowerCase();
    const currentStatus = String(currentModalOrder?.status || '').toLowerCase();
    $('#statusHint').textContent = selectedStatus !== currentStatus ? (STATUS_EFFECTS[selectedStatus] || '') : '';
    $('#statusSubmitBtn').disabled = updatingStatus || !currentModalOrder || selectedStatus === currentStatus;
  };

  const renderOrderModal = res => {
    const o = res.order;
    currentModalOrder = o;
    const items = orderItems(o);
    const shipping = o.shipping || {};
    $('#modalOrderTitle').textContent = `Pedido ${o.id}`;
    $('#modalOrderDate').textContent = o.created_at ? new Date(o.created_at.replace(' ', 'T')).toLocaleString('es-EC') : '';
    $('#modalCustomer').innerHTML = `
      ${esc(o.customer_name || shipping.name || '—')}<br>
      <span style="color:var(--muted);">${esc(o.customer_email || shipping.email || '')}</span><br>
      <span style="color:var(--muted);">${esc(o.customer_phone || shipping.phone || '')}</span>`;
    $('#modalShipping').innerHTML = `
      ${esc(shipping.city || '—')}<br>
      <span style="color:var(--muted);">${esc(shipping.address || '')}</span>
      ${shipping.notes ? `<br><span style="color:var(--muted);">Nota: ${esc(shipping.notes)}</span>` : ''}`;
    $('#modalItems').innerHTML = items.length
      ? items.map(it => `
        <div class="item-row">
          <span>${esc(it.name || 'Producto')} <span style="color:var(--muted);">× ${Number(it.quantity || 1)}</span></span>
          <strong>${it.price > 0 ? money(it.price * (it.quantity || 1)) : 'Cotizar'}</strong>
        </div>`).join('')
      : '<div style="color:var(--muted);font-size:13px;">Sin items registrados.</div>';
    $('#modalTotal').textContent = money(o.total);
    $('#modalPayment').textContent = o.payment_status ? `Pago: ${PAYMENT_LABELS[o.payment_status] || o.payment_status}${o.payment_provider ? ' · ' + o.payment_provider : ''}` : '';
    $('#statusSelect').innerHTML = STATUS_FLOW.concat(STATUS_META[o.status] && !STATUS_FLOW.includes(o.status) ? [o.status] : [])
      .map(s => `<option value="${s}" ${s === o.status ? 'selected' : ''}>${esc(STATUS_META[s]?.label || s)}</option>`).join('');
    syncStatusSubmitButton();
    $('#statusNote').value = '';
    $('#modalTimeline').innerHTML = (res.events || []).length
      ? res.events.map(ev => `
        <div class="timeline-item">
          <strong>${esc(STATUS_META[ev.to_status]?.label || ev.to_status)}</strong>
          <span style="color:var(--muted);"> · ${new Date(String(ev.created_at || '').replace(' ', 'T')).toLocaleString('es-EC')} · ${esc(ev.actor || '')}</span>
          ${ev.note ? `<div style="color:var(--muted);margin-top:2px;">${esc(ev.note)}</div>` : ''}
        </div>`).join('')
      : '<div style="color:var(--muted);font-size:12.5px;">Sin cambios de estado registrados aún.</div>';
  };

  const openOrderModal = async id => {
    const seq = ++modalSeq;
    try {
      const res = await api(`admin-order?id=${encodeURIComponent(id)}`);
      if (seq !== modalSeq) return;
      renderOrderModal(res);
      $('#statusMessage').classList.remove('show');
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
      try {
        const res = await api(`admin-order?id=${encodeURIComponent(orderId)}`);
        if (currentModalOrder && currentModalOrder.id === orderId) renderOrderModal(res);
      } catch (reloadError) { /* el estado ya se guardó; la lista de abajo lo refleja */ }
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
