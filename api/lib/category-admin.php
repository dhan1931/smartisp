<?php
declare(strict_types=1);

/** Actualiza filas existentes sin borrar las demas categorias ni sus metadatos. */
function saveCategoryChanges(PDO $pdo, array $changes): void {
    $seen = [];
    foreach ($changes as $category) {
        if (!is_array($category)) throw new InvalidArgumentException('La categoría no es válida.');
        $id = trim((string)($category['id'] ?? ''));
        $name = trim((string)($category['name'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $id) || $name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('Escribe un nombre y un identificador válido para la categoría.');
        }
        if (isset($seen[$id])) throw new InvalidArgumentException('Hay categorías con el mismo identificador. Guarda cada categoría por separado.');
        $seen[$id] = true;
        foreach (['iconImageUrl', 'bannerImageUrl'] as $field) {
            $url = trim((string)($category[$field] ?? ''));
            if ($url !== '' && !storefrontCampaignSafeUrl($url, true)) throw new InvalidArgumentException('La imagen debe ser una subida de la tienda o una dirección HTTPS válida.');
        }
        if (mb_strlen((string)($category['description'] ?? '')) > 500 || mb_strlen((string)($category['bannerAlt'] ?? '')) > 200) {
            throw new InvalidArgumentException('La descripción o el texto de la imagen supera el límite permitido.');
        }
    }
    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT * FROM categories_rows WHERE id = :id FOR UPDATE');
        $duplicate = $pdo->prepare('SELECT id FROM categories_rows WHERE LOWER(TRIM(name)) = LOWER(:name) AND id <> :id LIMIT 1');
        $group = $pdo->prepare('SELECT id FROM category_macro_groups WHERE id = :id');
        $rows = categoryTreeRows($pdo, true);
        $propagate = $pdo->prepare('UPDATE categories_rows SET macro_group_id = :family WHERE parent_category_id = :id');
        $save = $pdo->prepare('INSERT INTO categories_rows (id, name, subcategories, banner_image_url, banner_alt, description, icon, icon_image_url, keywords, macro_group_id, parent_category_id)
            VALUES (:id, :name, :subs, :banner, :alt, :description, :icon, :image, :keywords, :family, :parent)
            ON DUPLICATE KEY UPDATE name = VALUES(name), subcategories = VALUES(subcategories), banner_image_url = VALUES(banner_image_url), banner_alt = VALUES(banner_alt), description = VALUES(description), icon = VALUES(icon), icon_image_url = VALUES(icon_image_url), keywords = VALUES(keywords), macro_group_id = VALUES(macro_group_id), parent_category_id = VALUES(parent_category_id)');
        $table = getProductsTableName($pdo);
        $rename = $pdo->prepare("UPDATE `$table` SET category = :newName WHERE category = :oldName");
        $renameTag = $pdo->prepare("UPDATE `$table` SET subcategory = :newName WHERE subcategory = :oldName AND category = :parent");
        foreach ($changes as $category) {
            $id = trim((string)$category['id']);
            $name = trim((string)$category['name']);
            $find->execute([':id' => $id]);
            $old = $find->fetch(PDO::FETCH_ASSOC) ?: [];
            $duplicate->execute([':name' => $name, ':id' => $id]);
            if ($duplicate->fetchColumn()) throw new InvalidArgumentException('Ya existe otra categoría con ese nombre.');
            $parent = trim((string)($category['parentCategoryId'] ?? $old['parent_category_id'] ?? ''));
            $family = trim((string)($category['macroGroupId'] ?? $old['macro_group_id'] ?? ''));
            if ($parent !== '') {
                $rows[$id] ??= ['id' => $id, 'name' => $name, 'macro_group_id' => '', 'parent_category_id' => '', 'subcategories' => ''];
                categoryTreeCheckLink($rows, $id, $parent);
                if ($rows[$parent]['macro_group_id'] !== '') $family = $rows[$parent]['macro_group_id'];
            }
            if ($family !== '') {
                $group->execute([':id' => $family]);
                if (!$group->fetchColumn()) throw new InvalidArgumentException('La familia elegida ya no existe. Selecciona otra.');
            }
            $list = static function ($value): string {
                if (!is_array($value)) $value = preg_split('/[\n,]+/', (string)$value);
                return json_encode(array_values(array_unique(array_filter(array_map(static fn($item) => trim((string)$item), $value)))), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            };
            $save->execute([
                ':id' => $id, ':name' => $name,
                ':subs' => $list($category['subcategories'] ?? json_decode($old['subcategories'] ?? '[]', true) ?? []),
                ':banner' => trim((string)($category['bannerImageUrl'] ?? $old['banner_image_url'] ?? '')),
                ':alt' => (string)($category['bannerAlt'] ?? $old['banner_alt'] ?? ''),
                ':description' => (string)($category['description'] ?? $old['description'] ?? ''),
                ':icon' => preg_match('/^[a-z0-9-]{1,64}$/', (string)($category['icon'] ?? '')) ? $category['icon'] : ($old['icon'] ?? 'package'),
                ':image' => trim((string)($category['iconImageUrl'] ?? $old['icon_image_url'] ?? '')),
                ':keywords' => $list($category['keywords'] ?? json_decode($old['keywords'] ?? '[]', true) ?? []),
                ':family' => $family !== '' ? $family : null,
                ':parent' => $parent !== '' ? $parent : null,
            ]);
            $rows[$id] = ['id' => $id, 'name' => $name, 'macro_group_id' => $family, 'parent_category_id' => $parent, 'subcategories' => $rows[$id]['subcategories'] ?? ''];
            if ($parent === '') $propagate->execute([':family' => $family !== '' ? $family : null, ':id' => $id]);
            if ($old && $old['name'] !== $name) {
                $rename->execute([':newName' => $name, ':oldName' => $old['name']]);
                if ($parent !== '') $renameTag->execute([':newName' => $name, ':oldName' => $old['name'], ':parent' => $rows[$parent]['name']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

// Árbol de categorías: familia → categoría principal → subcategoría (dos niveles como máximo).
function categoryTreeRows(PDO $pdo, bool $lock = false): array {
    $rows = [];
    $stmt = $pdo->query('SELECT id, name, subcategories, macro_group_id, parent_category_id FROM categories_rows' . ($lock ? ' FOR UPDATE' : ''));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (string)$row['id'];
        $rows[$id] = ['id' => $id, 'name' => trim((string)$row['name']), 'subcategories' => (string)($row['subcategories'] ?? ''), 'macro_group_id' => trim((string)($row['macro_group_id'] ?? '')), 'parent_category_id' => trim((string)($row['parent_category_id'] ?? ''))];
    }
    return $rows;
}

function categoryTreeChildren(array $rows, string $id): array {
    return array_values(array_filter($rows, static fn($row) => $row['parent_category_id'] === $id));
}

function categoryTreeCheckLink(array $rows, string $childId, string $parentId): void {
    if (!isset($rows[$parentId])) throw new InvalidArgumentException('La categoría principal elegida ya no existe. Recarga la página.');
    if (!isset($rows[$childId])) throw new InvalidArgumentException('Una de las categorías ya no existe. Recarga la página.');
    $child = $rows[$childId]['name'];
    $parent = $rows[$parentId];
    if ($childId === $parentId) throw new InvalidArgumentException("«{$child}» no puede ser subcategoría de sí misma.");
    if ($parent['parent_category_id'] !== '') {
        $grand = $rows[$parent['parent_category_id']]['name'] ?? 'otra categoría';
        throw new InvalidArgumentException("«{$parent['name']}» ya es subcategoría de «{$grand}». Solo hay dos niveles: elige una categoría principal.");
    }
    if (categoryTreeChildren($rows, $childId)) throw new InvalidArgumentException("«{$child}» tiene sus propias subcategorías, así que no puede ser subcategoría de otra.");
}

function categoryTreeIds($value): array {
    if (!is_array($value)) return [];
    $ids = array_values(array_unique(array_filter(array_map(static fn($id) => trim((string)$id), $value), 'strlen')));
    if (count($ids) > 500) throw new InvalidArgumentException('Son demasiadas categorías a la vez. Hazlo por partes.');
    return $ids;
}

/** Agrega y quita subcategorías de una categoría principal en una sola transacción. */
function assignCategoryChildren(PDO $pdo, string $parentId, array $add, array $remove): array {
    $add = categoryTreeIds($add);
    $remove = categoryTreeIds($remove);
    if ($parentId === '') throw new InvalidArgumentException('Elige la categoría principal.');
    $pdo->beginTransaction();
    try {
        $rows = categoryTreeRows($pdo, true);
        if (!isset($rows[$parentId])) throw new InvalidArgumentException('La categoría principal ya no existe. Recarga la página.');
        $unlink = $pdo->prepare('UPDATE categories_rows SET parent_category_id = NULL WHERE id = :id AND parent_category_id = :parent');
        $link = $pdo->prepare('UPDATE categories_rows SET parent_category_id = :parent, macro_group_id = COALESCE(:family, macro_group_id) WHERE id = :id');
        $removed = 0;
        foreach ($remove as $id) {
            if (($rows[$id]['parent_category_id'] ?? '') !== $parentId) continue;
            $unlink->execute([':id' => $id, ':parent' => $parentId]);
            $rows[$id]['parent_category_id'] = '';
            $removed++;
        }
        $added = 0;
        $family = $rows[$parentId]['macro_group_id'];
        foreach ($add as $id) {
            if (($rows[$id]['parent_category_id'] ?? '') === $parentId) continue;
            categoryTreeCheckLink($rows, $id, $parentId);
            $link->execute([':parent' => $parentId, ':family' => $family !== '' ? $family : null, ':id' => $id]);
            $rows[$id]['parent_category_id'] = $parentId;
            if ($family !== '') $rows[$id]['macro_group_id'] = $family;
            $added++;
        }
        $pdo->commit();
        return ['added' => $added, 'removed' => $removed];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Cambia la familia de varias categorías principales y de sus subcategorías. */
function assignCategoriesFamily(PDO $pdo, array $ids, string $family): array {
    $ids = categoryTreeIds($ids);
    if (!$ids) throw new InvalidArgumentException('Selecciona al menos una categoría.');
    if ($family !== '') {
        $group = $pdo->prepare('SELECT id FROM category_macro_groups WHERE id = :id');
        $group->execute([':id' => $family]);
        if (!$group->fetchColumn()) throw new InvalidArgumentException('La familia elegida ya no existe. Selecciona otra.');
    }
    $pdo->beginTransaction();
    try {
        $rows = categoryTreeRows($pdo, true);
        $update = $pdo->prepare('UPDATE categories_rows SET macro_group_id = :family WHERE id = :id OR parent_category_id = :parent');
        $updated = 0;
        $skipped = [];
        foreach ($ids as $id) {
            if (!isset($rows[$id])) continue;
            if ($rows[$id]['parent_category_id'] !== '') { $skipped[] = $rows[$id]['name']; continue; }
            $update->execute([':family' => $family !== '' ? $family : null, ':id' => $id, ':parent' => $id]);
            $updated++;
        }
        $pdo->commit();
        return ['updated' => $updated, 'skipped' => $skipped];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function categoryTagList(string $raw): array {
    if (trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    $list = is_array($decoded) ? $decoded : explode(',', $raw);
    return array_values(array_unique(array_filter(array_map(static fn($tag) => trim((string)$tag), $list), 'strlen')));
}

function categoryTreeNewId(array $rows, string $name, string $parentName = ''): string {
    $base = smartispSlug($name, 80, 'categoria');
    if (!isset($rows[$base])) return $base;
    if ($parentName !== '') {
        $withParent = smartispSlug("$parentName $name", 80, 'categoria');
        if (!isset($rows[$withParent])) return $withParent;
    }
    for ($i = 2; isset($rows["$base-$i"]); $i++);
    return "$base-$i";
}

/** Filas, índice por nombre normalizado y familias, para convertir etiquetas en memoria. */
function categoryTreeContext(PDO $pdo, bool $lock): array {
    $rows = categoryTreeRows($pdo, $lock);
    $byKey = [];
    foreach ($rows as $row) $byKey[smartispCategoryKey($row['name'])] ??= $row['id'];
    return ['rows' => $rows, 'byKey' => $byKey, 'groups' => getCategoryMacroGroups($pdo)];
}

function categoryTreeEnsureCategory(PDO $pdo, array &$ctx, string $name, bool $apply): string {
    $key = smartispCategoryKey($name);
    if (isset($ctx['byKey'][$key])) return (string)$ctx['byKey'][$key];
    $id = categoryTreeNewId($ctx['rows'], $name);
    $family = smartispGuessMacroGroup($ctx['groups'], $name);
    if ($apply) $pdo->prepare('INSERT INTO categories_rows (id, name, subcategories, macro_group_id) VALUES (:id, :name, :subs, :family)')->execute([':id' => $id, ':name' => $name, ':subs' => '[]', ':family' => $family !== '' ? $family : null]);
    $ctx['rows'][$id] = ['id' => $id, 'name' => $name, 'subcategories' => '[]', 'macro_group_id' => $family, 'parent_category_id' => ''];
    $ctx['byKey'][$key] = $id;
    return $id;
}

/** Convierte la etiqueta $tag de $parentName en subcategoría real. 'done' o el motivo para no tocarla. */
function categoryTreeLinkTag(PDO $pdo, array &$ctx, string $parentName, string $tag, bool $apply, array &$result): string {
    $tag = trim($tag);
    $parentName = trim($parentName);
    if ($tag === '' || smartispCategoryKey($tag) === smartispCategoryKey($parentName)) return 'done';
    $parentId = categoryTreeEnsureCategory($pdo, $ctx, $parentName, $apply);
    $parent = $ctx['rows'][$parentId];
    if ($parent['parent_category_id'] !== '') return "«{$parent['name']}» ya es subcategoría y solo hay dos niveles";
    $family = $parent['macro_group_id'] !== '' ? $parent['macro_group_id'] : null;
    $childId = $ctx['byKey'][smartispCategoryKey($tag)] ?? null;
    if ($childId !== null) {
        $childId = (string)$childId;
        $child = $ctx['rows'][$childId];
        if ($child['parent_category_id'] === $parentId) return 'done';
        if ($child['parent_category_id'] !== '') return 'ya es subcategoría de «' . ($ctx['rows'][$child['parent_category_id']]['name'] ?? 'otra categoría') . '»';
        if (categoryTreeChildren($ctx['rows'], $childId)) return 'tiene sus propias subcategorías';
        if ($apply) $pdo->prepare('UPDATE categories_rows SET parent_category_id = :parent, macro_group_id = COALESCE(:family, macro_group_id) WHERE id = :id')->execute([':parent' => $parentId, ':family' => $family, ':id' => $childId]);
        $ctx['rows'][$childId]['parent_category_id'] = $parentId;
        if ($family !== null) $ctx['rows'][$childId]['macro_group_id'] = $family;
        $result['linked'][] = "{$child['name']} → {$parent['name']}";
        return 'done';
    }
    $childId = categoryTreeNewId($ctx['rows'], $tag, $parent['name']);
    if ($apply) $pdo->prepare('INSERT INTO categories_rows (id, name, subcategories, macro_group_id, parent_category_id) VALUES (:id, :name, :subs, :family, :parent)')->execute([':id' => $childId, ':name' => $tag, ':subs' => '[]', ':family' => $family, ':parent' => $parentId]);
    $ctx['rows'][$childId] = ['id' => $childId, 'name' => $tag, 'subcategories' => '[]', 'macro_group_id' => (string)$family, 'parent_category_id' => $parentId];
    $ctx['byKey'][smartispCategoryKey($tag)] = $childId;
    $result['created'][] = "$tag → {$parent['name']}";
    return 'done';
}

/**
 * Convierte las etiquetas de texto libre (categories_rows.subcategories) y las subcategorías
 * escritas en los productos en subcategorías reales, sin cambiar products.category ni duplicar
 * categorías. Con $apply = false solo informa lo que haría. Repetirla no cambia nada.
 */
function convertCategoryTags(PDO $pdo, bool $apply): array {
    $result = ['created' => [], 'linked' => [], 'cleaned' => 0, 'skipped' => [], 'pending' => 0];
    if ($apply) $pdo->beginTransaction();
    try {
        $ctx = categoryTreeContext($pdo, $apply);
        $pairs = [];
        foreach ($ctx['rows'] as $row) foreach (categoryTagList($row['subcategories']) as $tag) $pairs[] = [$row['name'], $tag, $row['id']];
        $table = getProductsTableName($pdo);
        $products = $pdo->query("SELECT DISTINCT TRIM(category) AS category, TRIM(subcategory) AS subcategory FROM `$table` WHERE TRIM(COALESCE(category, '')) <> '' AND TRIM(COALESCE(subcategory, '')) <> ''");
        foreach ($products->fetchAll(PDO::FETCH_ASSOC) as $pair) $pairs[] = [$pair['category'], $pair['subcategory'], null];
        $original = array_map(static fn($row) => categoryTagList($row['subcategories']), $ctx['rows']);
        $remaining = [];
        $skipped = [];
        foreach ($pairs as [$parentName, $tag, $rowId]) {
            $outcome = categoryTreeLinkTag($pdo, $ctx, $parentName, $tag, $apply, $result);
            if ($outcome === 'done') continue;
            $skipped[smartispCategoryKey($parentName) . '|' . smartispCategoryKey($tag)] = ['category' => $parentName, 'tag' => $tag, 'reason' => $outcome];
            if ($rowId !== null) $remaining[$rowId][] = $tag;
        }
        $clean = $pdo->prepare('UPDATE categories_rows SET subcategories = :subs WHERE id = :id');
        foreach ($original as $id => $tags) {
            $keep = array_values(array_unique($remaining[$id] ?? []));
            if (count($tags) === count($keep)) continue;
            $result['cleaned'] += count($tags) - count($keep);
            if ($apply) $clean->execute([':subs' => json_encode($keep, JSON_UNESCAPED_UNICODE), ':id' => (string)$id]);
        }
        $result['skipped'] = array_values($skipped);
        $result['pending'] = count($result['created']) + count($result['linked']) + $result['cleaned'];
        if ($apply) $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Registra la categoría de un producto y, si trae subcategoría, la deja como subcategoría real. */
function categoryTreeEnsurePair(PDO $pdo, string $category, string $subcategory): void {
    $ctx = categoryTreeContext($pdo, false);
    $result = ['created' => [], 'linked' => []];
    categoryTreeEnsureCategory($pdo, $ctx, $category, true);
    if (trim($subcategory) !== '') categoryTreeLinkTag($pdo, $ctx, $category, $subcategory, true, $result);
}

/** Selectores del panel. 'parent': posibles categorías principales de $forId; 'child': posibles subcategorías de $forId. */
function searchCategoriesForTree(PDO $pdo, string $query, string $forId, string $mode, int $limit = 20): array {
    $rows = categoryTreeRows($pdo);
    $key = smartispCategoryKey($query);
    $hasChildren = [];
    foreach ($rows as $row) if ($row['parent_category_id'] !== '') $hasChildren[$row['parent_category_id']] = true;
    $forIsChild = ($rows[$forId]['parent_category_id'] ?? '') !== '';
    $out = [];
    foreach ($rows as $row) {
        if ($row['id'] === $forId || ($key !== '' && !str_contains(smartispCategoryKey($row['name']), $key))) continue;
        if ($mode === 'parent' && ($row['parent_category_id'] !== '' || isset($hasChildren[$forId]))) continue;
        if ($mode === 'child' && ($forIsChild || isset($hasChildren[$row['id']]) || $row['parent_category_id'] === $forId)) continue;
        $out[] = ['id' => $row['id'], 'name' => $row['name'], 'macroGroupId' => $row['macro_group_id'], 'parentCategoryId' => $row['parent_category_id'], 'parentName' => $rows[$row['parent_category_id']]['name'] ?? ''];
    }
    usort($out, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return array_slice($out, 0, max(1, min(50, $limit)));
}
