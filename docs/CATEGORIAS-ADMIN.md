# Organización del catálogo

El panel distingue familia, categoría y subcategoría. Una familia agrupa categorías en el menú; cada producto mantiene su categoría y su subcategoría.

En Categorías, la pestaña Categorías permite buscar, filtrar por familia, editar una categoría y abrir sus productos. La pestaña Familias administra nombre, imagen, visibilidad y orden. Las acciones de mover y eliminar están en el menú de cada categoría.

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

Aplicar las migraciones con `php scripts/migrate.php`, incluyendo `023_category_card_images.sql`, antes de publicar el nuevo editor. Las credenciales siguen en el entorno del servidor. No es necesario recargar el catálogo ni reconstruir categorías.

CI ejecuta pruebas de actualizaciones parciales, renombrado, preservación de categorías no editadas, imágenes independientes, rollback, filtros por familia, rechazo de borrado con productos y subida/lectura de PNG contra MariaDB aislada. La prueba de navegador local usa respuestas simuladas para comprobar móvil, escritorio, error de guardado y recuperación del formulario; no prueba las credenciales ni el esquema de producción.
