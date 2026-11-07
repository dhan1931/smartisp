<?php
// Nombre para mostrar: los proveedores mandan «MOUSE/LOGITECH M170/WIRELESS/3 BOTONES». La base guarda el
// original (el importador empareja por nombre exacto); aquí solo se arregla lo que ve el cliente.
require_once __DIR__ . '/brands.php';

const SMARTISP_NAME_ALIASES = ['HPINC' => 'HP', 'TPLINK' => 'TP-Link', 'DLINK' => 'D-Link', 'ADATA' => 'ADATA', 'ENV' => 'ENV'];
const SMARTISP_NAME_UPPER = ['USB', 'HDMI', 'SSD', 'HDD', 'RAM', 'LED', 'LCD', 'OLED', 'QLED', 'UHD', 'FHD', 'QHD', 'HD', 'IPS', 'VA', 'TN', 'RGB', 'POE', 'NVR', 'DVR', 'IP', 'TV', 'PC', 'AIO', 'CPU', 'GPU', 'UPS', 'AC', 'DC', 'VGA', 'DVI', 'SATA', 'NVME', 'PCIE', 'NAS', 'SFP', 'LTE', 'GSM', 'NFC', 'MAC', 'OS', 'POS', 'ATX', 'ITX', 'MFF', 'SFF', 'BT', 'AMD', 'CD', 'DVD', 'PDF', 'UTP', 'FTP', 'RJ45', 'LAN', 'WAN', 'VPN', 'CCTV', 'PTZ', 'MP', 'GB', 'TB', 'MB', 'GHZ', 'MHZ', 'W', 'V', 'VA', 'XL', 'XXL', 'ID', 'II', 'III', 'IV', 'TKL', 'ANC', 'TWS', 'KVM', 'PDU', 'AP', 'ONU', 'OLT', 'GPON', 'OC', 'WUXGA', 'WQHD'];
const SMARTISP_NAME_SPECIAL = ['WIFI' => 'WiFi', 'WI-FI' => 'Wi-Fi', 'IPHONE' => 'iPhone', 'IPAD' => 'iPad', 'MACBOOK' => 'MacBook', 'PLAYSTATION' => 'PlayStation', 'GEFORCE' => 'GeForce', 'THINKPAD' => 'ThinkPad', 'THINKBOOK' => 'ThinkBook', 'IDEAPAD' => 'IdeaPad', 'VIVOBOOK' => 'Vivobook', 'ZENBOOK' => 'Zenbook', 'PROBOOK' => 'ProBook', 'ELITEBOOK' => 'EliteBook', 'EXPERTBOOK' => 'ExpertBook', 'MHZ' => 'MHz', 'GHZ' => 'GHz', 'KG' => 'kg', 'MM' => 'mm', 'CM' => 'cm', 'MAH' => 'mAh', 'PULG' => 'pulg', 'PULGS' => 'pulg', 'PULGADAS' => 'pulgadas'];
const SMARTISP_NAME_SMALL = ['de', 'del', 'con', 'para', 'y', 'e', 'o', 'u', 'en', 'la', 'el', 'las', 'los', 'sin', 'por', 'a', 'al', 'x'];

function smartispNameIsShouting(string $name): bool {
    $letters = preg_replace('/[^\p{L}]/u', '', $name);
    return $letters !== '' && mb_strlen(preg_replace('/[^\p{Lu}]/u', '', $letters)) / mb_strlen($letters) > 0.75;
}

function smartispDisplayName(string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '') return $name;
    $shouting = smartispNameIsShouting($name);
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

