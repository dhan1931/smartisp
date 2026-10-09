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
| PUB-03 | Páginas públicas de categorías destacadas y productos destacados, banners de categorías y actualización inicial del logo público. | Base desplegada en PRs #3 y #4. No equivale a un componente de cabecera totalmente centralizado ni resuelve el fallback al logo legado. |

## Pendiente / en curso

| ID | Requerimiento | Estado / siguiente paso |
| --- | --- | --- |
| PUB-04 | Separar Catálogo, Productos y Categorías en pantallas independientes. | **No implementado como tres pantallas nuevas.** Hay páginas públicas de categorías/productos destacados y una página de catálogo, pero no son una separación completa del área administrativa. Acordar el alcance de cada pantalla antes de construirla. |
| PUB-05 | Unificar la cabecera pública, navegación móvil y logo para todas las páginas; actualizar una sola implementación y evitar logos distintos/rotos. | Pendiente. Varias páginas PHP incluyen `includes/storefront-discovery-nav.php`, pero `tienda.html` y `productos-destacados.php` mantienen estructuras propias. `assets/js/public-brand.js` prioriza el logo de landing y puede caer a `/assets/img/logo.png`, que muestra una marca antigua. Centralizar markup, estilos y comportamiento; usar el logo canónico configurado y, si no carga, dejar el espacio del logo vacío (preferencia explícita del usuario), nunca mostrar otra marca. Incluir prueba en desktop y móvil para tienda, categorías, productos destacados, categoría individual y ficha. |
| PUB-06 | Hacer coherente el diseño de tarjetas e imágenes del listado entre catálogo y páginas comerciales/categorías. | Parcial. Densidad e imagen de ficha se desplegaron; falta validar que las tarjetas públicas tengan una presentación compartida y consistente. |
| PUB-07 | En móvil, abrir desde la izquierda un drawer blanco de categorías (aprox. 80% del ancho), sin los enlaces de secciones generales; incluir búsqueda, conteos y subcategorías y aplicarlo uniformemente a páginas públicas. | Pendiente de auditoría/implementación. La plantilla compartida actual aún incluye enlaces generales en la navegación/drawer; no satisface literalmente el concepto solicitado. |
| PUB-08 | Mejorar recomendaciones en ficha de producto para que roten y sean contextuales por categoría, subcategoría o compatibilidad. | Pendiente. Primero inspeccionar atributos existentes y hacer una estrategia útil sin inventar compatibilidad; si se requieren etiquetas nuevas, proponer migración y esperar confirmación. |
| PUB-09 | Asegurar que categorías, conteos y fotos del catálogo/categorías carguen de forma completa y estable, incluso tras recargas rápidas. | Pendiente de prueba de regresión en producción y diagnóstico de respuestas/cache/fallback si se reproduce; no dar por resuelto solo por el rendimiento observado en una carga. |
| CAT-01 | Datos dinámicos de categorías y descripciones/banners administrables por categoría. | PR #9 abierto; incluye `migrations/011_category_metadata.sql` además de metadatos y cambios de APIs/vistas. Falta confirmar que el usuario aplicó migraciones 010/011 en producción; no fusionar ni desplegar cambios que consulten columnas ausentes antes de esa confirmación. |
| CAM-01 | Rediseñar y ampliar el editor de banners/campañas: más formatos, imagen real con ajuste y opacidad, chips, fechas, carruseles de imágenes por banner, orden/portada, preview, borradores y controles accesibles. | Editor y renderer público implementados localmente; diccionario vigente confirma esquema 012. Preview ahora presenta carousel/single como banner individual y split/triple/grid/cards según formato; tiene navegación de prueba y chip individual. Build/lint pasan. Falta prueba visual completa del navegador, PR y despliegue. |
| CAM-02 | Relacionar accesos promocionales/chips con sus banners y conservar gestión comprensible. | Implementado localmente con `promo_chip_label/icon/target_url` por banner; requiere aplicar `migrations/013_campaign_slide_promotions.sql`, después pruebas, PR y despliegue. Se conservan los accesos globales existentes. |
| ADM-03 | Que el acceso al panel admin aparezca desde todas las cabeceras públicas solo para administradores autenticados. | Implementado localmente con validación `/api/auth/me`; falta confirmar visualmente rol admin/no-admin en las cabeceras públicas y desplegar. |
| OPS-01 | Desplegar por etapas, confirmar dependencias SQL y verificar cada cambio en producción. | Recurrente. El usuario ejecuta migraciones SQL; documentar nombre, orden y confirmación antes del despliegue dependiente. |
| OPS-02 | Publicar ahora cualquier cambio adicional que ya esté listo. | PRs #5–#8 y #10 ya están fusionados. El diccionario actual confirma que 012 está aplicada; el código de campañas aún depende de ejecutar 013 y no se ha desplegado. PR #9 depende de confirmar 011. Producción respondió HTTP 200 en la comprobación anterior. |

## Orden de trabajo acordado

1. Cerrar y verificar el editor CAM-01/CAM-02 y ADM-03 localmente; solicitar al usuario aplicar 013 (el diccionario recibido ya registra los campos de 012), después abrir PR y desplegar.
2. Resolver CAT-01: confirmar/aplicar 010 y 011, fusionar PR #9 y verificar páginas de categorías en producción.
3. Centralizar cabecera/logo públicos PUB-05 y resolver el drawer de categorías PUB-07; validar todas las pantallas públicas en móvil/escritorio.
4. Decidir el alcance de PUB-04 (pantallas públicas, administrativas o ambas) y diseñar una navegación sin duplicar funciones antes de implementarlo.
5. Diagnosticar carga de imágenes/conteos PUB-09, definir recomendaciones PUB-08 y revisar consistencia PUB-06.
6. Cerrar cada etapa con pruebas y verificación de producción; añadir pedidos nuevos aquí antes de empezarlos.

## Registro de cambios

- 2026-10-09: consolidado el estado de PRs #3–#10, PR #9 abierto, y cambios locales de campañas/migración 012. Se aclaró que la densidad de 4 columnas y la miniatura ampliada sí se desplegaron; la separación completa de Catálogo/Productos/Categorías no.
- 2026-10-09: solicitud de despliegue comprobada contra `main`, PRs y producción. No se realizó otro despliegue: lo disponible ya está fusionado y lo restante depende de SQL o no está terminado.
- 2026-10-09: el usuario confirma `docs/data-dictonary.md` como estado actual de la base. Se verificó que 012 ya está reflejada; se cerró preview de formatos localmente, se añadió chip vinculado por banner con migración 013 y se ajustó el mensaje de dependencias. Falta aplicar 013 y completar smoke visual antes de PR/despliegue.
