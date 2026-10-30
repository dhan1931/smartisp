(function () {
  'use strict';

  const shared = window.SmartStorefrontCart;
  if (!shared) return;
  const { money, escapeHtml, productKey } = shared;
  const $ = selector => document.querySelector(selector);

  const form = $('#checkoutForm');
  const submitButton = $('#submitButton');
  const message = $('#message');
  const itemsList = $('#cartItemsList');
  const REQUIRED_FIELDS = ['name', 'email', 'phone', 'address', 'city'];

  const groupCart = list => {
    const map = new Map();
    list.forEach(item => {
      const key = productKey(item);
      const qty = Math.max(1, Math.min(99, Number(item.quantity) || 1));
      if (map.has(key)) map.get(key).quantity = Math.min(99, map.get(key).quantity + qty);
      else map.set(key, { id: item.id ?? '', sku: item.sku || '', name: String(item.name || 'Producto'), price: Math.max(0, Number(item.price) || 0), image: item.image || item.imageUrl || '', category: item.category || '', quantity: qty });
    });
    return [...map.values()];
  };

  let cart = groupCart(shared.read());
  let activeUser = null;
  let submitting = false;
  let idempotencyKey = '';

  const saveCart = () => {
    shared.write(cart);
    idempotencyKey = '';
  };
  const cartTotal = () => cart.reduce((sum, item) => sum + item.price * item.quantity, 0);
  const isQuoteOnly = () => cart.length > 0 && cart.every(item => !item.price);
  const amountText = (total, quote) => total > 0 ? money(total) : (quote ? 'Solicitar cotización' : '$0.00');
  const taxBreakdown = total => {
    const base = total > 0 ? total / 1.15 : 0;
    return { base, iva: total > 0 ? total - base : 0 };
  };
  const icons = () => window.lucide?.createIcons();

  const resetSubmitButton = () => {
    submitButton.innerHTML = isQuoteOnly()
      ? '<i data-lucide="send" width="18"></i> Solicitar cotización y asesor comercial'
      : '<i data-lucide="check-circle" width="18"></i> Confirmar compra y solicitar asesor';
    submitButton.disabled = !cart.length;
    icons();
  };

  const showMessage = text => {
    message.textContent = text;
    message.classList.add('show');
  };
  const hideMessage = () => message.classList.remove('show');

  const renderSummary = () => {
    if (!cart.length) {
      itemsList.innerHTML = '<div class="cart-empty">Tu carrito está vacío.<br><a href="/tienda.html">Ir al catálogo</a></div>';
    } else {
      itemsList.innerHTML = cart.map((item, index) => {
        const name = escapeHtml(item.name);
        return `<div class="cart-line">
          <img src="${escapeHtml(item.image || shared.fallbackImage)}" alt="${name}" width="52" height="52" data-fallback="${escapeHtml(shared.fallbackImage)}" onerror="this.onerror=null;this.src=this.dataset.fallback">
          <div class="cart-line-info">
            <strong>${name}</strong>
            <small>${item.price > 0 ? money(item.price) : 'Cotizar'}</small>
            <div class="cart-line-qty">
              <button type="button" data-qty="-1" data-index="${index}" aria-label="Quitar una unidad de ${name}">−</button>
              <span aria-live="polite">${item.quantity}</span>
              <button type="button" data-qty="1" data-index="${index}" aria-label="Agregar una unidad de ${name}" ${item.quantity >= 99 ? 'disabled' : ''}>+</button>
            </div>
          </div>
          <div class="cart-line-side">
            <div class="cart-line-price">${money(item.price * item.quantity)}</div>
            <button type="button" class="cart-line-remove" data-remove="${index}" aria-label="Eliminar ${name} del pedido"><i data-lucide="trash-2" width="14"></i></button>
          </div>
        </div>`;
      }).join('');
    }
    const total = cartTotal();
    const quote = isQuoteOnly();
    const { base, iva } = taxBreakdown(total);
    $('#subtotalBaseText').textContent = amountText(base, quote);
    $('#ivaText').textContent = total > 0 ? money(iva) : '$0.00';
    $('#totalText').textContent = amountText(total, quote);
    $('.advisor-callout strong').textContent = quote ? 'Atención personalizada para cotización:' : 'Atención personalizada:';
    if (!submitting) resetSubmitButton();
    icons();
  };

  itemsList.addEventListener('click', event => {
    const qtyButton = event.target.closest('[data-qty]');
    const removeButton = event.target.closest('[data-remove]');
    if (!qtyButton && !removeButton) return;
    const index = Number((qtyButton || removeButton).dataset[qtyButton ? 'index' : 'remove']);
    if (!cart[index] || submitting) return;
    const next = removeButton ? 0 : cart[index].quantity + Number(qtyButton.dataset.qty);
    if (next <= 0) cart.splice(index, 1);
    else cart[index].quantity = Math.min(99, next);
    saveCart();
    renderSummary();
  });

  window.addEventListener('storage', event => {
    if (event.key !== 'smartisp.cart' || submitting) return;
    cart = groupCart(shared.read());
    renderSummary();
  });

  const renderProfile = user => {
    const notice = $('#profileNotice');
    const status = $('#profileStatusText');
    const badge = $('#profileBadge');
    if (user) {
      const fullName = [user.name, user.surname].filter(Boolean).join(' ');
      ['name', 'email', 'phone'].forEach(field => {
        const value = field === 'name' ? fullName : user[field];
        if (value && !form.elements[field].value) form.elements[field].value = value;
      });
      notice.classList.remove('guest');
      status.innerHTML = `Sesión activa como <strong>${escapeHtml(fullName || user.email)}</strong> · Correo de tu perfil: <strong>${escapeHtml(user.email)}</strong> · Celular: <strong>${escapeHtml(user.phone || 'Pendiente de registrar')}</strong>`;
      badge.innerHTML = '<i data-lucide="check" width="12"></i> Usuario Registrado';
    } else {
      notice.classList.add('guest');
      status.innerHTML = 'No has iniciado sesión. Puedes completar tus datos para la compra o <a href="/mi-cuenta?redirect=%2Fcheckout.html">iniciar sesión aquí</a>.';
      badge.innerHTML = '<i data-lucide="user-x" width="12"></i> Invitado';
    }
    icons();
  };

  fetch('/api/auth/session', { credentials: 'include' })
    .then(response => response.ok ? response.json() : null)
    .then(result => {
      activeUser = result?.user || null;
      renderProfile(activeUser);
    })
    .catch(() => renderProfile(null));

  const validate = values => {
    let firstInvalid = null;
    REQUIRED_FIELDS.forEach(field => {
      const input = form.elements[field];
      const value = String(values[field] || '').trim();
      let valid = value !== '';
      if (valid && field === 'email') valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
      if (valid && field === 'phone') valid = value.replace(/\D/g, '').length >= 7;
      input.setAttribute('aria-invalid', String(!valid));
      if (!valid && !firstInvalid) firstInvalid = input;
    });
    if (firstInvalid) firstInvalid.focus();
    return !firstInvalid;
  };

  form.addEventListener('input', event => {
    if (event.target.getAttribute('aria-invalid') === 'true') event.target.removeAttribute('aria-invalid');
  });

  const readJson = async response => {
    const text = await response.text();
    try { return text ? JSON.parse(text) : null; } catch { return null; }
  };

  const showConfirmation = (result, values, localItems, localTotal, quote) => {
    const order = result.order && typeof result.order === 'object' ? result.order : null;
    const serverItems = Array.isArray(order?.items) && order.items.length ? order.items : null;
    const items = serverItems || localItems;
    const total = Number.isFinite(Number(order?.total)) ? Number(order.total) : localTotal;
    const isQuote = order?.status === 'quote_requested' || result.isQuote === true || (result.isQuote == null && quote);
    const rawId = String(order?.id || result.id || result.orderId || result.shortId || '').trim();

    $('#confirmedOrderId').textContent = rawId ? (rawId.startsWith('#') ? rawId : `#${rawId}`) : 'pendiente';
    const profileEmail = String(activeUser?.email || '').trim().toLowerCase();
    const shippingEmail = String(values.email).trim().toLowerCase();
    $('#confirmedEmailText').textContent = profileEmail && profileEmail !== shippingEmail ? `${values.email} y a ${profileEmail} (correo de tu perfil)` : values.email;
    $('#confirmedPhoneText').textContent = values.phone;

    if (isQuote) {
      $('#successTitle').textContent = '¡Solicitud de cotización enviada con éxito!';
      $('#successLead').textContent = 'Tu requerimiento ha sido registrado en nuestro sistema comercial. Un asesor se comunicará a tu celular para entregarte la propuesta personalizada.';
    }

    $('#successItemsBody').innerHTML = items.map(item => {
      const quantity = Math.max(1, Number(item.quantity) || 1);
      const detail = item.category || (item.sku ? `SKU: ${item.sku}` : 'SmartISP');
      return `<tr><td><strong>${escapeHtml(item.name || 'Producto')}</strong><small>${escapeHtml(detail)}</small></td><td class="qty">${quantity}</td><td class="num">${money((Number(item.price) || 0) * quantity)}</td></tr>`;
    }).join('');
    const { base, iva } = taxBreakdown(total);
    $('#successSubtotalBaseText').textContent = amountText(base, isQuote);
    $('#successIvaText').textContent = total > 0 ? money(iva) : '$0.00';
    $('#successTotalText').textContent = amountText(total, isQuote);

    $('#processBanner').classList.add('is-hidden');
    $('#checkoutView').classList.add('is-hidden');
    $('#confirmationSuccessView').classList.remove('is-hidden');
    window.scrollTo({ top: 0, behavior: 'smooth' });
    $('#successTitle').focus();
    icons();
  };

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (submitting) return;
    hideMessage();
    if (!cart.length) return showMessage('Tu carrito está vacío. Agrega productos antes de confirmar.');

    const values = Object.fromEntries(new FormData(form));
    Object.keys(values).forEach(key => { values[key] = String(values[key]).trim(); });
    if (!validate(values)) return showMessage('Revisa los campos marcados: completa tus datos con un correo válido y un celular de al menos 7 dígitos.');

    submitting = true;
    submitButton.disabled = true;
    submitButton.innerHTML = '<i data-lucide="loader" width="18"></i> Procesando tu orden y enviando comprobante...';
    icons();

    if (!idempotencyKey) idempotencyKey = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    const localItems = cart.map(item => ({ ...item }));
    const localTotal = cartTotal();
    const quote = isQuoteOnly();

    try {
      const response = await fetch('/api/auth/customer-orders', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Idempotency-Key': idempotencyKey },
        body: JSON.stringify({
          items: cart.map(item => ({ id: productKey(item), sku: item.sku, name: item.name, price: item.price, quantity: item.quantity, image: item.image, category: item.category })),
          shipping: values,
          total: localTotal,
          idempotencyKey
        })
      });
      const result = await readJson(response);
      if (!result) {
        throw new Error(response.ok
          ? 'El servidor respondió de forma inesperada. Tu pedido podría no haberse registrado: revisa tu correo antes de intentar de nuevo.'
          : `El servidor no está disponible en este momento (HTTP ${response.status}). Intenta de nuevo en unos minutos.`);
      }
      if (!response.ok) {
        const invalid = new Set((Array.isArray(result.invalidItems) ? result.invalidItems : []).map(String));
        if (invalid.size) {
          const removed = cart.filter(item => invalid.has(productKey(item)));
          cart = cart.filter(item => !invalid.has(productKey(item)));
          saveCart();
          renderSummary();
          if (removed.length) {
            const names = removed.map(item => item.name).join(', ');
            throw new Error(`Quitamos de tu pedido ${removed.length === 1 ? 'un producto que ya no está disponible' : `${removed.length} productos que ya no están disponibles`}: ${names}. ${cart.length ? 'Revisa el resumen y confirma de nuevo.' : 'Tu carrito quedó vacío.'}`);
          }
        }
        throw new Error(result.error || 'No se pudo registrar el pedido.');
      }

      cart = [];
      shared.write([]);
      showConfirmation(result, values, localItems, localTotal, quote);
    } catch (error) {
      showMessage(error.message || 'Ocurrió un problema al procesar tu pedido.');
      submitting = false;
      resetSubmitButton();
    }
  });

  renderSummary();
})();
