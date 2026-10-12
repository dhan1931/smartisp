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
        $parentLookup = $pdo->prepare('SELECT id, parent_category_id FROM categories_rows WHERE id = :id');
        $save = $pdo->prepare('INSERT INTO categories_rows (id, name, subcategories, banner_image_url, banner_alt, description, icon, icon_image_url, keywords, macro_group_id, parent_category_id)
            VALUES (:id, :name, :subs, :banner, :alt, :description, :icon, :image, :keywords, :family, :parent)
            ON DUPLICATE KEY UPDATE name = VALUES(name), subcategories = VALUES(subcategories), banner_image_url = VALUES(banner_image_url), banner_alt = VALUES(banner_alt), description = VALUES(description), icon = VALUES(icon), icon_image_url = VALUES(icon_image_url), keywords = VALUES(keywords), macro_group_id = VALUES(macro_group_id), parent_category_id = VALUES(parent_category_id)');
        $table = getProductsTableName($pdo);
        $rename = $pdo->prepare("UPDATE `$table` SET category = :newName WHERE category = :oldName");
        // Una categoria no puede ser su propia ancestra -- recorre la cadena de padres del
        // candidato elegido y si en algun punto aparece $id, el enlace formaria un ciclo.
        $wouldCreateCycle = static function (string $id, string $candidateParent) use ($parentLookup): bool {
            $current = $candidateParent;
            $guard = 0;
            while ($current !== '' && $guard < 50) {
                if ($current === $id) return true;
                $parentLookup->execute([':id' => $current]);
                $row = $parentLookup->fetch(PDO::FETCH_ASSOC);
                $current = $row ? trim((string)($row['parent_category_id'] ?? '')) : '';
                $guard++;
            }
            return false;
        };
        foreach ($changes as $category) {
            $id = trim((string)$category['id']);
            $name = trim((string)$category['name']);
            $find->execute([':id' => $id]);
            $old = $find->fetch(PDO::FETCH_ASSOC) ?: [];
            $duplicate->execute([':name' => $name, ':id' => $id]);
            if ($duplicate->fetchColumn()) throw new InvalidArgumentException('Ya existe otra categoría con ese nombre.');
            $family = trim((string)($category['macroGroupId'] ?? $old['macro_group_id'] ?? ''));
            if ($family !== '') {
                $group->execute([':id' => $family]);
                if (!$group->fetchColumn()) throw new InvalidArgumentException('La familia elegida ya no existe. Selecciona otra.');
            }
            $parent = trim((string)($category['parentCategoryId'] ?? $old['parent_category_id'] ?? ''));
            if ($parent !== '') {
                if ($parent === $id) throw new InvalidArgumentException('Una categoría no puede ser subcategoría de sí misma.');
                $find->execute([':id' => $parent]);
                if (!$find->fetch(PDO::FETCH_ASSOC)) throw new InvalidArgumentException('La categoría padre elegida ya no existe. Selecciona otra.');
                if ($wouldCreateCycle($id, $parent)) throw new InvalidArgumentException('Esa categoría padre crearía un ciclo (ya es descendiente de esta categoría).');
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
            if ($old && $old['name'] !== $name) $rename->execute([':newName' => $name, ':oldName' => $old['name']]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
