<?php
// API de campanas editoriales del storefront y productos destacados.

function storefrontCampaignSafeUrl(string $url, bool $image = false): bool {
    $url = trim($url);
    if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x1F\x7F]/', $url) || str_contains($url, '\\')) return false;
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return !$image || preg_match('#^/uploads/(campaigns|products)/[A-Za-z0-9._-]+$#', $url) === 1;
    }
    if (!$image && str_starts_with($url, '#')) return true;
    return filter_var($url, FILTER_VALIDATE_URL) !== false && strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https';
}

function storefrontCampaignText(string $value, int $limit): string {
    $value = trim($value);
    if (function_exists('mb_substr')) return mb_substr($value, 0, $limit, 'UTF-8');
    if (preg_match('/^.{0,' . $limit . '}/us', $value, $match)) return $match[0];
    return substr($value, 0, $limit);
}

function storefrontCampaignDate(mixed $value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    $date = DateTime::createFromFormat('!Y-m-d\\TH:i', $value, new DateTimeZone('America/Guayaquil'));
    $errors = DateTime::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
        throw new RuntimeException('Usa una fecha y hora válidas para programar la campaña.');
    }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function storefrontCampaignPublicProduct(array $row): array {
    $product = normalizeProductRow($row);
    return [
        'id' => $product['id'],
        'name' => $product['name'],
        'price' => $product['price'],
        'category' => $product['category'],
        'imageUrl' => $product['imageUrl'],
        'sku' => $product['sku'],
        'visible' => $product['visible'],
    ];
}

if ($action === 'upload-campaign-image' && $method === 'POST') {
    requireAdminAuth();
    if (empty($_FILES['image']['tmp_name']) || (int)($_FILES['image']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Selecciona una imagen para subir.']);
        exit;
    }
    $file = $_FILES['image'];
    if ((int)($file['size'] ?? 0) < 1 || (int)($file['size'] ?? 0) > 8 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'La imagen supera el límite de 8 MB.']);
        exit;
    }
    $info = @getimagesize($file['tmp_name']);
    $mime = $info['mime'] ?? '';
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime]) || (int)($info[0] ?? 0) < 1 || (int)($info[1] ?? 0) < 1 || (int)$info[0] * (int)$info[1] > 40000000) {
        http_response_code(400);
        echo json_encode(['error' => 'Usa una imagen JPG, PNG o WebP válida de hasta 40 megapíxeles.']);
        exit;
    }
    $dir = __DIR__ . '/../../uploads/campaigns';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo preparar el almacenamiento de imágenes.']);
        exit;
    }
    $filename = 'campaign_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo guardar la imagen.']);
        exit;
    }
    echo json_encode(['ok' => true, 'url' => '/uploads/campaigns/' . $filename]);
    exit;
}