// Título y características: Intcomex mete todo en el nombre («JBL TUNE - 780 NC - Headphones - Headband -
// Black-Sonido JBL Pure Bass…») y Siglo21 separa campos con «/». El título llega hasta el modelo y lo demás
// pasa a una lista; la URL, el panel y los pedidos siguen usando el nombre completo.
const SMARTISP_TITLE_MAX = 70;
const SMARTISP_TITLE_SENTENCE = '/\b(con|para|que|hasta|de alta|tecnolog|permite|ideal|incluye|dise[nñ]o|perfecto)\b/iu';
const SMARTISP_TITLE_FOREIGN = '/^(para|compatible|for)\b/iu';
const SMARTISP_TITLE_COLOR = '/\b(negr[oa]|blanc[oa]|gris|azul|roj[oa]|verde|rosa|rosado|morado|lila|amarillo|naranja|plata|plateado|dorado|beige|black|white|gr[ae]y|blue|red|green|pink|purple|silver|gold|copper|graphite|grafito|midnight|starlight)\b/iu';
const SMARTISP_TITLE_CODE = '/\b(?!\d+(?:[.,]\d+)?(?:GB|TB|MB|KB|W|KW|MP|MAH|HZ|MHZ|GHZ|V|VA|KVA|MM|CM|ML|PAG|PPM|DPI|RPM|BTU|M|D)\b)(?:(?=[A-Z0-9-]*\d)(?=[A-Z0-9-]*[A-Z])[A-Z0-9]+(?:-[A-Z0-9]+)+|(?=\w*\d)(?=\w*[A-Z])[A-Z0-9]{5,})\b/u';

function smartispProductTitle(string $name): array {
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    [$title, $features] = smartispSplitProductName($name);
    // Las mayúsculas se arreglan solo si el nombre entero venía en mayúsculas («3200 MHz / PC4-25600» no se toca).
    $format = smartispNameIsShouting($name) || preg_match('/\b(HPINC|TPLINK|DLINK)\b/', $name) ? 'smartispDisplayName' : 'trim';
    $features = array_values(array_filter(array_map($format, $features), static fn($f) => $f !== ''));
    return ['title' => $format($title), 'features' => $features];
}

function smartispSplitProductName(string $name): array {
    $shouting = smartispNameIsShouting($name);
    if ((!$shouting && str_contains($name, ' - ')) || preg_match('/\s-\p{Lu}/u', $name)) {
        $segments = smartispNameDashSegments($name);
        if (count($segments) >= 2) return smartispNameBuildTitle($segments, ' - ', false);
    }
    if (str_contains($name, '/') && mb_strlen($name) > 50) {
        $segments = smartispNameSlashSegments($name);
        if (count($segments) >= 3) return smartispNameBuildTitle($segments, ' ', true);
    }
    if (str_contains($name, ';') && mb_strlen($name) > 60) {
        [$head, $tail] = explode(';', $name, 2);
        return [trim($head), array_values(array_filter(array_map('trim', explode(',', $tail)), 'strlen'))];
    }
    if (mb_strlen($name) > SMARTISP_TITLE_MAX && substr_count($name, ',') >= 2) {
        $parts = array_values(array_filter(array_map('trim', explode(',', $name)), 'strlen'));
        $n = mb_strlen($parts[0]) >= 20 ? 1 : 2;
        return [implode(', ', array_slice($parts, 0, $n)), array_slice($parts, $n)];
    }
    return [$name, []];
}

function smartispNameDashSegments(string $name): array {
    $out = [];
    foreach (preg_split('/\s+[-–—]\s+|\s+-(?=\p{Lu})/u', $name) as $segment) {
        // Frases pegadas con guion («Black-Sonido…», «Spatial Sound- Cancelación…»), sin partir «Wi-Fi» ni «Anti-Glare».
        $segment = preg_replace_callback('/(\p{L}{3,})-(\s*)(?=(\p{Lu}\p{Ll}.*))/u', static function ($m) {
            $color = preg_match('/^(black|white|gr[ae]y|blue|red|green|pink|purple|silver|gold|copper|negro|blanco|gris|azul|rojo|verde|rosa|morado)$/iu', $m[1]);
            return ($m[2] !== '' || $color || mb_strlen($m[3]) >= 25) ? $m[1] . ' |' : $m[0];
        }, trim($segment));
        $segment = preg_replace('/(?<=\w)-\s+(?=\p{Ll})/u', '-', $segment);
        foreach (explode(' |', $segment) as $part) {
            if (($part = trim($part)) !== '') $out[] = $part;
        }
    }
    return $out;
}

