/**
 * Carrito compartido para paginas publicas que no son tienda.html. Lee/escribe el mismo
 * localStorage('smartisp.cart') que assets/js/storefront-product-card.js (los botones
 * "Agregar" de las tarjetas de producto) -- aqui se agrega el drawer para verlo/editarlo,
 * que antes solo existia dentro de tienda.html. No se carga en tienda.html (tiene su propio
 * carrito mas integrado con wishlist/toast); si en el futuro se unifican, este archivo es el
 * punto de partida.
 *
 * Uso: <link rel="stylesheet" href="/assets/css/storefront-cart.css">
 *      <script src="/assets/js/storefront-cart.js" defer></script>
 * Requiere que exista un link/boton con [data-open-cart] en la pagina (ya lo trae
 * includes/storefront-discovery-nav.php via .storefront-cart).
 */
(function () {
  'use strict';

  const money = value => {
    const num = Number(value || 0);
    return num > 0 ? '$' + num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : 'Solicitar cotización';
  };

  const productKey = product => String(product?.id || product?.sku || product?.name || 'item');

  const FALLBACK_SVG = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 340" width="400" height="340">' +
    '<rect width="100%" height="100%" fill="#f1f5f9"/>' +
    '<rect x="20" y="20" width="360" height="300" rx="10" fill="#e2e8f0" stroke="#cbd5e1" stroke-width="2"/>' +
    '<text x="200" y="180" font-family="sans-serif" font-size="16" font-weight="700" fill="#475569" text-anchor="middle">SmartISP</text>' +
    '</svg>'
  );

  const escapeHtml = value => String(value == null ? '' : value).replace(/[&<>"']/g, ch => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]
  ));

  const readCart = () => {
    try {
      const value = JSON.parse(localStorage.getItem('smartisp.cart') || '[]');
      return Array.isArray(value) ? value.filter(item => item && typeof item === 'object') : [];
    } catch { return []; }
  };
  const writeCart = cart => {
    try { localStorage.setItem('smartisp.cart', JSON.stringify(cart)); } catch {}
  };

  let drawerEl = null;
  let backdropEl = null;

  const buildDrawer = () => {
    if (drawerEl) return;
    drawerEl = document.createElement('div');
    drawerEl.className = 'storefront-cart-drawer';
    drawerEl.innerHTML =
      '<div class="storefront-cart-panel">' +
      '<div class="storefront-cart-head"><h2>Tu carrito</h2><button type="button" class="storefront-cart-close" aria-label="Cerrar carrito"><i data-lucide="x"></i></button></div>' +
      '<div class="storefront-cart-items" id="storefrontCartItems">Tu carrito está vacío.</div>' +
      '<div class="storefront-cart-footer"><div class="storefront-cart-total"><span>Total</span><span id="storefrontCartTotal">$0</span></div>' +
      '<button type="button" class="storefront-cart-checkout">Finalizar compra <i data-lucide="arrow-up-right" width="16"></i></button></div>' +
      '</div>';
    document.body.appendChild(drawerEl);
    drawerEl.addEventListener('click', event => { if (event.target === drawerEl) setOpen(false); });
    drawerEl.querySelector('.storefront-cart-close').addEventListener('click', () => setOpen(false));
    drawerEl.querySelector('.storefront-cart-checkout').addEventListener('click', () => {
      if (!readCart().length) { alert('Tu carrito está vacío. Agrega productos antes de finalizar la compra.'); return; }
      window.location.href = '/checkout.html';
    });
  };

  const setOpen = open => {
    buildDrawer();
    drawerEl.classList.toggle('open', open);
    document.body.classList.toggle('storefront-cart-open', open);
  };

  const render = () => {
    buildDrawer();
    const cart = readCart();
    const totalQty = cart.reduce((sum, item) => sum + (Number(item.quantity) || 1), 0);
    document.querySelectorAll('.store-product-cart-count').forEach(node => {
      node.textContent = String(totalQty);
      node.hidden = totalQty === 0;
    });

    const itemsEl = drawerEl.querySelector('#storefrontCartItems');
    if (!cart.length) {
      itemsEl.innerHTML = '<div style="padding:40px 0;text-align:center;color:var(--sd-muted);">Tu carrito está vacío.</div>';
    } else {
      itemsEl.innerHTML = cart.map((item, index) => {
        const qty = Number(item.quantity) || 1;
        const unitPrice = Number(item.price) || 0;
        const lineTotal = unitPrice > 0 ? unitPrice * qty : 0;
        const img = item.image || item.imageUrl || FALLBACK_SVG;
        return (
          '<div class="storefront-cart-item">' +
          '<img src="' + escapeHtml(img) + '" alt="' + escapeHtml(item.name || '') + '" onerror="this.src=\'' + FALLBACK_SVG + '\'">' +
          '<div class="storefront-cart-item-copy">' +
          '<strong>' + escapeHtml(item.name || 'Producto') + '</strong>' +
          '<small>' + (unitPrice > 0 ? money(unitPrice) : 'Cotizar') + '</small>' +
          '<div class="storefront-cart-qty">' +
          '<button type="button" data-qty-delta="-1" data-index="' + index + '" aria-label="Restar una unidad">－</button>' +
          '<span>' + qty + '</span>' +
          '<button type="button" data-qty-delta="1" data-index="' + index + '" aria-label="Sumar una unidad">＋</button>' +
          (lineTotal > 0 ? '<span style="margin-left:6px;font-weight:700;color:var(--sd-blue);font-size:12px;">= ' + money(lineTotal) + '</span>' : '') +
          '</div></div>' +
          '<button type="button" class="storefront-cart-remove" data-remove-index="' + index + '" aria-label="Eliminar ' + escapeHtml(item.name || '') + ' del carrito"><i data-lucide="trash-2" width="16"></i></button>' +
          '</div>'
        );
      }).join('');
    }

    const totalNum = cart.reduce((total, item) => total + (Number(item.price || 0) * (Number(item.quantity) || 1)), 0);
    const hasQuoteOnly = cart.length > 0 && cart.every(i => !Number(i.price || 0));
    const totalEl = drawerEl.querySelector('#storefrontCartTotal');
    totalEl.textContent = totalNum > 0 ? money(totalNum) : (hasQuoteOnly ? 'Solicitar cotización' : '$0');

    itemsEl.querySelectorAll('[data-qty-delta]').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = Number(btn.dataset.index);
        const delta = Number(btn.dataset.qtyDelta);
        const current = readCart();
        if (!current[idx]) return;
        const newQty = (Number(current[idx].quantity) || 1) + delta;
        if (newQty <= 0) current.splice(idx, 1);
        else current[idx].quantity = Math.min(99, newQty);
        writeCart(current);
        render();
      });
    });
    itemsEl.querySelectorAll('[data-remove-index]').forEach(btn => {
      btn.addEventListener('click', () => {
        const current = readCart();
        current.splice(Number(btn.dataset.removeIndex), 1);
        writeCart(current);
        render();
      });
    });

    window.lucide?.createIcons();
  };

  document.addEventListener('click', event => {
    const opener = event.target.closest('[data-open-cart], .storefront-cart');
    if (opener) {
      event.preventDefault();
      render();
      setOpen(true);
    }
  });

  window.addEventListener('storage', event => { if (event.key === 'smartisp.cart') render(); });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', render);
  else render();

  const params = new URLSearchParams(window.location.search);
  if (params.get('openCart') === '1' || params.get('carrito') === '1') {
    render();
    setOpen(true);
  }
})();
