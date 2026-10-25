// Componente "Nuestras Marcas": trae marcas activas (storefront_brands) y las muestra en la
// pagina. Reusable en cualquier pantalla publica -- ver uso en tienda.html, nosotros.html,
// servicios.html. Markup esperado (el titulo y el contenedor ya existen en el HTML de la
// pagina, este script solo los llena):
//   <section id="brandsRow" hidden><h2 id="brandsTitle">Nuestras Marcas</h2>
//     <div id="brandsList"></div></section>
window.initBrandsSection = async function initBrandsSection(options = {}) {
  const {
    containerSelector = '#brandsRow',
    listSelector = '#brandsList',
    titleSelector = '#brandsTitle',
    title = '', // vacio = deja el texto que ya tiene el HTML (default "Nuestras Marcas")
    itemClass = 'brand-item', // cambiar si la pagina ya tiene su propio nombre de clase para el CSS
  } = options;
  const container = document.querySelector(containerSelector);
  const list = document.querySelector(listSelector);
  if (!container || !list) return;
  if (title) {
    const titleEl = document.querySelector(titleSelector);
    if (titleEl) titleEl.textContent = title;
  }
  try {
    const response = await fetch('/api/auth/brands?_t=' + Date.now(), { cache: 'no-store' });
    if (!response.ok) return;
    const data = await response.json();
    const brands = Array.isArray(data.brands) ? data.brands : [];
    if (!brands.length) return;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    list.innerHTML = brands.map(brand =>
      `<div class="${itemClass}"><img src="${esc(brand.logoUrl)}" alt="${esc(brand.name)}" loading="lazy"></div>`
    ).join('');
    container.hidden = false;
  } catch (error) {
    console.warn('No se pudieron cargar las marcas:', error);
  }
};