function smartispNameSlashSegments(string $name): array {
    $out = [];
    $code = static fn($t) => mb_strlen($t) >= 2 && preg_match('/\d/', $t) && !preg_match('/[,;]$/', $t);
    foreach (preg_split('#\s*/+\s*#u', $name) as $part) {
        if (($part = trim($part)) === '') continue;
        if ($out) {
            $left = preg_match('/(\S+)$/u', $out[count($out) - 1], $m) ? $m[1] : '';
            $right = preg_match('/^(\S+)/u', $part, $m) ? $m[1] : '';
            if (($code($left) && $code($right) && $right === $part) || preg_match('/^(S|SEG|H|MIN)\b/ui', $right)) {
                $out[count($out) - 1] .= '/' . $part;
                continue;
            }
        }
        $out[] = $part;
    }
    return $out;
}

function smartispNameBuildTitle(array $segments, string $sep, bool $slash): array {
    $title = array_shift($segments);
    if ($slash) {
        // Hasta el modelo: «TARJETA/DE/VIDEO/GIGABYTE RTX5070…», «MOUSE LOGITECH/M170/…».
        while ($segments && !preg_match('/\d/', $title) && count(explode(' ', $title)) < 6 && mb_strlen($title) + 1 + mb_strlen($segments[0]) <= 90) {
            $title .= ' ' . array_shift($segments);
        }
    } else {
        $used = 1;
        while ($segments && $used < 4) {
            $next = $segments[0];
            $onlyBrand = $used === 1 && mb_strlen($title) < 18;
            if (!$onlyBrand && (mb_strlen($title) + mb_strlen($sep) + mb_strlen($next) > SMARTISP_TITLE_MAX || mb_strlen($next) > 45 || ($used >= 2 && preg_match(SMARTISP_TITLE_SENTENCE, $next)))) break;
            $title .= $sep . array_shift($segments);
            $used++;
        }
    }
    // Lo que distingue a productos hermanos (modelo, color) no puede quedar solo en la lista.
    if ($segments && !preg_match(SMARTISP_TITLE_CODE, $title)) {
        foreach ($segments as $i => $feature) {
            if (mb_strlen($feature) > 40 || !preg_match(SMARTISP_TITLE_CODE, $feature, $m, PREG_OFFSET_CAPTURE) || $m[0][1] !== 0) continue;
            $title .= ' ' . $m[0][0];
            $rest = trim(mb_substr($feature, mb_strlen($m[0][0])));
            if ($rest === '') array_splice($segments, $i, 1); else $segments[$i] = $rest;
            break;
        }
    }
    foreach ($segments as $feature) {
        if (!preg_match('/^(\d+(?:[.,]\d+)?\s?(?:GB|TB))\b/iu', $feature, $m)) continue;
        if (!preg_match('/(^|\s)' . preg_quote($m[1], '/') . '\b/iu', $title)) $title .= ' ' . $m[1];
        break;
    }
    $colored = false;
    foreach ($segments as $i => $feature) {
        if (mb_strlen($feature) > 22 || preg_match(SMARTISP_TITLE_FOREIGN, $feature) || !preg_match(SMARTISP_TITLE_COLOR, $feature)) continue;
        if (mb_stripos($title, $feature) === false) $title .= ($slash ? ' ' : $sep) . $feature;
        array_splice($segments, $i, 1);
        $colored = true;
        break;
    }
    if (!$colored) {
        foreach ($segments as $feature) {
            if (!preg_match('/\s((?:light|dark|deep|space)\s)?(\S+)$/iu', $feature, $m) || !preg_match(SMARTISP_TITLE_COLOR, $m[2])) continue;
            $color = trim($m[1] . $m[2]);
            if (mb_stripos($title, $color) === false) $title .= ' ' . $color;
            break;
        }
    }
    return [$title, array_values($segments)];
}
