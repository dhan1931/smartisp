# Seguimiento de tienda y administración

Registro vivo de requerimientos, entregas y dependencias. Agregar cada nuevo pedido aquí antes de implementarlo, actualizar el estado al terminar y no marcar como desplegado sin comprobar producción.

Última revisión: 2026-10-09

## Hecho y desplegado

| ID | Requerimiento | Estado / evidencia |
| --- | --- | --- |
| PUB-01 | Configurar densidad de productos y mostrar cuatro columnas en escritorio, con proporción de imagen adecuada. | Desplegado. PR #5; invalidación de caché en PR #6. La opción se gestiona desde configuración. |
| PUB-02 | Hacer más grande la imagen/miniatura de la ficha de producto. | Desplegado. PR #7. |
| ADM-01 | Editor de campañas separado de las pestañas del editor de catálogo. | Desplegado como pantalla `campanas.html`, PR #8. |
| ADM-02 | Mostrar el acceso a campañas en el menú de administración y corregir la caché del menú. | Desplegado y verificado en `main`, PR #10. Etiqueta actual: “Campañas y banners”. |
| PUB-03 | Páginas públicas de categorías destacadas y productos destacados, banners de categorías y actualización inicial del logo público. | Desplegado en PRs #3, #4 y #11. El logo canónico ahora es `assets/img/logo.webp`; la centralización completa de todas las cabeceras sigue pendiente. |

## Pendiente / en curso

| ID | Requerimiento | Estado / siguiente paso |
| --- | --- | --- |
| PUB-04 | Separar Catálogo, Productos y Categorías en pantallas independientes. | **No implementado como tres pantallas nuevas.** Hay páginas públicas de categorías/productos destacados y una página de catálogo, pero no son una separación completa del área administrativa. Acordar el alcance de cada pantalla antes de construirla. |
| PUB-05 | Unificar la cabecera pública, navegación móvil y logo para todas las páginas; actualizar una sola implementación y evitar logos distintos/rotos. | Parcial. PR #11 fija `assets/img/logo.webp` y evita fallback al logo legado en las páginas públicas. Aún falta centralizar el markup/estilos completos y validar todas las cabeceras en móvil y escritorio. |
| PUB-06 | Hacer coherente el diseño de tarjetas e imágenes del listado entre catálogo y páginas comerciales/categorías. | Parcial. Densidad e imagen de ficha se desplegaron; falta validar que las tarjetas públicas tengan una presentación compartida y consistente. |
| PUB-07 | En móvil, abrir desde la izquierda un drawer blanco de categorías (aprox. 80% del ancho), sin los enlaces de secciones generales; incluir búsqueda, conteos y subcategorías y aplicarlo uniformemente a páginas públicas. | Pendiente de auditoría/implementación. La plantilla compartida actual aún incluye enlaces generales en la navegación/drawer; no satisface literalmente el concepto solicitado. |
| PUB-08 | Mejorar recomendaciones en ficha de producto para que roten y sean contextuales por categoría, subcategoría o compatibilidad. | Pendiente. Primero inspeccionar atributos existentes y hacer una estrategia útil sin inventar compatibilidad; si se requieren etiquetas nuevas, proponer migración y esperar confirmación. |
| PUB-09 | Asegurar que categorías, conteos y fotos del catálogo/categorías carguen de forma completa y estable, incluso tras recargas rápidas. | PR #13 desplegado y verificado: la API pública aporta categorías al hub cuando el render PHP viene vacío; producción muestra 36 tarjetas con conteos. Banner configurado roto detectado y se oculta con gracia. |
| CAT-01 | Datos dinámicos de categorías y descripciones/banners administrables por categoría. | Desplegado en PR #9. El usuario confirmó aplicación de migraciones y el diccionario recibido incluye `banner_image_url`, `banner_alt`, `description`, `icon` y `keywords`. La ruta destacada aún sufre una consulta fallida; véase PUB-09. |
| CAM-01 | Rediseñar y ampliar el editor de banners/campañas: más formatos, imagen real con ajuste y opacidad, chips, fechas, carruseles de imágenes por banner, orden/portada, preview, borradores y controles accesibles. | Editor base desplegado con PR #9. En curso: preview sin recortes por defecto, ancho de imagen ajustable y acordeones que conservan el banner abierto. El ancho nuevo requiere `migrations/014_campaign_image_layout.sql`; el usuario confirmó que ya la ejecutó. |
| CAM-02 | Relacionar accesos promocionales/chips con sus banners y conservar gestión comprensible. | Columnas 013 confirmadas por el usuario como aplicadas; API desplegada las devuelve. Se conservan los accesos globales existentes. |
| ADM-03 | Que el acceso al panel admin aparezca desde todas las cabeceras públicas solo para administradores autenticados. | Script compartido desplegado con PR #9; se comprobó el caso público sin sesión (el enlace permanece oculto). Falta confirmar el estado visible con una sesión admin real. |
| CAM-03 | Mantener imágenes de campañas accesibles después de las publicaciones y recuperar las tres imágenes actualmente rotas. | Verificado el 2026-10-09 contra la API pública y con HTTP HEAD: las URLs que hoy sirven las tres tarjetas devuelven 404. Volver a cargar desde el editor: `/uploads/campaigns/campaign_3dca57e947a495e338ede290.png` (Redes), `/uploads/campaigns/campaign_946d055155a9556ec9c4fe53.png` (Seguridad) y `/uploads/campaigns/campaign_814c8fd058bb1f2f1ab4c2a8.png` (Oficina). El archivo de la primera coincide con un registro 404 anterior; los otros dos IDs cambiaron. |
| CAM-04 | Corregir diseño de banners: proporción panorámica, imagen separada del panel de texto, preview fiel; fijar `assets/img/logo.webp` como marca canónica pública sin fallback/override remoto a otro logo. | Layout, preview y logo canónico desplegados en PR #11 (`7dbcc1e`). Las imágenes de campaña aún responden 404; véase CAM-03. |
| PUB-10 | Rediseñar las tarjetas compartidas de destacados/categorías: cuatro productos por fila, miniaturas limpias, precio, disponibilidad no inventada y botón Agregar conectado al carrito. | En curso local sobre `origin/main`; lint/build pasan. Falta validar PHP y flujo visual autenticado antes de desplegar. |
| PUB-11 | Página dinámica de categoría con sidebar de subcategorías, precio y disponibilidad real. | En curso local; el filtro de stock solo aparece cuando existe `product_inventory`. No se inventa filtro de marca porque el modelo normalizado de productos no proporciona una marca independiente. |
| PUB-12 | Solo familias principales destacadas usan banners en la vista externa; en páginas internas, permitir imagen opcional o icono por subcategoría. | Requerimiento nuevo pendiente. El editor actual administra banners por categoría padre, no una imagen/icono individual por subcategoría; definir persistencia/API antes de implementarlo. |
| ADM-04 | Alinear el dashboard con la nueva referencia: sidebar por módulos, superficies y bordes más legibles, gráficas/estadísticas/reportes reales. | Requerimiento posterior a la prioridad pública. El backlog ya separa alertas, ventas, salud del catálogo y módulos que aún no tienen backend. |
| CAT-02 | Evitar que la página de categorías destacadas quede vacía si falla la consulta de metadatos/banner de categorías o su conexión PHP. | Desplegado en PR #13 (`e4ec1e3`): fallback SQL y render cliente desde `/api/auth/catalog`; verificado en navegador con 36 categorías visibles y enlaces/conteos. |
| OPS-01 | Desplegar por etapas, confirmar dependencias SQL y verificar cada cambio en producción. | Recurrente. El usuario ejecuta migraciones SQL; documentar nombre, orden y confirmación antes del despliegue dependiente. |
| OPS-02 | Publicar ahora cualquier cambio adicional que ya esté listo. | PR #13 fusionado a `main` (`e4ec1e3`), CI exitosa, Hostinger lo reporta actual y `/categorias-destacadas` se verificó con 36 tarjetas. |