if ($action === 'admin-store-campaigns') {
    requireAdminAuth();
    $code = 'store-home';

    if ($method === 'GET') {
        $featuredPage = [
            'eyebrow' => 'Selección comercial',
            'title' => 'Productos destacados para redes, empresas y tecnología',
            'description' => 'Una vitrina rápida de artículos del catálogo SmartISP: conectividad, cómputo, energía, seguridad, periféricos y equipamiento TI.',
            'note' => 'Selección pensada para partir rápido: revisa precio, categoría y ficha antes de cotizar o comprar.',
            'products_title' => 'Selección destacada',
            'products_description' => 'Productos elegidos para mostrar novedades y alta rotación.',
        ];
        $featuredSettings = $pdo->query("SELECT setting_key, setting_value FROM settings_rows WHERE setting_key LIKE 'store_featured_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (['eyebrow', 'title', 'description', 'note', 'products_title', 'products_description'] as $field) {
            if (isset($featuredSettings['store_featured_' . $field])) $featuredPage[$field] = (string)$featuredSettings['store_featured_' . $field];
        }
        $stmt = $pdo->prepare('SELECT * FROM storefront_campaigns WHERE code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$campaign) {
            echo json_encode(['campaign' => null, 'slides' => [], 'products' => [], 'chips' => [], 'featured_page' => $featuredPage]);
            exit;
        }
        $slides = $pdo->prepare('SELECT id, eyebrow, title, subtitle, button_text, target_url, image_url, image_alt, image_fit, image_width_pct, card_layout, is_active, starts_at, ends_at, badge_label, badge_tone, image_opacity, overlay_opacity, image_interval_seconds, promo_chip_label, promo_chip_icon, promo_chip_target_url FROM storefront_campaign_slides WHERE campaign_id = :id ORDER BY sort_order, id');
        $slides->execute([':id' => $campaign['id']]);
        $slideRows = $slides->fetchAll(PDO::FETCH_ASSOC);
        if ($slideRows) {
            $marks = implode(',', array_fill(0, count($slideRows), '?'));
            $gallery = $pdo->prepare("SELECT id, slide_id, image_url, image_alt, sort_order, is_primary FROM storefront_campaign_slide_images WHERE slide_id IN ($marks) ORDER BY slide_id, sort_order, id");
            $gallery->execute(array_column($slideRows, 'id'));
            $imagesBySlide = [];
            foreach ($gallery->fetchAll(PDO::FETCH_ASSOC) as $image) $imagesBySlide[(string)$image['slide_id']][] = $image;
            foreach ($slideRows as &$slide) $slide['images'] = $imagesBySlide[(string)$slide['id']] ?? [[
                'image_url' => $slide['image_url'], 'image_alt' => $slide['image_alt'], 'sort_order' => 0, 'is_primary' => 1
            ]];
            unset($slide);
        }
        $productTable = getProductsTableName($pdo);
        $products = $pdo->prepare("SELECT p.* FROM storefront_campaign_products cp JOIN `$productTable` p ON p.id = cp.product_id WHERE cp.campaign_id = :id ORDER BY cp.sort_order, cp.product_id");
        $products->execute([':id' => $campaign['id']]);
        $chips = $pdo->query('SELECT id, label, icon, target_url, is_active FROM storefront_promo_chips ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['campaign' => $campaign, 'slides' => $slideRows, 'products' => array_map('normalizeProductRow', $products->fetchAll(PDO::FETCH_ASSOC)), 'chips' => $chips, 'featured_page' => $featuredPage]);
        exit;
    }

    if ($method === 'POST') {
        $settings = is_array($body['campaign'] ?? null) ? $body['campaign'] : [];
        $slides = is_array($body['slides'] ?? null) ? array_slice($body['slides'], 0, 12) : [];
        $productIds = is_array($body['product_ids'] ?? null) ? array_values(array_unique(array_slice(array_filter(array_map(static fn($id) => trim((string)$id), $body['product_ids']), static fn($id) => $id !== ''), 0, 12))) : [];
        $name = trim((string)($settings['name'] ?? 'Portada de la tienda'));
        $rotation = max(4, min(20, (int)($settings['rotation_seconds'] ?? 7)));
        $displayMode = in_array(($settings['display_mode'] ?? 'carousel'), ['carousel', 'single', 'split', 'triple', 'grid', 'cards'], true) ? $settings['display_mode'] : 'carousel';
        try {
            $campaignStarts = storefrontCampaignDate($settings['starts_at'] ?? null);
            $campaignEnds = storefrontCampaignDate($settings['ends_at'] ?? null);
        } catch (RuntimeException $error) {
            http_response_code(400);
            echo json_encode(['error' => $error->getMessage()]);
            exit;
        }
        if ($campaignStarts && $campaignEnds && $campaignEnds <= $campaignStarts) {
            http_response_code(400);
            echo json_encode(['error' => 'La fecha final debe ser posterior a la inicial.']);
            exit;
        }
        $featuredPageDefaults = [
            'eyebrow' => 'Selección comercial',
            'title' => 'Productos destacados para redes, empresas y tecnología',
            'description' => 'Una vitrina rápida de artículos del catálogo SmartISP: conectividad, cómputo, energía, seguridad, periféricos y equipamiento TI.',
            'note' => 'Selección pensada para partir rápido: revisa precio, categoría y ficha antes de cotizar o comprar.',
            'products_title' => 'Selección destacada',
            'products_description' => 'Productos elegidos para mostrar novedades y alta rotación.',
        ];
        $existingFeaturedRows = $pdo->query("SELECT setting_key, setting_value FROM settings_rows WHERE setting_key LIKE 'store_featured_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $featuredInput = is_array($body['featured_page'] ?? null) ? $body['featured_page'] : [];
        $featuredPage = [];
        foreach ($featuredPageDefaults as $field => $fallback) {
            $current = $existingFeaturedRows['store_featured_' . $field] ?? $fallback;
            $limit = ['eyebrow' => 80, 'title' => 180, 'description' => 500, 'note' => 300, 'products_title' => 120, 'products_description' => 300][$field];
            $featuredPage[$field] = storefrontCampaignText((string)($featuredInput[$field] ?? $current), $limit);
        }
        if ($featuredPage['title'] === '') {
            http_response_code(400);
            echo json_encode(['error' => 'El título de Selección comercial es obligatorio.']);
            exit;
        }
        $chips = is_array($body['chips'] ?? null) ? array_slice($body['chips'], 0, 8) : [];
        if ($name === '' || strlen($name) > 150) {
            http_response_code(400);
            echo json_encode(['error' => 'El nombre de la campaña es obligatorio (máximo 150 caracteres).']);
            exit;
        }
        foreach ($slides as $slide) {
            $title = trim((string)($slide['title'] ?? ''));
            $images = is_array($slide['images'] ?? null) ? array_slice($slide['images'], 0, 12) : [];
            if (!$images && trim((string)($slide['image_url'] ?? '')) !== '') $images[] = ['image_url' => $slide['image_url']];
            $target = trim((string)($slide['target_url'] ?? ''));
            $promoTarget = trim((string)($slide['promo_chip_target_url'] ?? $target));
            $active = !array_key_exists('is_active', $slide) || !empty($slide['is_active']);
            try {
                $starts = storefrontCampaignDate($slide['starts_at'] ?? null);
                $ends = storefrontCampaignDate($slide['ends_at'] ?? null);
            } catch (RuntimeException $error) {
                http_response_code(400);
                echo json_encode(['error' => $error->getMessage()]);
                exit;
            }
            if (($active && ($title === '' || !$images)) || strlen($title) > 180 || ($target !== '' && !storefrontCampaignSafeUrl($target)) || (trim((string)($slide['promo_chip_label'] ?? '')) !== '' && !storefrontCampaignSafeUrl($promoTarget)) || ($starts && $ends && $ends <= $starts)) {
                http_response_code(400);
                echo json_encode(['error' => 'Cada banner visible necesita título, imagen, enlace y fechas válidas.']);
                exit;
            }
            foreach ($images as $image) if (!storefrontCampaignSafeUrl(trim((string)($image['image_url'] ?? '')), true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Una imagen del banner no tiene una ruta válida.']);
                exit;
            }
        }
        foreach ($chips as $chip) {
            if (trim((string)($chip['label'] ?? '')) === '' || strlen((string)$chip['label']) > 60 || !storefrontCampaignSafeUrl((string)($chip['target_url'] ?? ''))) {
                http_response_code(400);
                echo json_encode(['error' => 'Cada acceso promocional necesita un texto y un enlace válido.']);
                exit;
            }
        }
        $activeSlides = array_filter($slides, static fn($slide) => !array_key_exists('is_active', $slide) || !empty($slide['is_active']));
        $requiredSlides = ['split' => 2, 'triple' => 3, 'grid' => 4][$displayMode] ?? 1;
        if (!empty($settings['is_active']) && count($activeSlides) < $requiredSlides) {
            http_response_code(400);
            echo json_encode(['error' => 'Este formato necesita al menos ' . $requiredSlides . ' banners visibles.']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO storefront_campaigns (code, name, placement, is_active, rotation_seconds, display_mode, starts_at, ends_at) VALUES (:code, :name, 'home', :active, :rotation, :mode, :starts, :ends) ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = VALUES(is_active), rotation_seconds = VALUES(rotation_seconds), display_mode = VALUES(display_mode), starts_at = VALUES(starts_at), ends_at = VALUES(ends_at)")
                ->execute([':code' => $code, ':name' => $name, ':active' => !empty($settings['is_active']) ? 1 : 0, ':rotation' => $rotation, ':mode' => $displayMode, ':starts' => $campaignStarts, ':ends' => $campaignEnds]);
            $saveFeaturedSetting = $pdo->prepare('INSERT INTO settings_rows (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP');
            foreach ($featuredPage as $field => $value) $saveFeaturedSetting->execute(['store_featured_' . $field, $value]);
            $campaignId = (int)$pdo->query("SELECT id FROM storefront_campaigns WHERE code = 'store-home'")->fetchColumn();
            if ($productIds) {
                $productTable = getProductsTableName($pdo);
                $marks = implode(',', array_fill(0, count($productIds), '?'));
                $check = $pdo->prepare("SELECT id FROM `$productTable` WHERE visible = 1 AND id IN ($marks)");
                $check->execute($productIds);
                $found = array_map('strval', $check->fetchAll(PDO::FETCH_COLUMN));
                if (count($found) !== count($productIds)) throw new RuntimeException('Uno o más productos ya no están visibles o no existen.');
            }
            $pdo->prepare('DELETE FROM storefront_campaign_slides WHERE campaign_id = ?')->execute([$campaignId]);
            $pdo->prepare('DELETE FROM storefront_campaign_products WHERE campaign_id = ?')->execute([$campaignId]);
            if (array_key_exists('chips', $body)) $pdo->prepare('DELETE FROM storefront_promo_chips')->execute();
            $slideInsert = $pdo->prepare('INSERT INTO storefront_campaign_slides (campaign_id, eyebrow, title, subtitle, button_text, target_url, image_url, image_alt, image_fit, image_width_pct, card_layout, sort_order, is_active, starts_at, ends_at, badge_label, badge_tone, image_opacity, overlay_opacity, image_interval_seconds, promo_chip_label, promo_chip_icon, promo_chip_target_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $galleryInsert = $pdo->prepare('INSERT INTO storefront_campaign_slide_images (slide_id, image_url, image_alt, sort_order, is_primary) VALUES (?, ?, ?, ?, ?)');
            foreach ($slides as $index => $slide) {
                $images = is_array($slide['images'] ?? null) ? array_slice($slide['images'], 0, 12) : [];
                if (!$images && trim((string)($slide['image_url'] ?? '')) !== '') $images[] = ['image_url' => $slide['image_url'], 'image_alt' => $slide['image_alt'] ?? '', 'is_primary' => true];
                $primaryIndex = 0;
                foreach ($images as $imageIndex => $image) if (!empty($image['is_primary'])) { $primaryIndex = $imageIndex; break; }
                $primary = $images[$primaryIndex] ?? ['image_url' => '', 'image_alt' => ''];
                $slideInsert->execute([
                    $campaignId,
                    storefrontCampaignText((string)($slide['eyebrow'] ?? ''), 100) ?: null,
                    trim((string)$slide['title']),
                    storefrontCampaignText((string)($slide['subtitle'] ?? ''), 500) ?: null,
                    storefrontCampaignText((string)($slide['button_text'] ?? 'Explorar'), 60) ?: 'Explorar',
                    substr(trim((string)($slide['target_url'] ?? '')), 0, 500),
                    substr(trim((string)($primary['image_url'] ?? '')), 0, 500),
                    storefrontCampaignText((string)($primary['image_alt'] ?? $slide['title'] ?? ''), 200) ?: null,
                    in_array(($slide['image_fit'] ?? 'cover'), ['cover', 'contain'], true) ? $slide['image_fit'] : 'cover',
                    max(35, min(65, (int)($slide['image_width_pct'] ?? 55))),
                    in_array(($slide['card_layout'] ?? 'side'), ['full', 'side'], true) ? $slide['card_layout'] : 'side',
                    $index,
                    !array_key_exists('is_active', $slide) || !empty($slide['is_active']) ? 1 : 0,
                    storefrontCampaignDate($slide['starts_at'] ?? null),
                    storefrontCampaignDate($slide['ends_at'] ?? null),
                    storefrontCampaignText((string)($slide['badge_label'] ?? ''), 60) ?: null,
                    in_array(($slide['badge_tone'] ?? 'discount'), ['discount', 'new', 'featured', 'dark'], true) ? $slide['badge_tone'] : 'discount',
                    max(0, min(100, (int)($slide['image_opacity'] ?? 100))),
                    max(0, min(100, (int)($slide['overlay_opacity'] ?? 18))),
                    max(2, min(20, (int)($slide['image_interval_seconds'] ?? 5))),
                    storefrontCampaignText((string)($slide['promo_chip_label'] ?? ''), 60) ?: null,
                    storefrontCampaignText((string)($slide['promo_chip_icon'] ?? 'tag'), 40) ?: 'tag',
                    substr(trim((string)($slide['promo_chip_target_url'] ?? $slide['target_url'] ?? '/tienda.html#catalogo')), 0, 500),
                ]);
                $slideId = (int)$pdo->lastInsertId();
                foreach ($images as $imageIndex => $image) $galleryInsert->execute([$slideId, substr(trim((string)$image['image_url']), 0, 500), storefrontCampaignText((string)($image['image_alt'] ?? $slide['title'] ?? ''), 200) ?: null, $imageIndex, $imageIndex === $primaryIndex ? 1 : 0]);
            }
            $productInsert = $pdo->prepare('INSERT INTO storefront_campaign_products (campaign_id, product_id, sort_order) VALUES (?, ?, ?)');
            foreach ($productIds as $index => $productId) $productInsert->execute([$campaignId, $productId, $index]);
            $chipInsert = $pdo->prepare('INSERT INTO storefront_promo_chips (label, icon, target_url, sort_order, is_active) VALUES (?, ?, ?, ?, ?)');
            if (array_key_exists('chips', $body)) foreach ($chips as $index => $chip) $chipInsert->execute([storefrontCampaignText((string)$chip['label'], 60), storefrontCampaignText((string)($chip['icon'] ?? 'tag'), 40) ?: 'tag', substr(trim((string)$chip['target_url']), 0, 500), $index, !empty($chip['is_active']) ? 1 : 0]);
            $pdo->commit();
            echo json_encode(['ok' => true]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('No se pudo guardar la campaña de tienda: ' . $error->getMessage());
            $isValidationError = $error instanceof RuntimeException && !$error instanceof PDOException;
            http_response_code($isValidationError ? 400 : 500);
            echo json_encode(['error' => $isValidationError ? $error->getMessage() : 'No se pudo guardar la campaña. Revisa los logs del servidor.']);
        }
        exit;
    }
}

if ($action === 'store-campaigns' && $method === 'GET') {
    $now = 'UTC_TIMESTAMP()';
    $stmt = $pdo->query("SELECT id, rotation_seconds, display_mode FROM storefront_campaigns WHERE code = 'store-home' AND is_active = 1 AND (starts_at IS NULL OR starts_at <= $now) AND (ends_at IS NULL OR ends_at > $now) LIMIT 1");
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$campaign) {
        $chips = $pdo->query("SELECT label, icon, target_url FROM storefront_promo_chips WHERE is_active = 1 AND (starts_at IS NULL OR starts_at <= UTC_TIMESTAMP()) AND (ends_at IS NULL OR ends_at > UTC_TIMESTAMP()) ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['slides' => [], 'products' => [], 'chips' => $chips]);
        exit;
    }
    $slideStmt = $pdo->prepare('SELECT id, eyebrow, title, subtitle, button_text, target_url, image_url, image_alt, image_fit, image_width_pct, card_layout, starts_at, ends_at, badge_label, badge_tone, image_opacity, overlay_opacity, image_interval_seconds, promo_chip_label, promo_chip_icon, promo_chip_target_url FROM storefront_campaign_slides WHERE campaign_id = :id AND is_active = 1 AND (starts_at IS NULL OR starts_at <= UTC_TIMESTAMP()) AND (ends_at IS NULL OR ends_at > UTC_TIMESTAMP()) ORDER BY sort_order, id');
    $slideStmt->execute([':id' => $campaign['id']]);
    $publicSlides = $slideStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($publicSlides) {
        $marks = implode(',', array_fill(0, count($publicSlides), '?'));
        $gallery = $pdo->prepare("SELECT slide_id, image_url, image_alt, sort_order, is_primary FROM storefront_campaign_slide_images WHERE slide_id IN ($marks) ORDER BY slide_id, sort_order, id");
        $gallery->execute(array_column($publicSlides, 'id'));
        $imagesBySlide = [];
        foreach ($gallery->fetchAll(PDO::FETCH_ASSOC) as $image) $imagesBySlide[(string)$image['slide_id']][] = $image;
        foreach ($publicSlides as &$slide) $slide['images'] = $imagesBySlide[(string)$slide['id']] ?? [[
            'image_url' => $slide['image_url'], 'image_alt' => $slide['image_alt'], 'sort_order' => 0, 'is_primary' => 1
        ]];
        unset($slide);
    }
    $productTable = getProductsTableName($pdo);
    $productStmt = $pdo->prepare("SELECT p.* FROM storefront_campaign_products cp JOIN `$productTable` p ON p.id = cp.product_id WHERE cp.campaign_id = :id AND p.visible = 1 ORDER BY cp.sort_order, cp.product_id");
    $productStmt->execute([':id' => $campaign['id']]);
    $products = array_map('storefrontCampaignPublicProduct', $productStmt->fetchAll(PDO::FETCH_ASSOC));
    $chips = $pdo->query("SELECT label, icon, target_url FROM storefront_promo_chips WHERE is_active = 1 AND (starts_at IS NULL OR starts_at <= UTC_TIMESTAMP()) AND (ends_at IS NULL OR ends_at > UTC_TIMESTAMP()) ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    header('Cache-Control: public, max-age=60');
    echo json_encode(['rotation_seconds' => max(4, min(20, (int)$campaign['rotation_seconds'])), 'display_mode' => $campaign['display_mode'] ?: 'carousel', 'slides' => $publicSlides, 'products' => $products, 'chips' => $chips]);
    exit;
}
