<?php
declare(strict_types=1);
$database = getenv('MYSQL_DATABASE') ?: '';
if (!preg_match('/(?:^|_)ci$/i', $database) || !in_array(getenv('MYSQL_HOST') ?: '', ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Esta prueba solo se permite en una base local aislada terminada en _ci.');
}
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/category-admin.php';
$pdo = getDbConnection();
$table = getProductsTableName($pdo);
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$read = static function (string $id) use ($pdo): array { $stmt=$pdo->prepare('SELECT * FROM categories_rows WHERE id = ?'); $stmt->execute([$id]); return $stmt->fetch(PDO::FETCH_ASSOC) ?: []; };
$one=['id'=>'ci-category-edit','name'=>'CI categoría editable','subcategories'=>['Primera'],'macroGroupId'=>'componentes','icon'=>'cpu','iconImageUrl'=>'https://example.test/icon.webp','bannerImageUrl'=>'https://example.test/banner.webp','bannerAlt'=>'Banner CI','description'=>'Descripción CI','keywords'=>['procesador']];
$two=['id'=>'ci-category-other','name'=>'CI categoría intacta','macroGroupId'=>'redes','bannerImageUrl'=>'https://example.test/other.webp'];
try {
    saveCategoryChanges($pdo, [$one,$two]);
    $before=$read($two['id']);
    $pdo->prepare("INSERT INTO `$table` (id,name,price,category,visible) VALUES ('ci-category-product','CI producto',1,?,1)")->execute([$one['name']]);
    saveCategoryChanges($pdo, [['id'=>$one['id'],'name'=>'CI categoría renombrada']]);
    $after=$read($one['id']);
    $assert($after['icon_image_url']===$one['iconImageUrl'] && $after['banner_image_url']===$one['bannerImageUrl'], 'Editar el nombre borró una imagen.');
    $assert($after['macro_group_id']==='componentes' && $after['icon']==='cpu', 'Editar el nombre borró la familia o el icono.');
    $assert($read($two['id'])===$before, 'Editar una categoría cambió otra categoría.');
    $assert($pdo->query("SELECT category FROM `$table` WHERE id='ci-category-product'")->fetchColumn()==='CI categoría renombrada', 'Renombrar dejó productos en la categoría antigua.');
    $original=$read($one['id']);
    try { saveCategoryChanges($pdo, [['id'=>$one['id'],'name'=>'CI cambio que debe revertirse'],['id'=>'ci-category-invalid','name'=>'CI inválida','macroGroupId'=>'ci-no-existe']]); throw new RuntimeException('Debió rechazar una familia inexistente.'); }
    catch(InvalidArgumentException $error) {}
    $assert($read($one['id'])===$original && !$read('ci-category-invalid'), 'Una actualización fallida dejó cambios parciales.');
    try { saveCategoryChanges($pdo, [$one,$one]); throw new RuntimeException('Debió rechazar IDs duplicados antes de escribir.'); }
    catch(InvalidArgumentException $error) {}
    saveCategoryChanges($pdo, [['id'=>$one['id'],'name'=>$original['name'],'iconImageUrl'=>'','bannerImageUrl'=>'']]);
    $after=$read($one['id']);
    $assert($after['icon_image_url']==='' && $after['banner_image_url']==='', 'Quitar imágenes no se guardó.');
    $assert($after['macro_group_id']==='componentes', 'Quitar imágenes borró la familia.');
    echo "Categorías: guardado parcial, imágenes independientes, familia, renombrado y rollback verificados.\n";
} finally {
    $pdo->exec("DELETE FROM `$table` WHERE id='ci-category-product'");
    $pdo->exec("DELETE FROM categories_rows WHERE id IN ('ci-category-edit','ci-category-other','ci-category-invalid')");
}

$rejects = static function (callable $fn, string $message) use ($assert): void {
    try { $fn(); } catch (InvalidArgumentException $error) { return; }
    $assert(false, $message);
};
$treeIds = "'ci-tree-a','ci-tree-b','ci-tree-c','ci-tree-d','ci-tree-e','ci-etiqueta-nueva','ci-patch'";
try {
    $insert = $pdo->prepare('INSERT INTO categories_rows (id, name, subcategories, macro_group_id, parent_category_id) VALUES (?, ?, ?, ?, ?)');
    $insert->execute(['ci-tree-a', 'CI Redes Árbol', '[]', 'redes', null]);
    $insert->execute(['ci-tree-b', 'CI Switches', '[]', null, null]);
    $insert->execute(['ci-tree-c', 'CI Cables', '[]', 'perifericos', null]);
    $insert->execute(['ci-tree-d', 'CI Otra principal', '["CI Switches","CI Etiqueta Nueva","CI Hija Ajena"]', 'componentes', null]);
    $insert->execute(['ci-tree-e', 'CI Hija Ajena', '[]', 'redes', 'ci-tree-a']);
    $pdo->exec("INSERT INTO `$table` (id,name,price,category,subcategory,visible) VALUES ('ci-tree-p1','CI patch',1,'CI Redes Árbol','CI Patch',1),('ci-tree-p2','CI switch',1,'CI Switches','',1)");

    $result = assignCategoryChildren($pdo, 'ci-tree-a', ['ci-tree-b', 'ci-tree-c'], []);
    $assert($result['added'] === 2, 'No se agregaron las subcategorías.');
    $assert($read('ci-tree-b')['parent_category_id'] === 'ci-tree-a' && $read('ci-tree-b')['macro_group_id'] === 'redes' && $read('ci-tree-c')['macro_group_id'] === 'redes', 'La subcategoría no heredó la familia de su principal.');
    $rejects(fn() => assignCategoryChildren($pdo, 'ci-tree-b', ['ci-tree-d'], []), 'Debió rechazar un tercer nivel.');
    $rejects(fn() => assignCategoryChildren($pdo, 'ci-tree-b', ['ci-tree-a'], []), 'Debió rechazar un ciclo.');
    $rejects(fn() => assignCategoryChildren($pdo, 'ci-tree-a', ['ci-tree-a'], []), 'Debió rechazar que una categoría sea subcategoría de sí misma.');
    $rejects(fn() => saveCategoryChanges($pdo, [['id' => 'ci-tree-d', 'name' => 'CI Otra principal', 'parentCategoryId' => 'ci-tree-b']]), 'Guardar con una subcategoría como principal debió rechazarse.');
    $rejects(fn() => saveCategoryChanges($pdo, [['id' => 'ci-tree-a', 'name' => 'CI Redes Árbol', 'parentCategoryId' => 'ci-tree-d']]), 'Una principal con subcategorías no puede pasar a ser subcategoría.');

    saveCategoryChanges($pdo, [['id' => 'ci-tree-a', 'name' => 'CI Redes Árbol', 'macroGroupId' => 'energia']]);
    $assert($read('ci-tree-b')['macro_group_id'] === 'energia' && $read('ci-tree-e')['macro_group_id'] === 'energia', 'Cambiar la familia de la principal no llegó a sus subcategorías.');
    saveCategoryChanges($pdo, [['id' => 'ci-tree-b', 'name' => 'CI Switches', 'macroGroupId' => 'software']]);
    $assert($read('ci-tree-b')['macro_group_id'] === 'energia', 'Una subcategoría debe conservar la familia de su principal.');
    $bulk = assignCategoriesFamily($pdo, ['ci-tree-a', 'ci-tree-c'], 'redes');
    $assert($bulk['updated'] === 1 && $bulk['skipped'] === ['CI Cables'] && $read('ci-tree-b')['macro_group_id'] === 'redes', 'La familia en bloque no respetó la herencia.');

    $preview = convertCategoryTags($pdo, false);
    $assert($preview['pending'] > 0 && !$read('ci-etiqueta-nueva'), 'La vista previa de la conversión no debe escribir.');
    convertCategoryTags($pdo, true);
    $new = $read('ci-etiqueta-nueva');
    $assert($new && $new['parent_category_id'] === 'ci-tree-d' && $new['macro_group_id'] === 'componentes', 'La etiqueta no se convirtió en subcategoría real con la familia de su principal.');
    $assert(($read('ci-patch')['parent_category_id'] ?? '') === 'ci-tree-a', 'La subcategoría escrita en productos no se convirtió.');
    $assert($read('ci-tree-b')['parent_category_id'] === 'ci-tree-a' && $read('ci-tree-e')['parent_category_id'] === 'ci-tree-a', 'La conversión no debe mover una categoría que ya tiene principal.');
    $assert(json_decode($read('ci-tree-d')['subcategories'], true) === ['CI Switches', 'CI Hija Ajena'], 'La conversión debe dejar solo las etiquetas que no pudo convertir.');
    $again = convertCategoryTags($pdo, true);
    $assert($again['pending'] === 0 && !$again['created'] && !$again['linked'], 'Repetir la conversión no debe cambiar nada.');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM categories_rows WHERE name IN ('CI Etiqueta Nueva','CI Patch')")->fetchColumn() === 2, 'La conversión duplicó categorías.');
    $assert($pdo->query("SELECT category FROM `$table` WHERE id='ci-tree-p1'")->fetchColumn() === 'CI Redes Árbol', 'La conversión no debe cambiar la categoría de los productos.');

    $params = [];
    $sql = smartispCategoryScopeSql($pdo, ['CI Patch'], $params, 's');
    $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE $sql ORDER BY id");
    $stmt->execute($params);
    $assert($stmt->fetchAll(PDO::FETCH_COLUMN) === ['ci-tree-p1'], 'Filtrar por la subcategoría debe incluir los productos de la principal marcados con ella.');
    $params = [];
    $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE " . smartispCategoryScopeSql($pdo, ['CI Redes Árbol'], $params, 's') . ' ORDER BY id');
    $stmt->execute($params);
    $assert($stmt->fetchAll(PDO::FETCH_COLUMN) === ['ci-tree-p1', 'ci-tree-p2'], 'Filtrar por la principal debe incluir sus subcategorías.');

    assignCategoryChildren($pdo, 'ci-tree-a', [], ['ci-tree-c']);
    $assert($read('ci-tree-c')['parent_category_id'] === null, 'No se quitó la subcategoría.');
    echo "Árbol de categorías: subcategorías, dos niveles, ciclos, familia heredada y conversión de etiquetas verificados.\n";
} finally {
    $pdo->exec("DELETE FROM `$table` WHERE id IN ('ci-tree-p1','ci-tree-p2')");
    $pdo->exec("DELETE FROM categories_rows WHERE id IN ($treeIds)");
}
