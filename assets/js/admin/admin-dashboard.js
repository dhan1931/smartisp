// Dashboard del panel (admin.html). Requiere admin-page.js y admin-charts.js.
document.addEventListener('DOMContentLoaded', () => SmartAdmin.boot(async user => {
  const { $, esc, money, compact, number, api, icons, toast } = SmartAdmin;

  const STATUS = {
    quote_requested: 'Cotización', pending: 'Pendiente de pago', paid: 'Pago confirmado', preparing: 'Preparando',
    shipped: 'Enviado', delivered: 'Entregado', cancelled: 'Cancelado', received: 'Recibido',
  };
  const METRICS = {
    pipeline: { title: 'Valor de pedidos', format: money, axis: v => '$' + compact(v) },
    revenue: { title: 'Ventas cobradas', format: money, axis: v => '$' + compact(v) },
    orders: { title: 'Pedidos', format: number, axis: compact },
  };
  const state = { metric: 'pipeline', analytics: null };

  // Encabezado
  const now = new Date();
  const hour = now.getHours();
  const name = String(user?.name || user?.email || '').split(/[ @]/)[0];
  $('#greeting').textContent = `${hour < 12 ? 'Buenos días' : hour < 19 ? 'Buenas tardes' : 'Buenas noches'}${name ? ', ' + name : ''}`;
  $('#today').textContent = now.toLocaleDateString('es-EC', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

  const delta = (now, before) => {
    if (!before) return now ? '<span class="up">Nuevo</span> vs 30 días previos' : 'Sin movimiento en 30 días';
    const pct = Math.round(((now - before) / before) * 100);
    if (pct === 0) return 'Igual que los 30 días previos';
    return `<span class="${pct > 0 ? 'up' : 'down'}">${pct > 0 ? '▲' : '▼'} ${Math.abs(pct)}%</span> vs 30 días previos`;
  };

  const renderKpis = ({ analytics, orders, business }) => {
    const t = analytics?.totals || {}, p = analytics?.previous || {};
    const byStatus = Object.fromEntries((orders?.by_status || []).map(s => [s.status, Number(s.total || 0)]));
    const pending = byStatus.pending || 0;
    const tiles = [
      { href: '/estadisticas.html', icon: 'banknote', label: 'Ventas cobradas · 30 días', value: analytics ? money(t.revenue) : '—', foot: analytics ? delta(t.revenue, p.revenue) : '' },
      { href: '/estadisticas.html', icon: 'trending-up', label: 'Valor de pedidos · 30 días', value: analytics ? money(t.pipeline) : '—', foot: analytics ? delta(t.pipeline, p.pipeline) : '' },
      { href: '/pedidos.html', icon: 'shopping-cart', label: 'Pedidos hoy', value: orders ? number(orders.today_count) : '—', foot: analytics ? `${number(t.active_orders)} en los últimos 30 días` : '' },
      { href: '/pedidos.html?status=pending', icon: 'credit-card', label: 'Pendientes de pago', value: orders ? number(pending) : '—', foot: pending ? 'Confirma el pago o contacta al cliente' : 'Nada pendiente', alert: pending > 0 },
      { href: '/estadisticas.html', icon: 'receipt', label: 'Ticket promedio · 30 días', value: analytics && t.paid_orders ? money(t.avg_ticket) : '—', foot: analytics ? `${number(t.paid_orders)} pedido${t.paid_orders === 1 ? '' : 's'} cobrado${t.paid_orders === 1 ? '' : 's'}` : '' },
      { href: '/clientes.html', icon: 'users', label: 'Clientes registrados', value: business ? number(business.customers) : '—', foot: analytics ? `${number(t.new_customers)} nuevo${t.new_customers === 1 ? '' : 's'} en 30 días` : '' },
    ];
    $('#kpis').innerHTML = tiles.map(k => `<a class="kpi ${k.alert ? 'is-alert' : ''}" href="${k.href}">
      <span class="kpi-label"><i data-lucide="${k.icon}"></i>${k.label}</span>
      <strong class="kpi-value">${k.value}</strong>
      <span class="kpi-foot">${k.foot}</span></a>`).join('');
  };

  const renderTrend = () => {
    if (!state.analytics) return;
    const metric = METRICS[state.metric];
    const points = state.analytics.daily.map(d => {
      const date = new Date(d.day + 'T12:00:00');
      return { ...d, label: date.toLocaleDateString('es-EC', { day: 'numeric', month: 'short' }), title: date.toLocaleDateString('es-EC', { weekday: 'long', day: 'numeric', month: 'long' }) };
    });
    const total = points.reduce((sum, p) => sum + p[state.metric], 0);
    $('#trendTitle').textContent = `${metric.title} por día`;
    $('#trendHint').textContent = `Últimos 30 días · total ${metric.format(total)}`;
    SmartCharts.columns($('#trendChart'), points, {
      key: state.metric, format: metric.format, axis: metric.axis, label: `${metric.title} por día, últimos 30 días`,
      emptyText: `Todavía no hay ${metric.title.toLowerCase()} en los últimos 30 días.`,
      detail: p => `${number(p.orders)} pedido${p.orders === 1 ? '' : 's'}`,
    });
  };

  const renderAttention = ({ orders, products, insights }) => {
    const items = [];
    const byStatus = Object.fromEntries((orders?.by_status || []).map(s => [s.status, Number(s.total || 0)]));
    const plural = (n, one, many) => `${number(n)} ${n === 1 ? one : many}`;
    if (byStatus.quote_requested) items.push({ cls: 'warn', icon: 'file-question', href: '/pedidos.html?status=quote_requested', title: `${plural(byStatus.quote_requested, 'cotización', 'cotizaciones')} por responder`, text: 'Pedidos sin precio final esperando tu respuesta.' });
    if (byStatus.pending) items.push({ cls: 'warn', icon: 'credit-card', href: '/pedidos.html?status=pending', title: `${plural(byStatus.pending, 'pedido pendiente', 'pedidos pendientes')} de pago`, text: 'Al confirmar el cobro, márcalo como "Pago confirmado".' });
    if (byStatus.paid || byStatus.preparing) items.push({ cls: '', icon: 'package-check', href: '/pedidos.html?status=paid', title: `${plural((byStatus.paid || 0) + (byStatus.preparing || 0), 'pedido', 'pedidos')} por despachar`, text: 'Cobrados o en preparación, todavía sin enviar.' });
    const inv = insights?.inventory_configured ? insights.inventory : null;
    if (inv?.out_of_stock) items.push({ cls: 'bad', icon: 'package-x', href: '/inventario.html?filter=out', title: `${plural(inv.out_of_stock, 'producto agotado', 'productos agotados')}`, text: 'No se pueden comprar hasta que cargues stock.' });
    if (products?.without_photo) items.push({ cls: 'bad', icon: 'image-off', href: '/editor-catalogo.html?photo=without_photo', title: `${plural(products.without_photo, 'producto', 'productos')} sin imagen`, text: 'Una foto mejora la confianza y la conversión.' });
    if (!items.length && orders && products) items.push({ cls: 'ok', icon: 'circle-check', href: '/pedidos.html', title: 'Todo al día', text: 'No hay pedidos ni productos pendientes de atención.' });
    $('#attention').innerHTML = items.map(a => `<a class="attention-item ${a.cls}" href="${a.href}">
      <span class="attention-icon"><i data-lucide="${a.icon}"></i></span>
      <div><strong>${esc(a.title)}</strong><span>${esc(a.text)}</span></div><i data-lucide="chevron-right"></i></a>`).join('')
      || '<p class="muted">No se pudieron revisar los pendientes.</p>';
  };

  const ago = value => {
    const d = SmartAdmin.parseDate(value);
    if (!d) return '';
    const minutes = Math.round((Date.now() - d) / 60000);
    if (minutes < 1) return 'ahora';
    if (minutes < 60) return `hace ${minutes} min`;
    if (minutes < 1440) return `hace ${Math.round(minutes / 60)} h`;
    if (minutes < 2880) return 'ayer';
    return d.toLocaleDateString('es-EC', { day: 'numeric', month: 'short' });
  };

  const renderRecent = recent => {
    const rows = recent?.orders || [];
    $('#recent').innerHTML = rows.length ? rows.map(o => {
      const shipping = o.shipping || {};
      const client = o.customer_name || shipping.name || o.customer_email || shipping.email || 'Cliente';
      return `<tr data-href="/pedidos.html?q=${encodeURIComponent(o.id)}" tabindex="0" aria-label="Ver pedido ${esc(o.id)} de ${esc(client)}">
        <td class="order-id">${esc(o.id)}</td>
        <td><div class="order-client">${esc(client)}</div></td>
        <td><span class="badge st-${esc(o.status)}">${esc(STATUS[o.status] || o.status || 'Sin estado')}</span></td>
        <td class="order-when">${esc(ago(o.created_at))}</td>
        <td class="num"><strong>${Number(o.total) > 0 ? money(o.total) : 'Cotizar'}</strong></td></tr>`;
    }).join('') : `<tr><td colspan="5" class="muted">${recent ? 'Todavía no hay pedidos.' : 'No se pudieron cargar los pedidos.'}</td></tr>`;
  };

  const renderHealth = (products, business) => {
    if (!products) { $('#health').innerHTML = '<p class="muted">No se pudo revisar el catálogo.</p>'; return; }
    const total = Number(products.total || 0);
    const pct = total ? Math.round((products.with_photo / total) * 100) : 0;
    const hidden = total - Number(products.visible || 0);
    $('#health').innerHTML = `<div class="health">
        <div class="ring" style="--pct:${pct}" role="img" aria-label="${pct}% de productos con foto"><strong>${pct}%</strong></div>
        <div class="health-copy"><strong>Productos con foto</strong><span>${number(products.with_photo)} de ${number(total)} productos tienen imagen. ${business ? `Catálogo en ${number(business.categories)} categorías.` : ''}</span></div>
      </div>
      <div class="metric-grid">
        <a class="metric" href="/editor-catalogo.html"><strong>${number(total)}</strong><span>Productos</span></a>
        <a class="metric ${products.without_photo ? 'bad' : ''}" href="/editor-catalogo.html?photo=without_photo"><strong>${number(products.without_photo)}</strong><span>Sin imagen</span></a>
        <a class="metric ${hidden ? 'warn' : ''}" href="/editor-catalogo.html?visible=hidden"><strong>${number(hidden)}</strong><span>Ocultos en tienda</span></a>
      </div>`;
  };

  const renderTop = insights => {
    const rows = (insights?.top_products || []).map(p => ({ label: p.name, value: p.units, valueText: `${number(p.units)} u.`, extra: `${number(p.orders)} pedido${p.orders === 1 ? '' : 's'}` }));
    $('#top').innerHTML = insights ? SmartCharts.bars(rows, 'Todavía no hay ventas en los últimos 30 días.') : '<p class="muted">No se pudieron cargar los más vendidos.</p>';
  };

  const renderInventory = insights => {
    if (!insights) { $('#inventory').innerHTML = '<p class="muted">No se pudo consultar el inventario.</p>'; return; }
    if (!insights.inventory_configured) {
      $('#inventory').innerHTML = '<p class="muted" style="margin:0;line-height:1.5">Ningún producto tiene stock controlado: todos se venden sin límite. <a href="/inventario.html">Carga el stock en Inventario</a> para que los pedidos lo aparten y se descuente al enviar.</p>';
      return;
    }
    const i = insights.inventory;
    $('#inventory').innerHTML = `<div class="metric-grid">
      <a class="metric" href="/inventario.html?filter=tracked"><strong>${number(i.tracked_products)}</strong><span>Con stock controlado</span></a>
      <a class="metric" href="/inventario.html"><strong>${number(i.available_units)}</strong><span>Unidades libres</span></a>
      <a class="metric ${i.out_of_stock ? 'bad' : ''}" href="/inventario.html?filter=out"><strong>${number(i.out_of_stock)}</strong><span>Agotados</span></a>
    </div><p class="muted" style="margin:12px 0 0;font-size:12.5px">${number(i.reserved_units)} unidades apartadas por pedidos en curso.</p>`;
  };

  // Cada bloque se dibuja con lo que llegó; una consulta que falla no deja vacío al resto.
  const load = async () => {
    const button = $('#refresh');
    button.disabled = true;
    $('#dashboard').classList.add('is-refreshing');
    const settle = action => api(action).catch(error => { console.warn(action, error); return null; });
    const [analytics, orders, recent, products, business, insights] = await Promise.all([
      settle('admin-analytics?days=30'), settle('admin-orders-stats'), settle('admin-orders?limit=6'),
      settle('admin-products-stats'), settle('admin-dashboard-stats'), settle('admin-dashboard-insights'),
    ]);
    state.analytics = analytics;
    renderKpis({ analytics, orders, business });
    renderAttention({ orders, products, insights });
    renderRecent(recent);
    renderHealth(products, business);
    renderTop(insights);
    renderInventory(insights);
    icons();
    // El gráfico al final: su tarjeta toma el alto de la lista de pendientes, y el SVG se dibuja
    // con el alto que tenga la caja en ese momento.
    if (analytics) renderTrend(); else $('#trendChart').innerHTML = '<div class="chart-empty">No se pudieron cargar las ventas.</div>';
    $('#dashboard').classList.remove('is-refreshing');
    button.disabled = false;
    if (!analytics && !orders && !products) toast('No se pudo conectar con el servidor para cargar el resumen.', true);
  };

  $('#refresh').addEventListener('click', load);
  $('#metric').addEventListener('click', event => {
    const button = event.target.closest('[data-metric]');
    if (!button) return;
    $('#metric').querySelectorAll('button').forEach(b => b.classList.toggle('is-active', b === button));
    state.metric = button.dataset.metric;
    renderTrend();
  });
  $('#recent').addEventListener('click', event => {
    const row = event.target.closest('tr[data-href]');
    if (row) window.location.assign(row.dataset.href);
  });
  $('#recent').addEventListener('keydown', event => {
    const row = event.target.closest('tr[data-href]');
    if (row && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); window.location.assign(row.dataset.href); }
  });
  SmartCharts.onResize(renderTrend);

  await load();
}));
