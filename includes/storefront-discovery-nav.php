<?php
$storefrontActivePage = $storefrontActivePage ?? '';
$storefrontNavLinks = [
    ['catalog', '/tienda.html', 'Tienda', 'house'],
    ['categories', '/categorias-destacadas', 'Categorías', 'layout-grid'],
    ['about', '/nosotros', 'Nosotros', 'users'],
    ['services', '/servicios', 'Servicios', 'settings'],
    ['products', '/productos-destacados', 'Productos', 'sparkles'],
];
?>
<div class="storefront-topbar"><span><i data-lucide="truck" width="14"></i> Envío gratis en Guayas</span><span><i data-lucide="headphones" width="14"></i> Atención experta · Lun a Vie 9:00 a 18:00</span></div>
<header class="storefront-header">
  <a class="storefront-logo logo" href="/" title="SmartISP Tienda Online">SmartISP</a>
  <form class="storefront-search" action="/tienda.html" method="get" role="search">
    <input type="search" name="q" placeholder="¿Qué estás buscando hoy?" aria-label="Buscar productos" required>
    <button type="submit" aria-label="Buscar"><i data-lucide="search" width="18"></i></button>
  </form>
  <div class="storefront-actions">
    <a href="/tienda.html?openAccount=1" aria-label="Mi cuenta"><i data-lucide="user" width="18"></i><span>Mi cuenta</span></a>
    <a class="storefront-cart" href="/tienda.html?openCart=1" aria-label="Carrito"><i data-lucide="shopping-cart" width="20"></i><span>Carrito</span><b class="store-product-cart-count" hidden>0</b></a>
    <button class="storefront-menu-button" type="button" aria-label="Abrir categorías" aria-expanded="false" aria-controls="storefrontNavigation"><i data-lucide="menu" width="20"></i></button>
  </div>
</header>
<button class="storefront-menu-backdrop" type="button" aria-label="Cerrar categorías" hidden></button>
<nav class="storefront-navigation" id="storefrontNavigation" aria-label="Secciones principales">
  <div class="storefront-drawer-head"><a class="storefront-drawer-logo logo" href="/" aria-label="SmartISP inicio">SmartISP</a><button class="storefront-menu-close" type="button" aria-label="Cerrar categorías"><i data-lucide="x" width="25"></i></button></div>
  <?php foreach ($storefrontNavLinks as [$key, $href, $label, $icon]): ?>
    <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"<?= $storefrontActivePage === $key ? ' aria-current="page"' : '' ?>><i data-lucide="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>" width="16"></i><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
  <?php endforeach; ?>
  <div class="storefront-category-menu" id="storefrontCategoryMenu">
    <button class="storefront-category-trigger" type="button" aria-expanded="false" aria-controls="storefrontCategoryPanel"><span>Categorías</span><i data-lucide="chevron-down" width="16"></i></button>
    <div class="storefront-category-panel" id="storefrontCategoryPanel" hidden>
      <div class="storefront-category-panel-head"><strong>Categorías</strong><a href="/categorias-destacadas">Ver todas <i data-lucide="arrow-right" width="16"></i></a></div>
      <label class="storefront-category-search"><i data-lucide="search" width="15"></i><input type="search" placeholder="Buscar categoría..." aria-label="Buscar categoría"></label>
      <div class="storefront-category-list" id="storefrontCategoryList">
        <a href="/tienda.html#catalogo" data-store-category="todo el catálogo">Todo el catálogo <i data-lucide="chevron-right" width="16"></i></a>
        <a href="/tienda.html?macro=computacion#catalogo" data-store-category="computacion equipos">Computación y Equipos <i data-lucide="chevron-right" width="16"></i></a>
        <a href="/tienda.html?macro=componentes#catalogo" data-store-category="componentes almacenamiento">Componentes y Almacenamiento <i data-lucide="chevron-right" width="16"></i></a>
        <a href="/tienda.html?macro=redes#catalogo" data-store-category="redes conectividad">Redes y Conectividad <i data-lucide="chevron-right" width="16"></i></a>
        <a href="/tienda.html?macro=monitores#catalogo" data-store-category="monitores pantallas">Monitores y Pantallas <i data-lucide="chevron-right" width="16"></i></a>
        <a href="/tienda.html?macro=perifericos#catalogo" data-store-category="perifericos accesorios">Periféricos y Accesorios <i data-lucide="chevron-right" width="16"></i></a>
      </div>
    </div>
  </div>
</nav>
<link rel="stylesheet" href="/assets/css/storefront-cart.css?v=shared-cart-drawer-20261009">
<script src="/assets/js/public-admin-link.js?v=public-admin-access-20261009" defer></script>
<script src="/assets/js/storefront-cart.js?v=shared-cart-drawer-20261009" defer></script>
<nav class="storefront-mobile-bar" aria-label="Accesos rápidos">
  <a href="/tienda.html"><i data-lucide="store" width="19"></i><span>Tienda</span></a>
  <a href="/categorias-destacadas"><i data-lucide="layout-grid" width="19"></i><span>Categorías</span></a>
  <a href="/tienda.html#catalogo"><i data-lucide="search" width="19"></i><span>Buscar</span></a>
  <a href="/tienda.html?openCart=1" data-open-cart><i data-lucide="shopping-cart" width="19"></i><span>Carrito</span></a>
  <a href="/tienda.html?openAccount=1"><i data-lucide="user" width="19"></i><span>Cuenta</span></a>
</nav>
