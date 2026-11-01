<?php
// Búsqueda del catálogo público: cada palabra debe aparecer en el nombre, SKU o categoría, en cualquier
// orden y sin importar guiones o puntos («tp link router» encuentra «Router TP-Link»).
require_once __DIR__ . '/brands.php';

const SMARTISP_CATALOG_COMPACT_NAME = "REPLACE(REPLACE(REPLACE(REPLACE(LOWER(name), '-', ''), ' ', ''), '.', ''), '/', '')";

// Nombres con que los proveedores escriben algunas marcas.
const SMARTISP_BRAND_ALIASES = ['HP' => ['hpinc', 'hpe'], 'A-Data' => ['adata'], 'TP-Link' => ['tplink'], 'D-Link' => ['dlink'], 'Nexxt Solutions' => ['nexxt']];

function smartispCatalogTokens(string $search): array {
    $words = preg_split('/[\s,;]+/u', mb_strtolower(trim($search)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $tokens = [];
    foreach ($words as $word) {
        $word = trim($word, " \t\"'()[]{}");
        if ($word === '' || (mb_strlen($word) < 2 && !ctype_digit($word))) continue;
        $tokens[$word] = true;
        if (count($tokens) >= 6) break;
    }
    return array_keys($tokens);
}

// Singular sencillo para que «cámaras» y «routers» encuentren «cámara» y «router».
function smartispCatalogSingular(string $token): string {
    if (mb_strlen($token) > 4 && preg_match('/(ces)$/u', $token)) return mb_substr($token, 0, -3) . 'z';
    if (mb_strlen($token) > 4 && preg_match('/[^aeiou]es$/u', $token)) return mb_substr($token, 0, -2);
    if (mb_strlen($token) > 3 && str_ends_with($token, 's')) return mb_substr($token, 0, -1);
    return $token;
}

function smartispCatalogLike(string $value): string {
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}

// Devuelve [condición, orden por relevancia] y llena $params. Placeholders distintos por uso: con
// prepares nativos un nombre repetido da HY093.
function smartispCatalogSearchSql(string $search, array &$params): array {
    $tokens = smartispCatalogTokens($search);
    $parts = [];
    foreach ($tokens as $i => $token) {
        $forms = array_unique([$token, smartispCatalogSingular($token)]);
        $or = [];
        foreach (array_values($forms) as $j => $form) {
            foreach (['name', 'sku', 'category', 'subcategory'] as $column) {
                $key = ":s{$i}_{$j}_$column";
                $or[] = "$column LIKE $key";
                $params[$key] = smartispCatalogLike($form);
            }
            $compact = smartispBrandCompact($form);
            if (strlen($compact) >= 2) {
                $or[] = SMARTISP_CATALOG_COMPACT_NAME . " LIKE :s{$i}_{$j}_compact";
                $params[":s{$i}_{$j}_compact"] = smartispCatalogLike($compact);
            }
        }
        $parts[] = '(' . implode(' OR ', $or) . ')';
    }
    $params[':search_id'] = $search;
    $params[':search_sku'] = $search;
    $where = '(id = :search_id OR sku = :search_sku' . ($parts ? ' OR (' . implode(' AND ', $parts) . ')' : '') . ')';

    $params[':rank_id'] = $search;
    $params[':rank_sku'] = $search;
    $params[':rank_start'] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
    $params[':rank_phrase'] = smartispCatalogLike($search);
    $rank = 'CASE WHEN id = :rank_id OR sku = :rank_sku THEN 0 WHEN name LIKE :rank_start THEN 1 WHEN name LIKE :rank_phrase THEN 2 ELSE 3 END';
    return [$where, $rank];
}

function smartispBrandPattern(string $brand): string {
    $variants = [implode('[- .]?', array_map(static fn($w) => preg_quote($w, '/'), preg_split('/[^a-z0-9]+/', smartispSlug($brand, 120, ''), -1, PREG_SPLIT_NO_EMPTY)))];
    foreach (SMARTISP_BRAND_ALIASES[$brand] ?? [] as $alias) $variants[] = preg_quote($alias, '/');
    return '(^|[^a-z0-9])(' . implode('|', $variants) . ')([^a-z0-9]|$)';
}

function smartispFindBrand(string $value): ?string {
    $key = smartispBrandCompact($value);
    foreach (SMARTISP_BRANDS as $brand) {
        if (smartispBrandCompact($brand) === $key) return $brand;
    }
    return null;
}

// Marcas presentes en un conjunto de nombres, de la más frecuente a la menos.
function smartispBrandFacets(array $names, int $max = 12): array {
    static $patterns = null;
    if ($patterns === null) {
        $patterns = [];
        foreach (SMARTISP_BRANDS as $brand) $patterns[$brand] = '/' . smartispBrandPattern($brand) . '/';
    }
    $counts = [];
    foreach ($names as $name) {
        $text = strtolower(smartispSlug((string)$name, 300, ''));
        $text = str_replace('-', ' ', $text);
        foreach ($patterns as $brand => $pattern) {
            if (preg_match($pattern, $text)) $counts[$brand] = ($counts[$brand] ?? 0) + 1;
        }
    }
    arsort($counts);
    $out = [];
    foreach (array_slice($counts, 0, $max, true) as $brand => $count) $out[] = ['name' => $brand, 'count' => $count];
    return $out;
}
