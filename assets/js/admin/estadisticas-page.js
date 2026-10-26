// Estadísticas (estadisticas.html): admin-insights?scope=full en una sola petición.
document.addEventListener('DOMContentLoaded', () => SmartAdmin.boot(async () => {
  const { $, esc, money, compact, number, api, icons, toast, delta, relTime, remembered, remember, csv, download, state: stateHtml } = SmartAdmin;

  const STATUS = {
    quote_requested: ['Cotización', 'var(--series-7)'], pending: ['Pendiente de pago', 'var(--series-4)'], paid: ['Pago confirmado', 'var(--series-1)'],
    preparing: ['Preparando', 'var(--series-3)'], shipped: ['Enviado', 'var(--series-5)'], delivered: ['Entregado', 'var(--series-6)'],
    received: ['Recibido', 'var(--series-2)'], cancelled: ['Cancelado', 'var(--series-8)'], canceled: ['Cancelado', 'var(--series-8)'], refunded: ['Reembolsado', 'var(--series-8)'],
  };
  const ORDER = Object.keys(STATUS);
  const DAYS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
  const plural = (n, one, many) => `${number(n)} ${Number(n) === 1 ? one : many}`;
  const dayLabel = (iso, opts) => new Date(iso + 'T12:00:00').toLocaleDateString('es-EC', opts);
  const shortDay = iso => dayLabel(iso, { day: 'numeric', month: 'short' });
  const monthLabel = ym => new Date(ym + '-15T12:00:00').toLocaleDateString('es-EC', { month: 'short', year: '2-digit' });
  const pctText = v => v == null ? '—' : `${String(v).replace('.', ',')}%`;
  const share = (v, total) => total ? `${String(Math.round((v / total) * 1000) / 10).replace('.', ',')}%` : '—';
  const hours = h => h == null ? '—' : h < 1 ? `${Math.round(h * 60)} min` : h < 48 ? `${String(h).replace('.', ',')} h` : `${String(Math.round(h / 24 * 10) / 10).replace('.', ',')} días`;
  const todayIso = new Date(Date.now() - 5 * 3600 * 1000).toISOString().slice(0, 10);
  const shift = (iso, days) => new Date(Date.parse(iso + 'T12:00:00Z') + days * 86400000).toISOString().slice(0, 10);

  const saved = remembered('estadisticas', {});
  const st = { period: saved.period || '30d', from: saved.from || '', to: saved.to || '', compare: saved.compare !== false, tab: saved.tab || 'ventas', data: null, seq: 0 };
  const persist = () => remember('estadisticas', { period: st.period, from: st.from, to: st.to, compare: st.compare, tab: st.tab });
  const sync = (box, attr, value) => box.querySelectorAll('button').forEach(b => { const on = b.dataset[attr] === value; b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', String(on)); });

  const params = () => {
    const q = new URLSearchParams({ scope: 'full' });
    if (st.period === '365') { q.set('period', 'custom'); q.set('from', shift(todayIso, -364)); q.set('to', todayIso); }
    else if (st.period === 'custom') { q.set('period', 'custom'); q.set('from', st.from); q.set('to', st.to); }
    else q.set('period', st.period);
    return q;
  };

  const skeleton = () => {
    $('#kpis').innerHTML = Array.from({ length: 8 }, () => '<div class="kpi" aria-hidden="true"><span class="sk sk-line" style="width:55%"></span><span class="sk sk-title"></span><span class="sk sk-line" style="width:40%"></span></div>').join('');
    ['#trendChart', '#monthlyChart', '#heatmap'].forEach(s => { $(s).innerHTML = '<span class="sk sk-block" style="height:260px"></span>'; });
    ['#weekdays', '#cash', '#status', '#buyers', '#idle', '#inventory', '#ops', '#margin'].forEach(s => { $(s).innerHTML = '<span class="sk sk-line"></span><span class="sk sk-line" style="width:70%"></span>'; });
    ['#cities', '#customers', '#products', '#categories', '#cohorts', '#marginRows'].forEach(s => { $(s).innerHTML = SmartAdmin.skeletonRows(4, 4); });
  };

  const renderKpis = d => {
    const t = d.totals, p = d.previous, b = d.buyers || {};
    const recValue = (b.new_value || 0) + (b.returning_value || 0);
    const tiles = [
      ['banknote', 'Ventas confirmadas', money(t.revenue), delta(t.revenue, p.revenue), plural(t.paid_orders, 'pedido cobrado', 'pedidos cobrados')],
      ['hourglass', 'Pendiente de cobro', money(t.pending_value), delta(t.pending_value, p.pending_value, { invert: true }), 'Pedidos sin pago confirmado'],
      ['shopping-cart', 'Pedidos', number(t.orders), delta(t.orders, p.orders), `${money(t.pipeline)} en valor`],
      ['receipt', 'Ticket promedio', t.paid_orders ? money(t.avg_ticket) : '—', t.paid_orders ? delta(t.avg_ticket, p.avg_ticket) : '', 'Ventas ÷ pedidos cobrados'],
      ['circle-x', 'Cancelación', pctText(t.cancel_rate), delta(t.cancel_rate, p.cancel_rate, { invert: true }), plural(t.void_orders, 'anulado', 'anulados')],
      ['file-check', 'Conversión de cotizaciones', pctText(t.quote_conversion), t.quote_conversion != null && p.quote_conversion != null ? delta(t.quote_conversion, p.quote_conversion) : '', t.cart_quotes ? `${number(t.cart_quotes_converted)} de ${number(t.cart_quotes)}` : 'Sin cotizaciones'],
      ['user-plus', 'Clientes nuevos', number(t.new_customers), delta(t.new_customers, p.new_customers), 'Cuentas registradas'],
      ['repeat', 'Valor de recurrentes', recValue ? share(b.returning_value, recValue) : '—', '', `${plural(b.returning_buyers || 0, 'comprador', 'compradores')} que repiten`],
    ];
    $('#kpis').innerHTML = tiles.map(([icon, label, value, dl, foot]) => `<div class="kpi"><span class="kpi-label"><i data-lucide="${icon}" aria-hidden="true"></i>${label}</span><strong class="kpi-value">${value}</strong><span class="kpi-foot">${dl}<span>${esc(foot)}</span></span></div>`).join('');
  };

  const renderTrend = () => {
    const d = st.data;
    if (!d) return;
    const hourly = d.granularity === 'hour';
    const points = d.series.map((x, i) => ({ ...x, prev_revenue: (d.previous_series[i] || {}).revenue || 0,
      label: hourly ? x.key.slice(0, 2) + 'h' : shortDay(x.key), title: hourly ? x.key : dayLabel(x.key, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) }));
    $('#trendHint').textContent = `${hourly ? 'Por hora' : 'Por día'} · ${money(d.totals.revenue)} confirmados, ${money(d.totals.pending_value)} por cobrar.`;
    $('#trendLegend').innerHTML = '<li><i class="sw line" style="background:var(--series-1)"></i>Ventas confirmadas</li><li><i class="sw" style="background:var(--series-2)"></i>Pedidos</li>' + (st.compare ? '<li><i class="sw dash"></i>Periodo anterior</li>' : '');
    SmartCharts.combo($('#trendChart'), points, {
      label: 'Ventas confirmadas y pedidos del periodo', title: p => p.title, emptyText: 'Sin pedidos en este periodo.',
      panels: [
        { key: 'revenue', type: 'line', label: 'Ventas confirmadas', format: money, axis: v => '$' + compact(v), color: 'var(--series-1)', prevKey: st.compare ? 'prev_revenue' : null, height: 180 },
        { key: 'orders', type: 'bars', label: 'Pedidos', format: number, axis: compact, color: 'var(--series-2)', height: 90 },
      ],
      detail: p => p.pending_value ? `${money(p.pending_value)} por cobrar` : '',
    });
    $('#trendTable').innerHTML = `<table><thead><tr><th>${hourly ? 'Hora' : 'Día'}</th><th class="num">Confirmado</th><th class="num">Por cobrar</th><th class="num">Pedidos</th><th class="num">Anulados</th><th class="num">Clientes nuevos</th></tr></thead><tbody>${points.map(p => `<tr><td>${esc(p.title)}</td><td class="num">${money(p.revenue)}</td><td class="num">${money(p.pending_value)}</td><td class="num">${number(p.orders)}</td><td class="num">${number(p.void_orders)}</td><td class="num">${number(p.new_customers)}</td></tr>`).join('')}</tbody></table>`;
    const monthly = (d.monthly || []).map(m => ({ ...m, label: monthLabel(m.month), title: new Date(m.month + '-15T12:00:00').toLocaleDateString('es-EC', { month: 'long', year: 'numeric' }) }));
    SmartCharts.combo($('#monthlyChart'), monthly, {
      label: 'Ventas confirmadas y pedidos por mes, últimos 12 meses', title: p => p.title, emptyText: 'Sin pedidos en los últimos 12 meses.',
      panels: [
        { key: 'revenue', type: 'line', label: 'Ventas confirmadas', format: money, axis: v => '$' + compact(v), color: 'var(--series-1)', height: 150 },
        { key: 'orders', type: 'bars', label: 'Pedidos', format: number, axis: compact, color: 'var(--series-2)', height: 80 },
      ],
      detail: p => p.pending_value ? `${money(p.pending_value)} por cobrar` : '',
    });
  };

  const renderVentas = d => {
    if (d.heatmap) {
      SmartCharts.heatmap($('#heatmap'), d.heatmap.orders, { format: v => plural(v, 'pedido', 'pedidos') });
      const byDay = d.heatmap.value.map(r => r.reduce((a, b) => a + b, 0));
      const counts = d.heatmap.orders.map(r => r.reduce((a, b) => a + b, 0));
      $('#weekdays').innerHTML = SmartCharts.bars(DAYS.map((name, i) => ({ label: name, value: byDay[i], valueText: money(byDay[i]), extra: plural(counts[i], 'pedido', 'pedidos') })), 'Sin pedidos en este periodo.', { color: 'var(--series-3)' });
    } else { $('#heatmap').innerHTML = stateHtml('error', 'No se pudo calcular'); $('#weekdays').innerHTML = ''; }
    const t = d.totals;
    $('#cash').innerHTML = SmartCharts.stack([
      { label: 'Confirmado', value: t.revenue, color: 'var(--series-1)', extra: plural(t.paid_orders, 'pedido', 'pedidos') },
      { label: 'Pendiente de cobro', value: t.pending_value, color: 'var(--series-4)', href: '/pedidos.html?status=pending' },
    ], { format: money, emptyText: 'Sin pedidos en este periodo.' });
    const rows = [...(d.by_status || [])].sort((a, b) => (ORDER.indexOf(a.status) + 99) % 99 - (ORDER.indexOf(b.status) + 99) % 99);
    $('#status').innerHTML = SmartCharts.stack(rows.map(r => ({ label: (STATUS[r.status] || [r.status])[0], value: r.orders, color: (STATUS[r.status] || [0, 'var(--series-muted)'])[1], extra: money(r.value), href: `/pedidos.html?status=${encodeURIComponent(r.status)}` })), { format: number, emptyText: 'Sin pedidos en este periodo.' });
  };

  const renderClientes = d => {
    const b = d.buyers;
    $('#buyers').innerHTML = b ? SmartCharts.stack([
      { label: 'Nuevos', value: b.new_buyers, color: 'var(--series-3)', extra: `${money(b.new_value)} en pedidos · ${money(b.new_revenue)} cobrados` },
      { label: 'Recurrentes', value: b.returning_buyers, color: 'var(--series-1)', extra: `${money(b.returning_value)} en pedidos · ${money(b.returning_revenue)} cobrados` },
    ], { format: number, emptyText: 'Sin compradores en este periodo.' }) : stateHtml('error', 'No se pudo calcular');
    const cities = d.cities || [];
    const cityTotal = cities.reduce((s, c) => s + c.value, 0);
    $('#cities').innerHTML = cities.length ? cities.map(c => `<tr><td class="cell-strong">${esc(c.city)}</td><td class="num">${number(c.orders)}</td><td class="num">${money(c.value)}</td><td class="num">${share(c.value, cityTotal)}</td></tr>`).join('')
      : `<tr><td colspan="4">${stateHtml('empty', 'Sin ciudades en este periodo', 'Los pedidos no traen ciudad de envío.', 'map-pin')}</td></tr>`;
    const customers = d.top_customers || [];
    $('#customers').innerHTML = customers.length ? customers.map(c => `<tr><td class="cell-strong">${esc(c.name || 'Sin nombre')}</td><td>${c.email ? `<a href="/clientes.html?q=${encodeURIComponent(c.email)}">${esc(c.email)}</a>` : '—'}</td><td class="num">${number(c.orders)}</td><td class="num">${money(c.value)}</td><td class="num">${money(c.revenue)}</td><td>${esc(relTime(c.last_order))}</td></tr>`).join('')
      : `<tr><td colspan="6">${stateHtml('empty', 'Sin compradores en este periodo', '', 'users')}</td></tr>`;
    const cohorts = d.cohorts || [];
    const maxK = Math.max(0, ...cohorts.map(c => c.returned.length));
    $('#cohortHead').innerHTML = `<tr><th>Primer pedido</th><th class="num">Clientes</th>${Array.from({ length: maxK }, (_, k) => `<th class="num">Mes +${k + 1}</th>`).join('')}</tr>`;
    $('#cohorts').innerHTML = cohorts.some(c => c.customers) ? cohorts.map(c => `<tr><td class="cell-strong">${esc(new Date(c.cohort + '-15T12:00:00').toLocaleDateString('es-EC', { month: 'long', year: 'numeric' }))}</td><td class="num">${number(c.customers)}</td>${Array.from({ length: maxK }, (_, k) => {
      if (k >= c.returned.length) return '<td aria-hidden="true"></td>';
      const n = c.returned[k];
      const rate = c.customers ? n / c.customers : 0;
      return `<td class="num cohort-cell" style="--i:${rate.toFixed(3)}" data-export="${n}" title="${esc(`${n} de ${c.customers} volvieron (${Math.round(rate * 100)}%)`)}">${c.customers ? Math.round(rate * 100) + '%' : '—'}</td>`;
    }).join('')}</tr>`).join('') : `<tr><td colspan="${2 + maxK}">${stateHtml('empty', 'Aún no hay compradores en los últimos 6 meses', '', 'users')}</td></tr>`;
  };

  const renderProductos = d => {
    const products = d.top_products_revenue || [];
    $('#products').innerHTML = products.length ? products.map(p => `<tr><td class="cell-strong">${esc(p.name)}</td><td class="num">${number(p.units)}</td><td class="num">${number(p.orders)}</td><td class="num">${money(p.revenue)}</td></tr>`).join('')
      : `<tr><td colspan="4">${stateHtml('empty', 'Sin ventas en este periodo', '', 'package')}</td></tr>`;
    const cats = d.categories || [];
    const catTotal = cats.reduce((s, c) => s + c.revenue, 0);
    $('#categories').innerHTML = cats.length ? cats.map(c => `<tr><td class="cell-strong">${esc(c.category)}</td><td class="num">${number(c.products)}</td><td class="num">${number(c.units)}</td><td class="num">${money(c.revenue)}</td><td class="num">${share(c.revenue, catTotal)}</td></tr>`).join('')
      : `<tr><td colspan="5">${stateHtml('empty', 'Sin ventas en este periodo', '', 'folder')}</td></tr>`;
    const s = d.dead_stock;
    $('#idle').innerHTML = s ? `<div class="idle-buckets">${[30, 60, 90].map(n => `<div><strong>${number(s['days_' + n])}</strong><span>${n} días</span></div>`).join('')}</div>
      <p class="muted goal-note">De ${plural(s.visible_products, 'producto visible', 'productos visibles')}.${s.idle_stock_value ? ` Stock inmovilizado (90 días): <strong>${money(s.idle_stock_value)}</strong>.` : ''}</p>
      ${s.items.length ? `<ul class="mini-list">${s.items.map(p => `<li><span><strong>${esc(p.name)}</strong><small>${p.price ? money(p.price) : 'Sin precio'}${p.stock != null ? ` · ${plural(p.stock, 'unidad', 'unidades')}` : ''}${p.stock_value ? ` · ${money(p.stock_value)} en stock` : ''}</small></span><a class="button small ghost" href="/editor-catalogo.html?tab=tab-products&q=${encodeURIComponent(p.name)}" aria-label="Abrir ${esc(p.name)}"><i data-lucide="arrow-right" aria-hidden="true"></i></a></li>`).join('')}</ul>` : ''}` : stateHtml('error', 'No se pudo calcular');
    const i = d.inventory;
    $('#inventory').innerHTML = i && i.tracked ? `<div class="metric-grid">
        <div class="metric"><strong>${money(i.stock_value)}</strong><span>Valor a precio de venta</span></div>
        <div class="metric"><strong>${number(i.units)}</strong><span>Unidades · ${number(i.reserved_units)} apartadas</span></div>
        <a class="metric ${i.out_of_stock ? 'bad' : ''}" href="/inventario.html?filter=out"><strong>${number(i.out_of_stock)}</strong><span>Agotados</span></a>
        <a class="metric ${i.low_stock ? 'warn' : ''}" href="/inventario.html?filter=low"><strong>${number(i.low_stock)}</strong><span>Stock bajo (≤ ${i.low_threshold})</span></a></div>` + `<p class="muted goal-note">${i.stock_cost_value == null ? 'Sin costos: aplica la migración 041 para ver el valor a costo.' : i.tracked_with_cost ? `A costo: <strong>${money(i.stock_cost_value)}</strong> (${number(i.tracked_with_cost)} de ${plural(i.tracked, 'producto', 'productos')} con costo; a precio de venta suman ${money(i.costed_stock_value)}).` : 'Carga costos en Productos para ver el valor del inventario a costo.'}</p>`
      : stateHtml('empty', 'Sin stock controlado', 'Carga el stock en Inventario para medirlo aquí.', 'boxes');
  };

  const renderMargen = d => {
    const m = d.margin;
    const tone = p => p < 0 ? 'bad' : p < 10 ? 'warn' : '';
    if (!m) { $('#margin').innerHTML = stateHtml('error', 'No se pudo calcular'); $('#marginRows').innerHTML = ''; return; }
    if (!m.available || !m.costed_products) {
      $('#margin').innerHTML = stateHtml('empty', 'Carga costos para ver tu margen', m.available ? 'Agrega el costo de compra en cada producto (o impórtalo con una columna «costo»).' : 'Falta aplicar la migración 041_products_cost.sql (php scripts/migrate.php).', 'calculator') + '<p style="text-align:center;margin:0"><a class="button small" href="/editor-catalogo.html?tab=tab-products">Ir a Productos</a></p>';
      $('#marginRows').innerHTML = `<tr><td colspan="6">${stateHtml('empty', 'Sin productos con costo', '', 'calculator')}</td></tr>`;
      return;
    }
    $('#margin').innerHTML = `<div class="metric-grid">
        <div class="metric ${m.gross_margin < 0 ? 'bad' : ''}"><strong>${money(m.gross_margin)}</strong><span>Margen bruto</span></div>
        <div class="metric ${tone(m.margin_pct)}"><strong>${pctText(m.margin_pct)}</strong><span>Margen sobre ventas</span></div>
        <div class="metric"><strong>${money(m.revenue)}</strong><span>Ventas con costo · ${pctText(m.coverage_pct)} del total</span></div>
        <div class="metric"><strong>${money(m.cost)}</strong><span>Costo de lo vendido</span></div></div>
      <p class="muted goal-note">${plural(m.products_with_cost, 'producto vendido', 'productos vendidos')} con costo de ${number(m.products_sold)} · ${plural(m.costed_products, 'producto', 'productos')} del catálogo con costo cargado.</p>`;
    $('#marginRows').innerHTML = m.lowest.length ? m.lowest.map(p => `<tr><td class="cell-strong">${esc(p.name)}</td><td class="num">${number(p.units)}</td><td class="num">${money(p.revenue)}</td><td class="num">${money(p.cost)}</td><td class="num">${money(p.margin)}</td><td class="num"><span class="margin-pct ${tone(p.margin_pct)}">${pctText(p.margin_pct)}</span></td></tr>`).join('')
      : `<tr><td colspan="6">${stateHtml('empty', 'Sin ventas de productos con costo en este periodo', '', 'calculator')}</td></tr>`;
  };

  const renderOperacion = d => {
    const t = d.totals, tm = d.timings, sq = t.service_quotes_by_status || {};
    $('#ops').innerHTML = `<dl class="mini-stats">
      <div><dt>Conversión cotización → pedido</dt><dd>${pctText(t.quote_conversion)}</dd><small>${t.cart_quotes ? `${number(t.cart_quotes_converted)} convertidas · ${number(t.cart_quotes_lost)} anuladas de ${number(t.cart_quotes)}` : 'Sin cotizaciones de carrito'}</small></div>
      <div><dt>Solicitudes de servicios</dt><dd>${number(t.service_quotes)}</dd><small>${number(sq.nuevo || 0)} nuevas · ${number(sq.contactado || 0)} contactadas · ${number(sq.cerrado || 0)} cerradas</small></div>
      <div><dt>Del pedido al pago</dt><dd>${tm ? hours(tm.hours_to_paid) : '—'}</dd><small>${tm && tm.paid_samples ? `Promedio de ${plural(tm.paid_samples, 'pedido', 'pedidos')}` : 'Sin pagos en el historial de estados'}</small></div>
      <div><dt>Del pago al envío</dt><dd>${tm ? hours(tm.hours_paid_to_shipped) : '—'}</dd><small>${tm && tm.shipped_samples ? `Promedio de ${plural(tm.shipped_samples, 'pedido', 'pedidos')}` : 'Sin envíos en el historial de estados'}</small></div>
      <div><dt>Pedidos anulados</dt><dd>${number(t.void_orders)}</dd><small>${pctText(t.cancel_rate)} de los pedidos creados</small></div>
      <div><dt>Clientes nuevos</dt><dd>${number(t.new_customers)}</dd><small>Cuentas registradas en el periodo</small></div>
    </dl>`;
  };

  const renderAll = () => {
    const d = st.data;
    const range = d.from === d.to ? dayLabel(d.from, { day: 'numeric', month: 'long', year: 'numeric' }) : `${shortDay(d.from)} – ${dayLabel(d.to, { day: 'numeric', month: 'short', year: 'numeric' })}`;
    $('#periodText').textContent = `${range} · ${plural(d.days, 'día', 'días')}. Días y horas de Ecuador.`;
    $('#compareText').innerHTML = `<i data-lucide="git-compare" aria-hidden="true"></i> Comparado con ${esc(shortDay(d.previous_from))} – ${esc(shortDay(d.previous_to))}`;
    renderKpis(d); renderVentas(d); renderClientes(d); renderProductos(d); renderMargen(d); renderOperacion(d);
    icons(); renderTrend(); icons();
  };

  const load = async () => {
    const seq = ++st.seq;
    if (st.data) $('#content').classList.add('is-refreshing'); else skeleton();
    try {
      const data = await api(`admin-insights?${params()}`);
      if (seq !== st.seq) return;
      st.data = data;
      renderAll();
    } catch (e) {
      if (seq !== st.seq) return;
      if (!st.data) { $('#kpis').innerHTML = `<div class="kpi kpi-error">${stateHtml('error', 'No se pudieron cargar las estadísticas', e.message)}</div>`; icons(); }
      toast(e.message, true);
    } finally { if (seq === st.seq) $('#content').classList.remove('is-refreshing'); }
  };

  // Pestañas accesibles (flechas izquierda/derecha).
  const tabs = [...document.querySelectorAll('#tabs [role="tab"]')];
  const selectTab = (name, focus) => {
    st.tab = name; persist();
    tabs.forEach(t => { const on = t.id === 'tab-' + name; t.setAttribute('aria-selected', String(on)); t.tabIndex = on ? 0 : -1; if (on && focus) t.focus(); });
    document.querySelectorAll('[role="tabpanel"]').forEach(p => { p.hidden = p.id !== 'panel-' + name; });
    if (name === 'ventas') renderTrend();
  };
  $('#tabs').addEventListener('click', e => { const t = e.target.closest('[role="tab"]'); if (t) selectTab(t.id.slice(4)); });
  $('#tabs').addEventListener('keydown', e => {
    const i = tabs.indexOf(document.activeElement);
    if (i < 0 || !['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(e.key)) return;
    e.preventDefault();
    const next = e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : (i + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
    selectTab(tabs[next].id.slice(4), true);
  });
  if (!tabs.some(t => t.id === 'tab-' + st.tab)) st.tab = 'ventas';
  selectTab(st.tab);

  sync($('#period'), 'period', st.period);
  $('#rangeFrom').max = todayIso; $('#rangeTo').max = todayIso;
  if (st.period === 'custom') { $('#range').hidden = false; $('#rangeFrom').value = st.from; $('#rangeTo').value = st.to; }
  $('#period').addEventListener('click', e => {
    const b = e.target.closest('[data-period]');
    if (!b) return;
    sync($('#period'), 'period', b.dataset.period);
    if (b.dataset.period === 'custom') {
      $('#range').hidden = false;
      if (!$('#rangeFrom').value) { $('#rangeTo').value = todayIso; $('#rangeFrom').value = shift(todayIso, -29); }
      $('#rangeFrom').focus();
      return;
    }
    $('#range').hidden = true;
    st.period = b.dataset.period; persist(); load();
  });
  $('#range').addEventListener('submit', e => {
    e.preventDefault();
    const from = $('#rangeFrom').value, to = $('#rangeTo').value;
    if (!from || !to || from > to) { toast('Elige un rango válido.', true); return; }
    Object.assign(st, { period: 'custom', from, to }); persist(); load();
  });
  $('#comparePrev').checked = st.compare;
  $('#comparePrev').addEventListener('change', e => { st.compare = e.target.checked; persist(); renderTrend(); });

  $('#exportPeriod').addEventListener('click', () => {
    const d = st.data;
    if (!d) return;
    const rows = d.series.map((x, i) => [x.key, x.revenue.toFixed(2), x.pending_value.toFixed(2), x.orders, x.paid_orders, x.void_orders, x.quotes, x.new_customers, ((d.previous_series[i] || {}).revenue || 0).toFixed(2)]);
    csv(`smartisp-estadisticas-${d.from}_a_${d.to}.csv`, [d.granularity === 'hour' ? 'Hora' : 'Día', 'Ventas confirmadas', 'Pendiente de cobro', 'Pedidos', 'Pedidos cobrados', 'Anulados', 'Cotizaciones', 'Clientes nuevos', 'Ventas periodo anterior'], rows);
    toast('CSV del periodo descargado.');
  });
  $('#exportOrders').addEventListener('click', async () => {
    const d = st.data;
    if (!d) return;
    const button = $('#exportOrders');
    button.disabled = true; button.classList.add('is-busy');
    try { await download(`admin-export?type=orders&from=${d.from}&to=${d.to}`); toast('Pedidos del periodo descargados.'); }
    catch (e) { toast(e.message, true); }
    finally { button.disabled = false; button.classList.remove('is-busy'); }
  });

  document.addEventListener('smartisp:theme', renderTrend);
  SmartCharts.onResize(renderTrend);
  await load();
}));
