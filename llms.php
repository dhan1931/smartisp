<?php
// /llms.txt (formato llmstxt.org): resumen del negocio para asistentes de IA.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smartisp_llms_v2.txt';
if (is_file($cacheFile) && time() - filemtime($cacheFile) < 21600 && filesize($cacheFile) > 200) {
    readfile($cacheFile);
    exit;
}

require_once __DIR__ . '/api/lib/db.php';

$siteUrl = rtrim((string)(getenv('SITE_URL') ?: 'https://smart-isp.com.ec'), '/');
$categories = [];
$settings = [];
$productCount = 0;

$pdo = getDbConnection();
if ($pdo) {
    try {
        $pTable = getProductsTableName($pdo);
        $rows = $pdo->query("SELECT TRIM(category) AS category, COUNT(*) AS total FROM `$pTable`
            WHERE visible = 1 AND TRIM(category) <> '' GROUP BY TRIM(category) ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $categories[] = ['name' => $row['category'], 'total' => (int)$row['total']];
            $productCount += (int)$row['total'];
        }
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings_rows WHERE setting_key IN
            ('footer_whatsapp', 'footer_email', 'footer_address', 'footer_text', 'footer_social_facebook',
             'footer_social_instagram', 'footer_social_linkedin', 'footer_social_youtube')");
        $settings = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    } catch (Throwable $e) {
        error_log('llms.txt: ' . $e->getMessage());
    }
}

$clean = static fn($value) => trim(preg_replace('/\s+/', ' ', strip_tags((string)$value)));
$lines = [];
$lines[] = '# SmartISP Ecuador';
$lines[] = '';
$lines[] = '> Tienda y proveedor de tecnología en Ecuador: equipos de redes y fibra óptica, cómputo, energía (UPS), seguridad electrónica e infraestructura TI para empresas, hogares e ISP, con asesoría, cotizaciones, implementación y soporte.';
$lines[] = '';
$lines[] = 'SmartISP vende en línea en ' . $siteUrl . ' y atiende desde Daule (Guayas, Ecuador), en el C.C. Atrio Plaza, de lunes a viernes de 9:00 a 18:00. Envío gratis en la provincia del Guayas y despacho a todo Ecuador. Precios en dólares (USD). Los productos sin precio publicado se cotizan.';
$lines[] = '';
$lines[] = '## Contacto';
$lines[] = '';
$whatsapp = $clean($settings['footer_whatsapp'] ?? '');
$email = $clean($settings['footer_email'] ?? '');
$address = $clean($settings['footer_address'] ?? '');
foreach (smartispWhatsappNumbers((string)($settings['footer_whatsapp'] ?? '')) as $wa) {
    $lines[] = '- WhatsApp' . ($wa['label'] !== '' ? ' (' . $clean($wa['label']) . ')' : '') . ': ' . $clean($wa['number']) . ' (https://wa.me/' . $wa['digits'] . ')';
}
if ($email !== '') $lines[] = '- Correo: ' . $email;
$lines[] = '- Dirección: ' . ($address !== '' ? $address : 'C.C. Atrio Plaza, Daule, Guayas, Ecuador');
$lines[] = '- Horario: lunes a viernes, 9:00 a 18:00 (hora de Ecuador)';
foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube'] as $key => $label) {
    $url = $clean($settings['footer_social_' . $key] ?? '');
    if (preg_match('#^https?://#i', $url)) $lines[] = '- ' . $label . ': ' . $url;
}
$lines[] = '';
$lines[] = '## Páginas principales';
$lines[] = '';
$lines[] = '- [Tienda](' . $siteUrl . '/): catálogo completo con búsqueda, filtros y carrito.';
$lines[] = '- [Productos destacados](' . $siteUrl . '/productos-destacados): selección comercial vigente.';
$lines[] = '- [Servicios](' . $siteUrl . '/servicios): asesoría de compra, redes y fibra óptica, energía, seguridad, cómputo y atención comercial.';
$lines[] = '- [Nosotros](' . $siteUrl . '/nosotros): quiénes somos, misión, visión y marcas.';
$lines[] = '';
if ($categories) {
    $lines[] = '## Catálogo por categoría';
    $lines[] = '';
    $lines[] = number_format($productCount, 0, ',', '.') . ' productos publicados en ' . count($categories) . ' categorías:';
    $lines[] = '';
    foreach ($categories as $category) {
        $lines[] = '- [' . $category['name'] . '](' . $siteUrl . '/categoria/' . smartispSlug($category['name'], 90, 'categoria') . '/): ' . $category['total'] . ($category['total'] === 1 ? ' producto' : ' productos');
    }
    $lines[] = '';
}
$lines[] = '## Servicios';
$lines[] = '';
$lines[] = '- Asesoría de compra: selección de equipos según necesidad, presupuesto, compatibilidad y disponibilidad.';
$lines[] = '- Redes y fibra óptica: conectividad, switching, WiFi, cableado estructurado y fibra.';
$lines[] = '- Energía e infraestructura: UPS, protección eléctrica, climatización y racks.';
$lines[] = '- Seguridad y monitoreo: cámaras, grabadores y control de acceso.';
$lines[] = '- Cómputo y periféricos: laptops, computadores, monitores, impresoras y accesorios.';
$lines[] = '- Implementación y soporte: proyectos para empresas e ISP, con acompañamiento postventa.';
$lines[] = '';
$lines[] = '## Datos para citar';
$lines[] = '';
$lines[] = '- Precio, disponibilidad y especificaciones vigentes están en cada ficha: ' . $siteUrl . '/producto/<id>-<nombre>.';
$lines[] = '- Listado completo de URLs: ' . $siteUrl . '/sitemap.xml';
$lines[] = '';

$output = implode("\n", $lines);
@file_put_contents($cacheFile, $output);
echo $output;
