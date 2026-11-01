<?php
// Nombre para mostrar: los proveedores mandan «MOUSE/LOGITECH M170/WIRELESS/3 BOTONES». La base guarda el
// original (el importador empareja por nombre exacto); aquí solo se arregla lo que ve el cliente.
require_once __DIR__ . '/brands.php';

const SMARTISP_NAME_ALIASES = ['HPINC' => 'HP', 'TPLINK' => 'TP-Link', 'DLINK' => 'D-Link', 'ADATA' => 'ADATA', 'ENV' => 'ENV'];
const SMARTISP_NAME_UPPER = ['USB', 'HDMI', 'SSD', 'HDD', 'RAM', 'LED', 'LCD', 'OLED', 'QLED', 'UHD', 'FHD', 'QHD', 'HD', 'IPS', 'VA', 'TN', 'RGB', 'POE', 'NVR', 'DVR', 'IP', 'TV', 'PC', 'AIO', 'CPU', 'GPU', 'UPS', 'AC', 'DC', 'VGA', 'DVI', 'SATA', 'NVME', 'PCIE', 'NAS', 'SFP', 'LTE', 'GSM', 'NFC', 'MAC', 'OS', 'POS', 'ATX', 'ITX', 'MFF', 'SFF', 'BT', 'AMD', 'CD', 'DVD', 'PDF', 'UTP', 'FTP', 'RJ45', 'LAN', 'WAN', 'VPN', 'CCTV', 'PTZ', 'MP', 'GB', 'TB', 'MB', 'GHZ', 'MHZ', 'W', 'V', 'VA', 'XL', 'XXL', 'ID', 'II', 'III', 'IV', 'TKL', 'ANC', 'TWS', 'KVM', 'PDU', 'AP', 'ONU', 'OLT', 'GPON'];
const SMARTISP_NAME_SPECIAL = ['WIFI' => 'WiFi', 'WI-FI' => 'Wi-Fi', 'IPHONE' => 'iPhone', 'IPAD' => 'iPad', 'MACBOOK' => 'MacBook', 'PLAYSTATION' => 'PlayStation', 'GEFORCE' => 'GeForce', 'THINKPAD' => 'ThinkPad', 'THINKBOOK' => 'ThinkBook', 'IDEAPAD' => 'IdeaPad', 'VIVOBOOK' => 'Vivobook', 'ZENBOOK' => 'Zenbook', 'PROBOOK' => 'ProBook', 'ELITEBOOK' => 'EliteBook', 'EXPERTBOOK' => 'ExpertBook', 'MHZ' => 'MHz', 'GHZ' => 'GHz', 'KG' => 'kg', 'MM' => 'mm', 'CM' => 'cm', 'MAH' => 'mAh', 'PULG' => 'pulg', 'PULGS' => 'pulg', 'PULGADAS' => 'pulgadas'];
const SMARTISP_NAME_SMALL = ['de', 'del', 'con', 'para', 'y', 'e', 'o', 'u', 'en', 'la', 'el', 'las', 'los', 'sin', 'por', 'a', 'al', 'x'];

function smartispDisplayName(string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '') return $name;
    $letters = preg_replace('/[^\p{L}]/u', '', $name);
    $upper = preg_replace('/[^\p{Lu}]/u', '', $letters);
    $shouting = mb_strlen($letters) > 0 && mb_strlen($upper) / mb_strlen($letters) > 0.75;
    if (!$shouting && !preg_match('/\b(HPINC|TPLINK|DLINK)\b/', $name)) return $name;

    // En los nombres en mayúsculas la barra separa campos («MOUSE/LOGITECH M170/WIRELESS»); se respeta
    // entre modelos o números («T3170/T5170», «1660/2530») y en unidades («5600MT/S», «MB/S»).
    $parts = preg_split('#\s*/+\s*#u', $name);
    $name = array_shift($parts);
    foreach ($parts as $part) {
        if ($part === '') continue;
        $left = preg_match('/(\S+)$/u', $name, $m) ? $m[1] : '';
        $right = preg_match('/^(\S+)/u', $part, $m) ? $m[1] : '';
        $code = static fn($t) => mb_strlen($t) >= 2 && preg_match('/\d/', $t) && !preg_match('/[,;]$/', $t);
        $keep = ($code($left) && $code($right)) || preg_match('/^(S|SEG|H|MIN)$/ui', $right);
        $name .= ($keep ? '/' : ' ') . $part;
    }
    $name = trim($name);

    static $brands = null;
    if ($brands === null) {
        $brands = [];
        foreach (SMARTISP_BRANDS as $brand) $brands[mb_strtoupper($brand)] = $brand;
    }
    $words = explode(' ', $name);
    foreach ($words as $i => $word) {
        $key = mb_strtoupper($word);
        $bare = preg_replace('/[^\p{L}\d+-]/u', '', $key);
        if (isset(SMARTISP_NAME_ALIASES[$bare])) { $words[$i] = str_replace($bare, SMARTISP_NAME_ALIASES[$bare], $key); continue; }
        if (!$shouting) continue;
        if (isset($brands[$bare])) { $words[$i] = str_ireplace($bare, $brands[$bare], $word); continue; }
        if (isset(SMARTISP_NAME_SPECIAL[$bare])) { $words[$i] = str_ireplace($bare, SMARTISP_NAME_SPECIAL[$bare], $word); continue; }
        // Códigos de modelo (letras con números o guiones: M170, RTX5070, SM-A155) y siglas conocidas quedan en mayúsculas.
        if (preg_match('/\d/', $bare) || in_array($bare, SMARTISP_NAME_UPPER, true) || (preg_match('/-/', $bare) && mb_strlen($bare) <= 12)) continue;
        $lower = mb_strtolower($word);
        if ($i > 0 && in_array($lower, SMARTISP_NAME_SMALL, true)) { $words[$i] = $lower; continue; }
        // Siglas cortas sin vocales (JBL, MSI, LG, AOC queda por la lista de marcas) se respetan.
        if (mb_strlen($bare) <= 3 && !preg_match('/[AEIOUÁÉÍÓÚ]/u', $bare)) continue;
        $words[$i] = mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
    }
    return implode(' ', $words);
}
