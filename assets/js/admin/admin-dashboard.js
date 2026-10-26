// Dashboard del panel (admin.html). Una sola petición agregada: admin-insights (api/handlers/insights.php).
document.addEventListener('DOMContentLoaded', () => SmartAdmin.boot(async user => {
  const { $, esc, money, compact, number, api, post, icons, toast, delta, relTime, utc, remembered, remember, modal, confirm, state: stateHtml } = SmartAdmin;

  const STATUS = {
    quote_requested: { label: 'Cotización', color: 'var(--series-7)' },
    pending: { label: 'Pendiente de pago', color: 'var(--series-4)' },
    paid: { label: 'Pago confirmado', color: 'var(--series-1)' },
    preparing: { label: 'Preparando', color: 'var(--series-3)' },
    shipped: { label: 'Enviado', color: 'var(--series-5)' },
    delivered: { label: 'Entregado', color: 'var(--series-6)' },
    received: { label: 'Recibido', color: 'var(--series-2)' },
    cancelled: { label: 'Cancelado', color: 'var(--series-8)' },
    canceled: { label: 'Cancelado', color: 'var(--series-8)' },
    refunded: { label: 'Reembolsado', color: 'var(--series-8)' },
  };
  const STATUS_ORDER = ['quote_requested', 'pending', 'paid', 'preparing', 'shipped', 'delivered', 'received', 'cancelled', 'canceled', 'refunded'];
  const PERIOD_TEXT = { today: 'hoy', '7d': 'los últimos 7 días', '30d': 'los últimos 30 días', '90d': 'los últimos 90 días', month: 'este mes', custom: 'el rango elegido' };
  const plural = (n, one, many) => `${number(n)} ${Number(n) === 1 ? one : many}`;
  const dayLabel = (iso, opts) => new Date(iso + 'T12:00:00').toLocaleDateString('es-EC', opts);
  const shortDay = iso => dayLabel(iso, { day: 'numeric', month: 'short' });
  const hours = h => h == null ? '—' : h < 1 ? `${Math.round(h * 60)} min` : h < 48 ? `${String(h).replace('.', ',')} h` : `${String(Math.round(h / 24 * 10) / 10).replace('.', ',')} días`;
  const pctText = v => v == null ? '—' : `${String(v).replace('.', ',')}%`;

  const saved = remembered('dashboard', {});
  const state = { period: saved.period || '30d', from: saved.from || '', to: saved.to || '', compare: saved.compare !== false, top: saved.top || 'revenue', cat: saved.cat || 'revenue', data: null, seq: 0 };

  // Encabezado
  const now = new Date();
  const hour = now.getHours();
  const name = String(user?.name || user?.email || '').split(/[ @]/)[0];
  $('#greeting').textContent = `${hour < 12 ? 'Buenos días' : hour < 19 ? 'Buenas tardes' : 'Buenas noches'}${name ? ', ' + name : ''}`;
  $('#today').textContent = now.toLocaleDateString('es-EC', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  $('#comparePrev').checked = state.compare;

  const persist = () => remember('dashboard', { period: state.period, from: state.from, to: state.to, compare: state.compare, top: state.top, cat: state.cat });
  const syncSegmented = (box, attr, value) => box.querySelectorAll('button').forEach(b => { const on = b.dataset[attr] === value; b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', String(on)); });

  // Esqueletos con el mismo alto final: el contenido no empuja la página al llegar.
  const skeleton = () => {
    $('#kpis').innerHTML = Array.from({ length: 6 }, () => '<div class="kpi" aria-hidden="true"><span class="sk sk-line" style="width:55%"></span><span class="sk sk-title"></span><span class="sk sk-line" style="width:40%"></span><span class="sk" style="height:34px;margin-top:auto"></span></div>').join('');
    ['#goal', '#cash', '#attention', '#status', '#buyers', '#ops', '#top', '#categories', '#cities', '#inventory', '#idle', '#customers'].forEach(sel => { $(sel).innerHTML = '<span class="sk sk-line"></span><span class="sk sk-line" style="width:80%"></span><span class="sk sk-line" style="width:60%"></span>'; });
    $('#trendChart').innerHTML = '<span class="sk sk-block" style="height:300px"></span>';
    $('#heatmap').innerHTML = '<span class="sk sk-block" style="height:200px"></span>';
    $('#recent').innerHTML = SmartAdmin.skeletonRows(5, 6);
  };

  const sparkValues = (series, fn) => series.map(fn);

  const renderKpis = d => {
    const t = d.totals, p = d.previous, s = d.series;
    const openQuotes = (d.alerts?.service_quotes_new || 0) + (d.alerts?.orders_quote_requested || 0);
    const tiles = [
      { href: '/estadisticas.html', icon: 'banknote', label: 'Ventas confirmadas', value: money(t.revenue), delta: delta(t.revenue, p.revenue), foot: `${plural(t.paid_orders, 'pedido cobrado', 'pedidos cobrados')}`, spark: sparkValues(s, x => x.revenue) },
      { href: '/pedidos.html', icon: 'shopping-cart', label: 'Pedidos', value: number(t.orders), delta: delta(t.orders, p.orders), foot: `${money(t.pipeline)} en valor`, spark: sparkValues(s, x => x.orders) },
      { href: '/estadisticas.html', icon: 'receipt', label: 'Ticket promedio', value: t.paid_orders ? money(t.avg_ticket) : '—', delta: t.paid_orders ? delta(t.avg_ticket, p.avg_ticket) : '', foot: 'Ventas ÷ pedidos cobrados', spark: sparkValues(s, x => x.paid_orders ? x.revenue / x.paid_orders : 0) },
      { href: '/clientes.html', icon: 'user-plus', label: 'Clientes nuevos', value: number(t.new_customers), delta: delta(t.new_customers, p.new_customers), foot: 'Cuentas registradas', spark: sparkValues(s, x => x.new_customers) },
      { href: '/cotizaciones.html?status=nuevo', icon: 'messages-square', label: 'Cotizaciones abiertas', value: number(openQuotes), delta: delta(t.new_quotes, p.new_quotes), foot: `${plural(t.new_quotes, 'nueva', 'nuevas')} en el periodo`, spark: sparkValues(s, x => x.quotes), alert: openQuotes > 0 },
      { href: '/pedidos.html?status=cancelled', icon: 'circle-x', label: 'Tasa de cancelación', value: pctText(t.cancel_rate), delta: delta(t.cancel_rate, p.cancel_rate, { invert: true }), foot: plural(t.void_orders, 'pedido anulado', 'pedidos anulados'), spark: sparkValues(s, x => (x.orders + x.void_orders) ? x.void_orders / (x.orders + x.void_orders) : 0), color: 'var(--series-8)' },
    ];
    $('#kpis').innerHTML = tiles.map(k => `<a class="kpi${k.alert ? ' is-alert' : ''}" href="${k.href}">
      <span class="kpi-label"><i data-lucide="${k.icon}" aria-hidden="true"></i>${k.label}</span>
      <strong class="kpi-value">${k.value}</strong>
      <span class="kpi-foot">${k.delta}<span>${esc(k.foot)}</span></span>
      ${SmartCharts.sparkline(k.spark, { label: `Tendencia de ${k.label.toLowerCase()}`, color: k.color })}</a>`).join('');
  };

  const chartPoints = d => d.series.map((x, i) => {
    const prev = d.previous_series[i] || {};
    const hourly = d.granularity === 'hour';
    return { ...x, prev_revenue: prev.revenue || 0, prev_orders: prev.orders || 0,
      label: hourly ? x.key.slice(0, 2) + 'h' : shortDay(x.key),
      title: hourly ? `${x.key} – ${x.key.slice(0, 2)}:59` : dayLabel(x.key, { weekday: 'long', day: 'numeric', month: 'long' }) };
  });

  const renderTrend = () => {
    const d = state.data;
    if (!d) return;
    const points = chartPoints(d);
    const compareOn = state.compare;
    $('#trendHint').textContent = `${d.granularity === 'hour' ? 'Por hora' : 'Por día'} · ${money(d.totals.revenue)} confirmados y ${plural(d.totals.orders, 'pedido', 'pedidos')}.`;
    $('#trendLegend').innerHTML = '<li><i class="sw line" style="background:var(--series-1)"></i>Ventas confirmadas</li><li><i class="sw" style="background:var(--series-2)"></i>Pedidos</li>' + (compareOn ? '<li><i class="sw dash"></i>Periodo anterior</li>' : '');
    SmartCharts.combo($('#trendChart'), points, {
      label: 'Ventas confirmadas y pedidos del periodo', emptyText: 'Todavía no hay pedidos en este periodo.',
      title: p => p.title,
      panels: [
        { key: 'revenue', type: 'line', label: 'Ventas confirmadas', format: money, axis: v => '$' + compact(v), color: 'var(--series-1)', prevKey: compareOn ? 'prev_revenue' : null, prevLabel: 'Periodo anterior', height: 170 },
        { key: 'orders', type: 'bars', label: 'Pedidos', format: number, axis: compact, color: 'var(--series-2)', height: 90 },
      ],
      detail: p => p.pending_value ? `${money(p.pending_value)} por cobrar` : '',
    });
    $('#trendTable').innerHTML = `<table><thead><tr><th>${d.granularity === 'hour' ? 'Hora' : 'Día'}</th><th class="num">Ventas confirmadas</th><th class="num">Por cobrar</th><th class="num">Pedidos</th>${compareOn ? '<th class="num">Ventas periodo anterior</th>' : ''}</tr></thead><tbody>${points.map(p => `<tr><td>${esc(p.title)}</td><td class="num">${money(p.revenue)}</td><td class="num">${money(p.pending_value)}</td><td class="num">${number(p.orders)}</td>${compareOn ? `<td class="num">${money(p.prev_revenue)}</td>` : ''}</tr>`).join('')}</tbody></table>`;
  };

  const renderGoal = d => {
    const g = d.goal;
    if (!g) { $('#goal').innerHTML = stateHtml('error', 'No se pudo calcular la meta'); return; }
    const monthName = new Date(g.month + '-15T12:00:00').toLocaleDateString('es-EC', { month: 'long' });
    $('#goalHint').textContent = `Ventas confirmadas de ${monthName} · día ${g.days_elapsed} de ${g.days_in_month}.`;
    const vsPrev = g.previous_month_revenue ? delta(g.projection, g.previous_month_revenue) : '';
    if (!g.target) {
      $('#goal').innerHTML = `<div class="goal-figures"><div><span>Llevas</span><strong>${money(g.revenue)}</strong></div><div><span>Proyección al cierre</span><strong>${money(g.projection)}</strong></div></div>
        <p class="muted goal-note">Define una meta mensual para ver tu avance y el ritmo diario necesario. ${vsPrev ? `La proyección va ${vsPrev} frente a todo el mes anterior.` : ''}</p>`;
      return;
    }
    const pct = Math.min(100, (g.revenue / g.target) * 100);
    const proj = Math.min(100, (g.projection / g.target) * 100);
    const left = Math.max(0, g.target - g.revenue);
    const daysLeft = Math.max(1, g.days_in_month - g.days_elapsed + 1);
    const onTrack = g.projection >= g.target;
    $('#goal').innerHTML = `<div class="goal-figures"><div><span>Llevas</span><strong>${money(g.revenue)}</strong><small>${Math.round((g.revenue / g.target) * 100)}% de ${money(g.target)}</small></div>
        <div><span>Proyección</span><strong>${money(g.projection)}</strong><small class="${onTrack ? 'ok' : 'warn'}"><i data-lucide="${onTrack ? 'circle-check' : 'triangle-alert'}" aria-hidden="true"></i>${onTrack ? 'Vas a cumplir' : 'Por debajo de la meta'}</small></div></div>
      <div class="progress" role="progressbar" aria-label="Avance de la meta del mes" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.round(pct)}" aria-valuetext="${Math.round(pct)}% de la meta"><span class="proj" style="width:${proj}%"></span><span style="width:${pct}%"></span><i class="mark" style="left:calc(${(g.days_elapsed / g.days_in_month) * 100}% - 1px)" title="Hoy"></i></div>
      <p class="muted goal-note">${left ? `Faltan ${money(left)}: unos <strong>${money(left / daysLeft)}</strong> por día.` : '¡Meta cumplida!'} ${vsPrev ? `Proyección ${vsPrev} vs mes anterior.` : ''}</p>`;
  };

  const renderCash = d => {
    const t = d.totals;
    $('#cash').innerHTML = SmartCharts.stack([
      { label: 'Confirmado', value: t.revenue, color: 'var(--series-1)', extra: plural(t.paid_orders, 'pedido', 'pedidos') },
      { label: 'Pendiente de cobro', value: t.pending_value, color: 'var(--series-4)', extra: plural(Math.max(0, t.orders - t.paid_orders), 'pedido', 'pedidos'), href: '/pedidos.html?status=pending' },
    ], { format: money, label: 'Cobrado frente a pendiente', emptyText: 'Sin pedidos en este periodo.' });
  };

  const renderAttention = d => {
    const a = d.alerts || {}, att = d.attention || { stale_orders: [], service_quotes: [] };
    const items = [];
    att.stale_orders.forEach(o => items.push(`<div class="attention-item bad">
        <span class="attention-icon"><i data-lucide="clock-alert" aria-hidden="true"></i></span>
        <div><strong>Pedido #${esc(o.id)} sin pagar · ${money(o.total)}</strong><span>${esc(o.customer_name || o.customer_email || 'Cliente')} · creado ${esc(relTime(o.created_at))}</span></div>
        <div class="attention-actions"><button type="button" class="button small" data-pay="${esc(o.id)}" data-total="${esc(o.total)}"><i data-lucide="badge-check" aria-hidden="true"></i> Marcar pagado</button><a class="button small ghost" href="/pedidos.html?q=${encodeURIComponent(o.id)}" aria-label="Ver pedido ${esc(o.id)}"><i data-lucide="arrow-right" aria-hidden="true"></i></a></div></div>`));
    if (a.orders_pending_stale > att.stale_orders.length) items.push(link('bad', 'credit-card', `/pedidos.html?status=pending`, `${plural(a.orders_pending_stale - att.stale_orders.length, 'pedido más', 'pedidos más')} sin pagar hace +${a.stale_days} días`, 'Ver todos los pendientes de pago.'));
    else if (a.orders_pending && !att.stale_orders.length) items.push(link('warn', 'credit-card', '/pedidos.html?status=pending', plural(a.orders_pending, 'pedido pendiente de pago', 'pedidos pendientes de pago'), 'Recientes: confirma el pago cuando llegue el comprobante.'));
    if (a.orders_quote_requested) items.push(link('warn', 'file-question', '/pedidos.html?status=quote_requested', plural(a.orders_quote_requested, 'cotización de carrito por responder', 'cotizaciones de carrito por responder'), 'Clientes esperando un precio final.'));
    att.service_quotes.forEach(q => items.push(link('warn', 'messages-square', '/cotizaciones.html?status=nuevo', `${esc(q.service_interest || 'Servicio')} · ${esc(q.company_name || q.email || q.client_type || 'Cliente')}`, `Solicitud sin responder · ${relTime(q.created_at)}`, true)));
    if (a.service_quotes_new > att.service_quotes.length) items.push(link('warn', 'messages-square', '/cotizaciones.html?status=nuevo', plural(a.service_quotes_new - att.service_quotes.length, 'solicitud más sin responder', 'solicitudes más sin responder'), 'Cotizaciones de servicios.'));
    if (a.out_of_stock) items.push(link('bad', 'package-x', '/inventario.html?filter=out', plural(a.out_of_stock, 'producto agotado', 'productos agotados'), 'Visibles en la tienda sin unidades libres.'));
    if (a.low_stock) items.push(link('warn', 'triangle-alert', '/inventario.html?filter=low', plural(a.low_stock, 'producto con stock bajo', 'productos con stock bajo'), `Quedan ${d.inventory?.low_threshold || 5} unidades o menos.`));
    if (a.products_no_price) items.push(link('warn', 'circle-dollar-sign', '/editor-catalogo.html?price=quote', plural(a.products_no_price, 'producto visible sin precio', 'productos visibles sin precio'), 'Se muestran como “consultar precio”.'));
    if (a.products_no_photo) items.push(link('info', 'image-off', '/editor-catalogo.html?photo=without_photo', plural(a.products_no_photo, 'producto visible sin foto', 'productos visibles sin foto'), 'Una foto mejora la conversión.'));
    $('#attentionCount').hidden = !items.length;
    $('#attentionCount').textContent = `${items.length} pendiente${items.length === 1 ? '' : 's'}`;
    $('#attentionCount').className = 'badge warn';
    $('#attention').innerHTML = items.length ? items.join('') : stateHtml('empty', 'Todo al día', 'No hay pedidos atrasados, cotizaciones sin responder ni productos incompletos.', 'party-popper');
  };
  function link(tone, icon, href, title, text, raw) {
    return `<a class="attention-item ${tone}" href="${href}"><span class="attention-icon"><i data-lucide="${icon}" aria-hidden="true"></i></span><div><strong>${raw ? title : esc(title)}</strong><span>${esc(text)}</span></div><i data-lucide="chevron-right" class="chev" aria-hidden="true"></i></a>`;
  }

  const renderRecent = d => {
    const rows = d.recent_orders || [];
    if (!rows.length) { $('#recent').innerHTML = `<tr><td colspan="5">${stateHtml('empty', 'Todavía no hay pedidos', 'Cuando llegue el primero aparecerá aquí.', 'shopping-bag')}</td></tr>`; return; }
    $('#recent').innerHTML = rows.map(o => {
      const st = String(o.status || '').toLowerCase();
      const meta = STATUS[st] || { label: o.status || 'Sin estado' };
      const when = utc(o.created_at);
      return `<tr data-href="/pedidos.html?q=${encodeURIComponent(o.id)}" tabindex="0">
        <td><span class="cell-strong">#${esc(o.id)}</span><span class="cell-sub" title="${when ? esc(when.toLocaleString('es-EC')) : ''}">${esc(relTime(o.created_at))}</span></td>
        <td class="order-client">${esc(o.customer_name || o.customer_email || 'Sin nombre')}</td>
        <td><span class="badge st-${esc(st)}">${esc(meta.label)}</span></td>
        <td class="num cell-strong">${money(o.total)}</td>
        <td class="row-actions">${st === 'pending' ? `<button type="button" class="button small" data-pay="${esc(o.id)}" data-total="${esc(o.total)}" title="Marcar como pago confirmado"><i data-lucide="badge-check" aria-hidden="true"></i><span class="sr-only">Marcar pagado el pedido ${esc(o.id)}</span></button>` : ''}</td></tr>`;
    }).join('');
  };

  const renderStatus = d => {
    const rows = [...(d.by_status || [])].sort((a, b) => (STATUS_ORDER.indexOf(a.status) + 99) % 99 - (STATUS_ORDER.indexOf(b.status) + 99) % 99);
    $('#status').innerHTML = SmartCharts.stack(rows.map(r => ({ label: STATUS[r.status]?.label || r.status, value: r.orders, color: STATUS[r.status]?.color || 'var(--series-muted)', extra: money(r.value), href: `/pedidos.html?status=${encodeURIComponent(r.status)}` })),
      { format: number, label: 'Pedidos por estado', emptyText: 'No se crearon pedidos en este periodo.' });
  };

  const renderBuyers = d => {
    const b = d.buyers;
    if (!b) { $('#buyers').innerHTML = stateHtml('error', 'No se pudo calcular'); return; }
    const total = b.new_buyers + b.returning_buyers;
    const revTotal = b.new_value + b.returning_value;
    const share = revTotal ? Math.round((b.returning_value / revTotal) * 100) : 0;
    $('#buyers').innerHTML = SmartCharts.stack([
      { label: 'Nuevos', value: b.new_buyers, color: 'var(--series-3)', extra: `${money(b.new_value)} en pedidos` },
      { label: 'Recurrentes', value: b.returning_buyers, color: 'var(--series-1)', extra: `${money(b.returning_value)} en pedidos` },
    ], { format: number, label: 'Compradores nuevos y recurrentes', emptyText: 'Sin compradores en este periodo.' }) +
      (total ? `<p class="insight"><i data-lucide="repeat" aria-hidden="true"></i><span>Los recurrentes aportan <strong>${share}%</strong> del valor de pedidos${b.returning_revenue ? ` y ${money(b.returning_revenue)} ya cobrados` : ''}.</span></p>` : '');
  };

  const renderOps = d => {
    const t = d.totals, tm = d.timings;
    const sq = t.service_quotes_by_status || {};
    $('#ops').innerHTML = `<dl class="mini-stats">
      <div><dt>Conversión cotización → pedido</dt><dd>${pctText(t.quote_conversion)}</dd><small>${t.cart_quotes ? `${number(t.cart_quotes_converted)} de ${plural(t.cart_quotes, 'cotización de carrito', 'cotizaciones de carrito')}` : 'Sin cotizaciones de carrito'}</small></div>
      <div><dt>Solicitudes de servicios</dt><dd>${number(t.service_quotes)}</dd><small>${number(sq.nuevo || 0)} nuevas · ${number(sq.contactado || 0)} contactadas · ${number(sq.cerrado || 0)} cerradas</small></div>
      <div><dt>Del pedido al pago</dt><dd>${tm ? hours(tm.hours_to_paid) : '—'}</dd><small>${tm && tm.paid_samples ? `Promedio de ${plural(tm.paid_samples, 'pedido', 'pedidos')}` : 'Sin pagos registrados en el historial'}</small></div>
      <div><dt>Del pago al envío</dt><dd>${tm ? hours(tm.hours_paid_to_shipped) : '—'}</dd><small>${tm && tm.shipped_samples ? `Promedio de ${plural(tm.shipped_samples, 'pedido', 'pedidos')}` : 'Sin envíos registrados en el historial'}</small></div>
    </dl>`;
  };

  const renderTop = d => {
    const byRevenue = state.top === 'revenue';
    const list = (byRevenue ? d.top_products_revenue : d.top_products) || [];
    const rows = list.slice(0, 8).map(p => ({ label: p.name, value: byRevenue ? p.revenue : p.units, valueText: byRevenue ? money(p.revenue) : `${number(p.units)} u.`, extra: byRevenue ? `${number(p.units)} u.` : plural(p.orders, 'pedido', 'pedidos'),
      href: p.product_id ? `/editor-catalogo.html?tab=tab-products&q=${encodeURIComponent(p.name)}` : '' }));
    $('#top').innerHTML = SmartCharts.bars(rows, 'Todavía no hay ventas en este periodo.', { ranked: true });
  };

  const renderCategories = d => {
    const byRevenue = state.cat === 'revenue';
    const list = [...(d.categories || [])].sort((a, b) => byRevenue ? b.revenue - a.revenue : b.units - a.units).slice(0, 8);
    $('#categories').innerHTML = SmartCharts.bars(list.map(c => ({ label: c.category, value: byRevenue ? c.revenue : c.units, valueText: byRevenue ? money(c.revenue) : `${number(c.units)} u.`, extra: plural(c.products, 'producto', 'productos') })),
      'Todavía no hay ventas en este periodo.', { color: 'var(--series-3)' });
  };

  const renderHeatmap = d => {
    if (!d.heatmap) { $('#heatmap').innerHTML = stateHtml('error', 'No se pudo calcular'); return; }
    SmartCharts.heatmap($('#heatmap'), d.heatmap.orders, { format: v => plural(v, 'pedido', 'pedidos'), label: 'Pedidos por día de la semana y hora' });
    let best = null;
    d.heatmap.orders.forEach((row, wd) => row.forEach((v, h) => { if (v && (!best || v > best.v)) best = { v, wd, h }; }));
    const days = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
    const byDay = d.heatmap.orders.map(r => r.reduce((a, b) => a + b, 0));
    const topDay = byDay.indexOf(Math.max(...byDay));
    $('#heatHint').textContent = best ? `Más pedidos los ${days[topDay]}; la franja más activa es ${days[best.wd]} de ${best.h}:00 a ${best.h + 1}:00.` : 'Pedidos por día de la semana y hora (Ecuador).';
  };

  const renderCities = d => {
    $('#cities').innerHTML = SmartCharts.bars((d.cities || []).slice(0, 8).map(c => ({ label: c.city, value: c.value, valueText: money(c.value), extra: plural(c.orders, 'pedido', 'pedidos') })),
      'Sin pedidos con ciudad de envío en este periodo.', { color: 'var(--series-7)' });
  };

  const renderInventory = d => {
    const i = d.inventory;
    if (!i || !i.tracked) {
      $('#inventory').innerHTML = stateHtml('empty', 'Sin stock controlado', 'Todos los productos se venden sin límite. Carga el stock en Inventario para que los pedidos lo aparten.', 'boxes') + '<p style="text-align:center;margin:0"><a class="button small" href="/inventario.html">Ir a Inventario</a></p>';
      return;
    }
    $('#inventory').innerHTML = `<div class="metric-grid">
        <div class="metric"><strong>${money(i.stock_value)}</strong><span>Valor a precio de venta</span></div>
        <a class="metric" href="/inventario.html?filter=tracked"><strong>${number(i.free_units)}</strong><span>Unidades libres · ${number(i.reserved_units)} apartadas</span></a>
        <a class="metric ${i.out_of_stock ? 'bad' : ''}" href="/inventario.html?filter=out"><strong>${number(i.out_of_stock)}</strong><span>Agotados</span></a>
        <a class="metric ${i.low_stock ? 'warn' : ''}" href="/inventario.html?filter=low"><strong>${number(i.low_stock)}</strong><span>Stock bajo (≤ ${i.low_threshold})</span></a>
      </div>` + (i.low_items.length ? `<ul class="mini-list">${i.low_items.map(p => `<li><span><strong>${esc(p.name)}</strong><small>${p.sku ? esc(p.sku) + ' · ' : ''}${p.free <= 0 ? 'Agotado' : `${plural(p.free, 'libre', 'libres')}`}${p.reserved ? ` · ${number(p.reserved)} apartadas` : ''}</small></span>
        <a class="button small" href="/inventario.html?q=${encodeURIComponent(p.sku || p.name)}"><i data-lucide="package-plus" aria-hidden="true"></i> Reponer</a></li>`).join('')}</ul>` : '<p class="muted">Ningún producto con stock bajo.</p>');
  };

  const renderIdle = d => {
    const s = d.dead_stock;
    if (!s) { $('#idle').innerHTML = stateHtml('error', 'No se pudo calcular'); return; }
    $('#idle').innerHTML = `<div class="idle-buckets">${[30, 60, 90].map(n => `<div><strong>${number(s['days_' + n])}</strong><span>${n} días</span></div>`).join('')}</div>
      <p class="muted goal-note">De ${plural(s.visible_products, 'producto visible', 'productos visibles')}.${s.idle_stock_value ? ` Stock inmovilizado (90 días): <strong>${money(s.idle_stock_value)}</strong>.` : ''}</p>` +
      (s.items.length ? `<ul class="mini-list">${s.items.slice(0, 5).map(p => `<li><span><strong>${esc(p.name)}</strong><small>${p.price ? money(p.price) : 'Sin precio'}${p.stock != null ? ` · ${plural(p.stock, 'unidad', 'unidades')}` : ''}</small></span><a class="button small ghost" href="/editor-catalogo.html?tab=tab-products&q=${encodeURIComponent(p.name)}" aria-label="Abrir ${esc(p.name)} en el catálogo"><i data-lucide="arrow-right" aria-hidden="true"></i></a></li>`).join('')}</ul>` : '');
  };

  const renderCustomers = d => {
    const list = d.top_customers || [];
    $('#customers').innerHTML = list.length ? `<ol class="mini-list ranked">${list.slice(0, 6).map((c, i) => `<li><span class="rank">${i + 1}</span><span><strong>${esc(c.name || c.email || 'Cliente')}</strong><small>${plural(c.orders, 'pedido', 'pedidos')} · último ${esc(relTime(c.last_order))}</small></span><b>${money(c.value)}</b></li>`).join('')}</ol>`
      : stateHtml('empty', 'Sin compradores en este periodo', '', 'users');
  };

  const renderPeriod = d => {
    const range = d.from === d.to ? dayLabel(d.from, { day: 'numeric', month: 'long' }) : `${shortDay(d.from)} – ${shortDay(d.to)}`;
    const prev = d.previous_from === d.previous_to ? dayLabel(d.previous_from, { day: 'numeric', month: 'long' }) : `${shortDay(d.previous_from)} – ${shortDay(d.previous_to)}`;
    $('#periodText').textContent = `Resumen de ${PERIOD_TEXT[d.period] || 'el periodo'} (${range}).`;
    $('#compareText').innerHTML = `<i data-lucide="git-compare" aria-hidden="true"></i> Comparado con ${esc(prev)}`;
    const gen = new Date(d.generated_at);
    $('#generated').textContent = `Actualizado a las ${gen.toLocaleTimeString('es-EC', { hour: '2-digit', minute: '2-digit' })}. Días y horas de Ecuador.`;
  };

  const renderAll = () => {
    const d = state.data;
    renderPeriod(d); renderKpis(d); renderGoal(d); renderCash(d); renderAttention(d); renderRecent(d);
    renderStatus(d); renderBuyers(d); renderOps(d); renderTop(d); renderCategories(d); renderHeatmap(d);
    renderCities(d); renderInventory(d); renderIdle(d); renderCustomers(d);
    icons();
    renderTrend();
    icons();
  };

  const query = () => {
    const q = new URLSearchParams({ period: state.period });
    if (state.period === 'custom') { q.set('from', state.from); q.set('to', state.to); }
    return q.toString();
  };

  const load = async () => {
    const seq = ++state.seq;
    const button = $('#refresh');
    button.disabled = true; button.classList.add('is-busy');
    if (state.data) $('#dashboard').classList.add('is-refreshing'); else skeleton();
    try {
      const data = await api(`admin-insights?${query()}`);
      if (seq !== state.seq) return;
      state.data = data;
      renderAll();
    } catch (error) {
      if (seq !== state.seq) return;
      if (!state.data) {
        $('#kpis').innerHTML = `<div class="kpi kpi-error">${stateHtml('error', 'No se pudo cargar el resumen', error.message)}<button class="button small" type="button" data-retry>Reintentar</button></div>`;
        icons();
      }
      toast('No se pudo cargar el resumen: ' + error.message, true);
    } finally {
      if (seq === state.seq) { $('#dashboard').classList.remove('is-refreshing'); button.disabled = false; button.classList.remove('is-busy'); }
    }
  };

  // Periodo
  syncSegmented($('#period'), 'period', state.period);
  const today = new Date(Date.now() - 5 * 3600 * 1000).toISOString().slice(0, 10);
  $('#rangeTo').max = today; $('#rangeFrom').max = today;
  if (state.period === 'custom') { $('#range').hidden = false; $('#rangeFrom').value = state.from; $('#rangeTo').value = state.to; }
  $('#period').addEventListener('click', event => {
    const b = event.target.closest('[data-period]');
    if (!b) return;
    if (b.dataset.period === 'custom') {
      $('#range').hidden = false;
      syncSegmented($('#period'), 'period', 'custom');
      if (!$('#rangeFrom').value) { $('#rangeTo').value = today; $('#rangeFrom').value = new Date(Date.parse(today) - 13 * 86400000).toISOString().slice(0, 10); }
      $('#rangeFrom').focus();
      return;
    }
    $('#range').hidden = true;
    state.period = b.dataset.period;
    syncSegmented($('#period'), 'period', state.period);
    persist(); load();
  });
  $('#range').addEventListener('submit', event => {
    event.preventDefault();
    const from = $('#rangeFrom').value, to = $('#rangeTo').value;
    if (!from || !to || from > to) { toast('Elige un rango válido: la fecha inicial no puede ser posterior a la final.', true); return; }
    Object.assign(state, { period: 'custom', from, to });
    persist(); load();
  });

  $('#refresh').addEventListener('click', () => { load(); window.SmartNav?.refreshAlerts(); });
  $('#comparePrev').addEventListener('change', e => { state.compare = e.target.checked; persist(); renderTrend(); });
  $('#topMetric').addEventListener('click', e => { const b = e.target.closest('[data-metric]'); if (!b || !state.data) return; state.top = b.dataset.metric; syncSegmented($('#topMetric'), 'metric', state.top); persist(); renderTop(state.data); });
  $('#catMetric').addEventListener('click', e => { const b = e.target.closest('[data-metric]'); if (!b || !state.data) return; state.cat = b.dataset.metric; syncSegmented($('#catMetric'), 'metric', state.cat); persist(); renderCategories(state.data); });
  syncSegmented($('#topMetric'), 'metric', state.top);
  syncSegmented($('#catMetric'), 'metric', state.cat);
  $('#kpis').addEventListener('click', e => { if (e.target.closest('[data-retry]')) load(); });

  // Acciones rápidas
  document.addEventListener('click', async event => {
    const pay = event.target.closest('[data-pay]');
    if (!pay) return;
    event.preventDefault(); event.stopPropagation();
    const id = pay.dataset.pay;
    const ok = await confirm('Confirmar pago', `¿Marcar el pedido #${id} (${money(pay.dataset.total)}) como pago confirmado? Se registrará en su historial y se avisará al cliente por correo.`, 'Marcar pagado');
    if (!ok) return;
    pay.disabled = true;
    try {
      await post('admin-order-status', { id, status: 'paid' });
      toast(`Pedido #${id} marcado como pagado.`);
      window.SmartNav?.refreshAlerts();
      load();
    } catch (error) {
      pay.disabled = false;
      toast(error.message, true);
    }
  }, true);
  $('#recent').addEventListener('click', event => {
    if (event.target.closest('button, a')) return;
    const row = event.target.closest('tr[data-href]');
    if (row) window.location.assign(row.dataset.href);
  });
  $('#recent').addEventListener('keydown', event => {
    if (event.target.closest('button, a')) return;
    const row = event.target.closest('tr[data-href]');
    if (row && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); window.location.assign(row.dataset.href); }
  });

  $('#editGoal').addEventListener('click', () => {
    const current = state.data?.goal?.target || '';
    const suggestion = state.data?.goal?.previous_month_revenue ? Math.ceil(state.data.goal.previous_month_revenue * 1.1 / 100) * 100 : '';
    goalDialog = modal({
      title: 'Meta de ventas del mes',
      subtitle: 'Se compara con las ventas confirmadas del mes en curso. Usa 0 para quitarla.',
      body: `<form id="goalForm" class="form-row"><label for="goalInput">Meta mensual (USD)</label><input class="input" id="goalInput" type="number" min="0" step="50" inputmode="decimal" value="${esc(current)}" autofocus>
        ${suggestion ? `<span class="hint">Sugerencia: ${money(suggestion)} (mes anterior + 10%).</span>` : ''}</form>`,
      actions: [{ label: 'Cancelar', value: false }, { label: 'Guardar meta', kind: 'primary', onClick: () => { saveGoal(); return false; } }],
    });
  });
  let goalDialog = null;
  async function saveGoal() {
    if (!goalDialog) return;
    const value = Number(goalDialog.el.querySelector('#goalInput').value);
    if (!Number.isFinite(value) || value < 0) { toast('Escribe un monto válido.', true); return; }
    try {
      await post('admin-insights-goal', { goal: value });
      goalDialog.close(true);
      goalDialog = null;
      toast(value ? `Meta guardada: ${money(value)}.` : 'Meta eliminada.');
      load();
    } catch (error) { toast(error.message, true); }
  }
  document.addEventListener('submit', e => { if (e.target.id === 'goalForm') { e.preventDefault(); saveGoal(); } });
  document.addEventListener('smartisp:theme', renderTrend);
  SmartCharts.onResize(renderTrend);

  await load();
}));
