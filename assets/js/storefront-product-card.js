(() => {
  const keyOf = product => String(product?.id || product?.sku || product?.name || 'item');
  const readCart = () => {
    try {
      const value = JSON.parse(localStorage.getItem('smartisp.cart') || '[]');
      return Array.isArray(value) ? value.filter(item => item && typeof item === 'object') : [];
    } catch { return []; }
  };
  const updateCount = () => {
    const count = readCart().reduce((total, item) => total + Math.max(1, Number(item.quantity) || 1), 0);
    document.querySelectorAll('.store-product-cart-count').forEach(node => {
      node.textContent = String(count);
      node.hidden = count === 0;
    });
  };
  const decodeProduct = value => {
    const bytes = Uint8Array.from(atob(value), char => char.charCodeAt(0));
    return JSON.parse(new TextDecoder().decode(bytes));
  };

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-add-to-cart]');
    if (!button) return;
    let product;
    try { product = decodeProduct(button.dataset.addToCart); } catch { return; }
    const cart = readCart();
    const existing = cart.find(item => keyOf(item) === keyOf(product));
    if (existing) existing.quantity = Math.min(99, Math.max(1, Number(existing.quantity) || 1) + 1);
    else cart.push({ ...product, quantity: 1, price: Math.max(0, Number(product.price) || 0) });
    try { localStorage.setItem('smartisp.cart', JSON.stringify(cart)); }
    catch { return; }
    updateCount();
    const original = button.innerHTML;
    button.classList.add('is-added');
    button.innerHTML = '<i data-lucide="check" width="16"></i> Agregado';
    window.lucide?.createIcons();
    window.setTimeout(() => {
      button.innerHTML = original;
      button.classList.remove('is-added');
      window.lucide?.createIcons();
    }, 1300);
  });
  window.addEventListener('storage', event => { if (event.key === 'smartisp.cart') updateCount(); });
  updateCount();
})();
