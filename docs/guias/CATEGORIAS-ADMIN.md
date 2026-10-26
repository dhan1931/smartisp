# Organización del catálogo

El catálogo tiene tres niveles, todos en la base de datos (la tienda ya no tiene listas escritas en el código):

- **Familia** (`category_macro_groups`): agrupa categorías en el menú lateral y en las tarjetas de la portada. Tiene nombre, imagen, orden, visibilidad y palabras clave.
- **Categoría** (`categories_rows`): pertenece a una familia (`macro_group_id`).
- **Subcategoría**: una categoría con `parent_category_id`. Solo hay dos niveles: una categoría principal no puede tener padre y una subcategoría no puede tener hijas. Una subcategoría usa siempre la familia de su principal; al cambiar la familia de la principal cambia la de sus subcategorías.

Cada producto guarda su categoría por nombre (`products.category`) y, opcionalmente, una subcategoría de texto (`products.subcategory`). Filtrar por una categoría principal incluye los productos de sus subcategorías; filtrar por una subcategoría incluye además los productos de su principal que la llevan escrita como subcategoría. Lo aplican el catálogo (`catalog?category=` y `category_list=`), `categoria.php` y `productos-destacados.php` con `smartispCategoryScope` (`api/lib/db.php`).

En Categorías, la pestaña Categorías muestra el árbol familia → categoría → subcategorías con sus conteos, un filtro "Sin familia", el buscador y la selección de varias categorías para cambiarles la familia de una vez. El diálogo de una categoría tiene "Familia", "Es subcategoría de…" y, si es principal, la sección "Subcategorías" con un buscador para agregar categorías existentes o quitarlas; se aplica al guardar. La pestaña Familias administra nombre, imagen, visibilidad, orden y palabras clave.

## Palabras clave y conversión de etiquetas

La migración `045_familias_y_subcategorias.sql` copia a `category_macro_groups.keywords` las palabras que la tienda usaba para adivinar la familia y asigna una familia a las categorías que no tenían, con el mismo orden de reglas (nombre de la familia, categorías declaradas, palabra exacta, palabra contenida; si nada coincide, "Otras Soluciones IT"). No toca las familias ya elegidas.

Las categorías nuevas que llegan al guardar o importar productos se registran con la familia que sugieren las palabras clave (si ninguna coincide quedan "Sin familia") y su subcategoría de texto se registra como subcategoría real.

Las etiquetas antiguas de texto libre (`categories_rows.subcategories`) y las subcategorías escritas en los productos se convierten en subcategorías reales con el botón "Convertir etiquetas en subcategorías", que aparece mientras quede algo por convertir (`POST admin-category-convert-tags`; con `{dryRun: true}` solo informa). Se hace en PHP y no en la migración porque necesita el mismo slug que `smartispSlug` y leer JSON sin depender de `JSON_TABLE`. No cambia la categoría de ningún producto, enlaza una categoría existente con el mismo nombre en vez de duplicarla y no mueve una que ya tenga otra principal; esas quedan listadas como "no se pudieron convertir". Repetirla no cambia nada.

## Acciones de la API

- `GET categories`: categorías (con `parentCategoryId` y `totalCount`), familias y `tagConversion: {pending, skipped}`.
- `POST admin-category-children` `{parentId, add: [ids], remove: [ids]}`: agrega o quita subcategorías en una transacción.
- `POST admin-category-family` `{ids, macroGroupId}`: cambia la familia de categorías principales y sus subcategorías; las subcategorías elegidas solas se omiten (`skipped`).
- `GET admin-categories-search?q=&for=&mode=parent|child`: búsqueda para los selectores.
- `GET catalog` (página 1): además de `categories` devuelve `macroGroups` con `keywords`.

## Imágenes distintas

- `iconImageUrl`: imagen de identificación para la tarjeta de categoría. Cuando falta se usa el icono alternativo.
- `bannerImageUrl`: cabecera horizontal de la página de categoría.
- `macroGroup.imageUrl`: imagen de la familia de la tienda.

Las tres se suben mediante el almacenamiento validado de categorías; no se reutiliza automáticamente un banner como icono.

## Guardado

`POST categories` admite `{category: {...}}` para editar una fila. La variante anterior `{categories: [...]}` sigue aceptándose como actualizaciones parciales: no elimina categorías omitidas. Se preservan los campos omitidos, se valida la familia y se escribe en una transacción. Renombrar actualiza el nombre de categoría de sus productos. Los identificadores se mantienen fijos en el editor.

`DELETE categories?id=...` rechaza categorías con productos; primero deben moverse. Una operación rechazada mantiene abierto el formulario y sus datos.

El editor de productos usa las mismas familias y categorías, consulta el filtro `family` al servidor y sugiere solo las subcategorías de la categoría elegida.

## Despliegue y verificación

Aplicar las migraciones con `php scripts/migrate.php` (incluida `045_familias_y_subcategorias.sql`). El código funciona aunque la 045 aún no esté aplicada (sin palabras clave), pero la tienda solo deja de adivinar familias cuando las categorías tienen una asignada. Después, en Categorías, pulsar una vez "Convertir etiquetas en subcategorías" y revisar las que no se pudieron convertir.

CI ejecuta pruebas de actualizaciones parciales, renombrado, preservación de categorías no editadas, imágenes independientes, rollback, filtros por familia, rechazo de borrado con productos y subida/lectura de PNG contra MariaDB aislada. La prueba de navegador local usa respuestas simuladas para comprobar móvil, escritorio, error de guardado y recuperación del formulario; no prueba las credenciales ni el esquema de producción.
