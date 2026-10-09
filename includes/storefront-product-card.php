<?php
$storeProductName = (string)($storeProduct['name'] ?? 'Producto SmartISP');
$storeProductImage = (string)($storeProduct['imageUrl'] ?? '');
$storeProductCategory = trim((string)($storeProduct['category'] ?? '')) ?: 'SmartISP';
$storeProductPrice = (float)($storeProduct['price'] ?? 0);
$storeProductUrl = (string)($storeProductUrl ?? '/tienda.html#catalogo');
$storeProductImage = $storeProductImage !== '' ? $storeProductImage : '/assets/favicons/favicon-512x512.png';
if (preg_match('~^/api/auth/(?:product-image|proxy-image)(?:\?|$)~', $storeProductImage)) {
    $storeProductImage .= (str_contains($storeProductImage, '?') ? '&' : '?') . 'imgrev=4';
}
?>
<article class="store-product-card">
  <a class="store-product-media" href="<?= htmlspecialchars($storeProductUrl, ENT_QUOTES, 'UTF-8') ?>" aria-label="Ver <?= htmlspecialchars($storeProductName, ENT_QUOTES, 'UTF-8') ?>">
    <img src="<?= htmlspecialchars($storeProductImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($storeProductName, ENT_QUOTES, 'UTF-8') ?>" width="640" height="560" loading="<?= ($storeProductIndex ?? 0) < 4 ? 'eager' : 'lazy' ?>" fetchpriority="<?= ($storeProductIndex ?? 0) < 2 ? 'high' : 'auto' ?>" onerror="this.onerror=null;this.src='/assets/favicons/favicon-512x512.png'">
  </a>
  <div class="store-product-content">
    <span class="store-product-category"><?= htmlspecialchars($storeProductCategory, ENT_QUOTES, 'UTF-8') ?></span>
    <h2><a href="<?= htmlspecialchars($storeProductUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($storeProductName, ENT_QUOTES, 'UTF-8') ?></a></h2>
    <div class="store-product-foot">
      <strong><?= $storeProductPrice > 0 ? '$' . number_format($storeProductPrice, 2, '.', ',') : 'Cotizar' ?></strong>
      <a href="<?= htmlspecialchars($storeProductUrl, ENT_QUOTES, 'UTF-8') ?>">Ver ficha <i data-lucide="arrow-up-right" width="15"></i></a>
    </div>
  </div>
</article>
