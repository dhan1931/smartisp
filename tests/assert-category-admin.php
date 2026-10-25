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
