<?php
// Título corto y características a partir de los nombres reales de Intcomex y Siglo21.
require __DIR__ . '/../api/lib/product-name.php';

$fails = 0;
$check = static function (string $name, string $title, array $features) use (&$fails) {
    $got = smartispProductTitle($name);
    if ($got['title'] !== $title || $got['features'] !== $features) {
        $fails++;
        echo "FALLA: $name\n  título:   " . $got['title'] . "\n  esperado: $title\n  lista:    " . json_encode($got['features'], JSON_UNESCAPED_UNICODE) . "\n  esperada: " . json_encode($features, JSON_UNESCAPED_UNICODE) . "\n";
    }
};

$check('Kingston FURY Beast - DDR4 - módulo - 8 GB - DIMM de 288 contactos - 3200 MHz / PC4-25600 - CL16 - 1.35 V - sin búfer - no ECC - negro',
    'Kingston FURY Beast - DDR4 - módulo - 8 GB - negro', ['DIMM de 288 contactos', '3200 MHz / PC4-25600', 'CL16', '1.35 V', 'sin búfer', 'no ECC']);
$check('JBL TUNE - 780 NC - Headphones - Headband - Black-Sonido JBL Pure Bass de alta- resolución con tecnología Spatial Sound- Cancelación de ruido adaptativa con Smart Ambient',
    'JBL TUNE - 780 NC - Headphones - Headband - Black', ['Sonido JBL Pure Bass de alta-resolución con tecnología Spatial Sound', 'Cancelación de ruido adaptativa con Smart Ambient']);
$check('Klip Xtreme - Notebook carrying case and handbag - 15.6" - 1200D polyester - Gray/White - Ladies Bag',
    'Klip Xtreme - Notebook carrying case and handbag - 15.6" - Gray/White', ['1200D polyester', 'Ladies Bag']);
$check('Klip Xtreme - True wireless earphones - Para Phone / Para Portable electronics / Para Tablet - Wireless - 17Hrs IPX Purple',
    'Klip Xtreme - True wireless earphones - 17Hrs IPX Purple', ['Para Phone / Para Portable electronics / Para Tablet', 'Wireless']);
$check('Belkin - Soporte de seguridad para etiqueta Bluetooth antiperdida - azul - para Apple AirTag',
    'Belkin - Soporte de seguridad para etiqueta Bluetooth antiperdida - azul', ['para Apple AirTag']);
$check('COMPUTADOR/ASUS V3607VU-RP148/CORE 5 210H/512G SSD/16GB DDR/RTX 4050 6G/16 WUXGA/NO OS/BLACK',
    'Computador ASUS V3607VU-RP148 16GB Black', ['Core 5 210H', '512G SSD', '16GB DDR', 'RTX 4050 6G', '16 WUXGA', 'No OS']);
$check('TARJETA/DE/VIDEO/GIGABYTE GEFORCE RTX5070 EAGLE OC ICE 12GB GDDR7 PCIE 5.0',
    'Tarjeta de Video Gigabyte GeForce RTX5070 Eagle OC Ice 12GB GDDR7 PCIE 5.0', []);
$check('Mouse Logitech M170', 'Mouse Logitech M170', []);
$check('HP 122 - 1.5 ml - amarillo, cián, magenta - original - cartucho de tinta - para Deskjet 1000 J110, 2050A J510',
    'HP 122 - 1.5 ml - amarillo, cián, magenta - original', ['cartucho de tinta', 'para Deskjet 1000 J110, 2050A J510']);
$check('Hikvision - Surveillance camera - Indoor / Outdoor - DS-2CD1027G3-LIU bullet 2mp',
    'Hikvision - Surveillance camera - Indoor / Outdoor DS-2CD1027G3-LIU', ['bullet 2mp']);
$check('HP Color LaserJet Pro MFP 4303fdw - impresora multifunción - color - laser - Legal (216 x 356 mm) (original) - Wi-Fi(ac), Bluetooth',
    'HP Color LaserJet Pro MFP 4303fdw - impresora multifunción - color', ['laser', 'Legal (216 x 356 mm) (original)', 'Wi-Fi(ac), Bluetooth']);

$check('MOUSE LOGITECH/M170/WIRELESS/3 BOTONES/2.4 GHZ/BLUEGREY', 'Mouse Logitech M170', ['Wireless', '3 Botones', '2.4 GHz', 'Bluegrey']);
$check('IMPRESORA EPSON L3250 MULTIFUNCION/WIFI/T3170/T5170', 'Impresora Epson L3250 Multifuncion', ['WiFi', 'T3170/T5170']);
$check('CAMARA TPLINK/TAPO C410 KIT/2K', 'Camara TP-Link Tapo C410 Kit 2K', []);

echo $fails ? "$fails falla(s)\n" : "TITULOS-OK\n";
exit($fails ? 1 : 0);
