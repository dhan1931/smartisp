<?php
// Descarga de imágenes de terceros para la acción product-image/proxy-image (handlers/products.php).
// El proxy acepta URLs que llegan desde el navegador, así que cada salto (URL inicial y cada
// redirección) se valida: solo http/https, solo IPs públicas (y la conexión se fija a la IP ya
// validada, para que un DNS que cambia entre la comprobación y la descarga no lleve a la red
// interna) y solo imágenes rasterizadas verificadas por su contenido. Un SVG o un HTML servido
// desde nuestro dominio podría ejecutar scripts con la sesión del panel.

const SMARTISP_PROXY_IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
    'image/avif' => 'avif',
];
const SMARTISP_PROXY_MAX_BYTES = 15 * 1024 * 1024;
const SMARTISP_PROXY_MAX_REDIRECTS = 3;

function smartispIsPublicIp(string $ip): bool {
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (defined('FILTER_FLAG_GLOBAL_RANGE')) $flags |= FILTER_FLAG_GLOBAL_RANGE;
    if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) return false;
    // IPv4 embebida en IPv6 (::ffff:10.0.0.1), multicast y CGNAT 100.64.0.0/10, por si el filtro de PHP no los cubre.
    if (preg_match('/^(::ffff:|ff)/i', $ip)) return false;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        if (($long & 0xFFC00000) === ip2long('100.64.0.0') || ($long & 0xF0000000) === ip2long('224.0.0.0')) return false;
    }
    return true;
}

// Valida la URL y devuelve [esquema, host, puerto, ip] o null si no se puede usar.
function smartispResolvePublicUrl(string $url): ?array {
    if ($url === '' || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) return null;
    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, [80, 443, 8080, 8443], true)) return null;

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = @gethostbynamel($host) ?: [];
        if (!$ips && function_exists('dns_get_record')) {
            foreach ((@dns_get_record($host, DNS_AAAA) ?: []) as $record) {
                if (!empty($record['ipv6'])) $ips[] = $record['ipv6'];
            }
        }
    }
    if (!$ips) return null;
    // Si alguna de las IPs del nombre es interna se rechaza el nombre completo.
    foreach ($ips as $ip) {
        if (!smartispIsPublicIp($ip)) return null;
    }
    return [$scheme, $host, $port, $ips[0]];
}

// Tipo real de la imagen según sus bytes, o null si no es una de las admitidas.
function smartispDetectImageType(string $data): ?string {
    $info = @getimagesizefromstring($data);
    $mime = strtolower((string)($info['mime'] ?? ''));
    if ($mime === '' && class_exists('finfo')) {
        $mime = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->buffer($data));
    }
    return isset(SMARTISP_PROXY_IMAGE_TYPES[$mime]) ? $mime : null;
}

// Descarga la imagen siguiendo a mano hasta 3 redirecciones, validando cada destino.
// Devuelve los bytes o null.
function smartispFetchRemoteImage(string $url): ?string {
    for ($hop = 0; $hop <= SMARTISP_PROXY_MAX_REDIRECTS; $hop++) {
        $target = smartispResolvePublicUrl($url);
        if ($target === null) return null;
        [$scheme, $host, $port, $ip] = $target;
        $referer = $scheme . '://' . $host . '/';
        $location = null;
        $status = 0;
        $data = null;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_MAXFILESIZE    => SMARTISP_PROXY_MAX_BYTES,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                CURLOPT_REFERER        => $referer,
                CURLOPT_HTTPHEADER     => [
                    'Accept: image/avif,image/webp,image/png,image/jpeg,image/gif;q=0.9,*/*;q=0.8',
                    'Accept-Language: es-EC,es;q=0.9,en;q=0.8'
                ],
            ]);
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
            curl_close($ch);
            $data = is_string($response) ? $response : null;
        } else {
            // Sin curl: conexión directa a la IP validada, con el Host y el SNI del nombre original.
            $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
            $query = parse_url($url, PHP_URL_QUERY);
            $hostForUrl = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $directUrl = $scheme . '://' . $hostForUrl . ':' . $port . $path . ($query !== null ? '?' . $query : '');
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "Host: $host\r\nUser-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\nReferer: $referer\r\nAccept: image/avif,image/webp,image/png,image/jpeg,image/gif;q=0.9\r\n",
                    'timeout' => 8,
                    'follow_location' => 0,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'peer_name' => $host,
                    'verify_peer' => !smartispInsecureTls(),
                    'verify_peer_name' => !smartispInsecureTls(),
                ],
            ]);
            $response = @file_get_contents($directUrl, false, $context, 0, SMARTISP_PROXY_MAX_BYTES + 1);
            $headers = $http_response_header ?? [];
            foreach ($headers as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int)$m[1];
                    $location = null;
                } elseif (preg_match('/^Location:\s*(.+)$/i', $line, $m)) {
                    $location = trim($m[1]);
                }
            }
            if ($location !== null && !preg_match('#^https?://#i', $location)) {
                $location = str_starts_with($location, '/') ? $scheme . '://' . $host . ($port !== ($scheme === 'https' ? 443 : 80) ? ':' . $port : '') . $location : null;
            }
            $data = is_string($response) ? $response : null;
        }

        if ($status >= 300 && $status < 400 && $location) {
            $url = $location;
            continue;
        }
        if ($status < 200 || $status >= 300 || $data === null || $data === '' || strlen($data) > SMARTISP_PROXY_MAX_BYTES) return null;
        return $data;
    }
    return null;
}

// Borra de vez en cuando (1 de cada 200 peticiones que descargan) lo que lleve más de 30 días
// en la caché, con un tope por pasada para no alargar la respuesta.
function smartispPruneImageCache(string $cacheDir): void {
    if (random_int(1, 200) !== 1) return;
    $limit = time() - 86400 * 30;
    $checked = 0;
    foreach (new DirectoryIterator($cacheDir) as $file) {
        if (!$file->isFile()) continue;
        if ($file->getMTime() < $limit) @unlink($file->getPathname());
        if (++$checked >= 500) break;
    }
}
