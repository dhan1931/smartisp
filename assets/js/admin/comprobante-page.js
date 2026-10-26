// Comprobante imprimible de un pedido (comprobante.html?id=PED-XXXXXX). El PDF lo genera el
// navegador desde el diálogo de impresión.
document.addEventListener('DOMContentLoaded', () => SmartAdmin.boot(async () => {
  const { $, esc, money, api, icons, state: stateHtml, utc } = SmartAdmin;
  const STATUS_LABELS = { quote_requested: 'Cotización solicitada', pending: 'Pendiente de pago', paid: 'Pago confirmado', preparing: 'Preparando', shipped: 'Enviado / Retiro', delivered: 'Entregado', cancelled: 'Cancelado', received: 'Recibido' };
  const PAYMENT_LABELS = { paid: 'Cobrado', confirmed: 'Cobrado', pending: 'Pendiente de cobro', quote_pending: 'Cotización (sin cobro)', failed: 'Fallido', refunded: 'Reembolsado' };
  const IVA_RATE = 0.15;
  const ecTime = value => {
    const d = utc(value);
    return d ? d.toLocaleString('es-EC', { timeZone: 'America/Guayaquil', day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
  };
  const orderItems = o => Array.isArray(o.items?.items) ? o.items.items : (Array.isArray(o.items) ? o.items : []);

  const render = o => {
    const shipping = o.shipping || {};
    const items = orderItems(o);
    const total = Number(o.total || 0);
    // Los precios de la tienda ya incluyen IVA (mismo cálculo que el correo de comprobante).
    const base = total > 0 ? Math.round((total / (1 + IVA_RATE)) * 100) / 100 : 0;
    const iva = total > 0 ? Math.round((total - base) * 100) / 100 : 0;
    const isQuote = o.status === 'quote_requested' || total <= 0;
    const status = String(o.status || '').toLowerCase().replace(/[^a-z_]/g, '');
    document.title = `${isQuote ? 'Cotización' : 'Comprobante'} ${o.id} | SmartISP`;
    $('#receipt').innerHTML = `
      <header class="receipt-head">
        <img src="/assets/img/logo.png" alt="SmartISP" class="receipt-logo">
        <div class="receipt-title">
          <h1>${isQuote ? 'Cotización' : 'Comprobante de pedido'}</h1>
          <p class="receipt-id">N.º ${esc(o.id)}</p>
          <p>${esc(ecTime(o.created_at))}</p>
          <span class="badge st-${esc(status)}">${esc(STATUS_LABELS[o.status] || o.status || 'Sin estado')}</span>
        </div>
      </header>
      <section class="receipt-parties">
        <div>
          <h2>Cliente</h2>
          <p><strong>${esc(o.customer_name || shipping.name || '—')}</strong></p>
          ${o.customer_email || shipping.email ? `<p>${esc(o.customer_email || shipping.email)}</p>` : ''}
          ${o.customer_phone || shipping.phone ? `<p>${esc(o.customer_phone || shipping.phone)}</p>` : ''}
        </div>
        <div>
          <h2>Entrega</h2>
          <p>${esc([shipping.address, shipping.city].filter(Boolean).join(', ') || 'A coordinar')}</p>
          ${shipping.notes ? `<p class="muted">${esc(shipping.notes)}</p>` : ''}
        </div>
      </section>
      <table class="receipt-items">
        <thead><tr><th>Descripción</th><th class="num">Cant.</th><th class="num">P. unitario</th><th class="num">Subtotal</th></tr></thead>
        <tbody>${items.length ? items.map(it => {
          const qty = Number(it.quantity || 1), price = Number(it.price || 0);
          return `<tr><td>${esc(it.name || 'Producto')}${it.sku ? `<small>SKU ${esc(it.sku)}</small>` : ''}</td><td class="num">${qty}</td><td class="num">${price > 0 ? money(price) : 'A cotizar'}</td><td class="num">${price > 0 ? money(price * qty) : '—'}</td></tr>`;
        }).join('') : '<tr><td colspan="4" class="muted">Sin ítems registrados.</td></tr>'}</tbody>
      </table>
      <dl class="receipt-totals">
        <div><dt>Subtotal (sin IVA)</dt><dd>${total > 0 ? money(base) : 'A coordinar'}</dd></div>
        <div><dt>IVA (15%)</dt><dd>${money(iva)}</dd></div>
        <div class="grand"><dt>Total (IVA incl.)</dt><dd>${money(total)}</dd></div>
      </dl>
      <p class="receipt-payment">Pago: ${esc(PAYMENT_LABELS[o.payment_status] || o.payment_status || 'Pendiente')}${o.payment_provider ? ' · ' + esc(o.payment_provider) : ''}</p>
      <footer class="receipt-foot">
        <p>SmartISP Ecuador · smart-isp.com.ec</p>
        <p>Documento informativo: no reemplaza a la factura electrónica autorizada por el SRI.</p>
      </footer>`;
    $('#printBtn').disabled = false;
  };

  const id = new URLSearchParams(location.search).get('id') || '';
  $('#printBtn').addEventListener('click', () => window.print());
  if (!id) {
    $('#receipt').innerHTML = stateHtml('error', 'Falta el número de pedido', 'Abre el comprobante desde el detalle de un pedido.');
    icons();
    return;
  }
  try {
    const res = await api(`admin-order?id=${encodeURIComponent(id)}`);
    render(res.order);
  } catch (e) {
    $('#receipt').innerHTML = stateHtml('error', 'No se pudo cargar el pedido', e.message);
  }
  icons();
}));