## Orden de trabajo acordado

1. Cerrar y verificar PUB-10/PUB-11 y CAM-01 localmente; migración 014 confirmada por el usuario. Reponer manualmente los tres archivos CAM-03 desde el editor para que queden persistidos en Hostinger.
2. Resolver CAT-01: confirmar/aplicar 010 y 011, fusionar PR #9 y verificar páginas de categorías en producción.
3. Implementar PUB-12 con decisión de almacenamiento para visuales por subcategoría; después centralizar cabecera/logo públicos PUB-05 y resolver el drawer PUB-07.
4. Decidir el alcance de PUB-04 (pantallas públicas, administrativas o ambas) y diseñar una navegación sin duplicar funciones antes de implementarlo.
5. Diagnosticar carga de imágenes/conteos PUB-09, definir recomendaciones PUB-08 y revisar consistencia PUB-06.
6. Cerrar cada etapa con pruebas y verificación de producción; añadir pedidos nuevos aquí antes de empezarlos.

## Registro de cambios

- 2026-10-09: consolidado el estado de PRs #3–#10, PR #9 abierto, y cambios locales de campañas/migración 012. Se aclaró que la densidad de 4 columnas y la miniatura ampliada sí se desplegaron; la separación completa de Catálogo/Productos/Categorías no.
- 2026-10-09: solicitud de despliegue comprobada contra `main`, PRs y producción. No se realizó otro despliegue: lo disponible ya está fusionado y lo restante depende de SQL o no está terminado.
- 2026-10-09: el usuario confirma migración 013 aplicada; PR #9 fusionado y publicado en Hostinger. Smoke en tienda confirma formato triple; encontró tres imágenes de campaña configuradas con respuesta 404, registradas para recuperación y corrección de persistencia.
- 2026-10-09: PR #11 desplegó logo canónico y layout de campañas. PR #12 dejó el fallback SQL, pero la página seguía vacía; PR #13 añadió fallback cliente desde catálogo y se verificó en Hostinger con 36 categorías visibles.
- 2026-10-09: se registran las nuevas referencias visuales del storefront y dashboard. El usuario confirma migración 014 aplicada. Producción actualmente devuelve 404 en las tres imágenes listadas en CAM-03; el banner de categoría Componentes Informáticos sí responde 200.
