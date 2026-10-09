<?php
$storeProductName = (string)($storeProduct['name'] ?? 'Producto SmartISP');
$storeProductImage = (string)($storeProduct['imageUrl'] ?? '');
$storeProductCategory = trim((string)($storeProduct['category'] ?? '')) ?: 'SmartISP';
$storeProductPrice = (float)($storeProduct['price'] ?? 0);
$storeProductUrl = (string)($storeProductUrl ?? '/tienda.html#catalogo');
$storeProductOutOfStock = array_key_exists('available_qty', $storeProduct) && $storeProduct['available_qty'] !== null && (int)$storeProduct['available_qty'] <= 0;
if (preg_match('~^/api/auth/(?:product-image|proxy-image)(?:\?|$)~', $storeProductImage)) {
    $storeProductImage .= (str_contains($storeProductImage, '?') ? '&' : '?') . 'imgrev=4';
}
?>
<article class="store-product-card">
  <a class="store-product-media<?= $storeProductImage === '' ? ' is-empty' : '' ?>" href="<?= htmlspecialchars($storeProductUrl, ENT_QUOTES, 'UTF-8') ?>" aria-label="Ver <?= htmlspecialchars($storeProductName, ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($storeProductImage !== ''): ?><img src="<?= htmlspecialchars($storeProductImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($storeProductName, ENT_QUOTES, 'UTF-8') ?>" width="640" height="560" loading="<?= ($storeProductIndex ?? 0) < 4 ? 'eager' : 'lazy' ?>" fetchpriority="<?= ($storeProductIndex ?? 0) < 2 ? 'high' : 'auto' ?>" onerror="this.hidden=true"><?php endif; ?>
  </a>
  <div class="store-product-content">
    <span class="store-product-category"><?= htmlspecialchars($storeProductCategory, ENT_QUOTES, 'UTF-8') ?></span>
    <h2><a href="<?= htmlspecialchars($storeProductUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($storeProductName, ENT_QUOTES, 'UTF-8') ?></a></h2>
    <div class="store-product-foot">
      <strong><?= $storeProductPrice > 0 ? '$' . number_format($storeProductPrice, 2, '.', ',') : 'Cotizar' ?></strong>
      <a class="store-product-view" href="<?= htmlspecialchars($storeProductUrl, ENT_QUOTES, 'UTF-8') ?>" aria-label="Ver ficha de <?= htmlspecialchars($storeProductName, ENT_QUOTES, 'UTF-8') ?>"><i data-lucide="external-link" width="16"></i></a>
    </div>
    <?php if (array_key_exists('available_qty', $storeProduct)): ?><span class="store-product-stock<?= (int)$storeProduct['available_qty'] > 0 ? ' is-available' : '' ?>"><?= $storeProduct['available_qty'] === null ? 'Consultar disponibilidad' : ((int)$storeProduct['available_qty'] > 0 ? 'En stock' : 'Agotado') ?></span><?php endif; ?>
    <button class="store-product-add" type="button" data-add-to-cart="<?= htmlspecialchars(base64_encode(json_encode(['id'=>$storeProduct['id']??'','name'=>$storeProductName,'price'=>$storeProductPrice,'category'=>$storeProductCategory,'image'=>$storeProductImage,'imageUrl'=>$storeProductImage,'sku'=>$storeProduct['sku']??''], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)), ENT_QUOTES, 'UTF-8') ?>" <?= $storeProductOutOfStock ? 'disabled' : '' ?>><i data-lucide="<?= $storeProductOutOfStock ? 'ban' : 'shopping-cart' ?>" width="16"></i> <?= $storeProductOutOfStock ? 'Agotado' : 'Agregar' ?></button>
  </div>
</article>
