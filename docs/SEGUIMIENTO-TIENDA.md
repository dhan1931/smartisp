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
| CAM-01 | Rediseñar y ampliar el editor de banners/campañas: más formatos, imagen real con ajuste y opacidad, chips, fechas, carruseles de imágenes por banner, orden/portada, preview, borradores y controles accesibles. | Desplegado con PR #9, commit `77ec13e`. API de campañas y editor responden; revisión visual de producción revela que los tres archivos de imagen configurados devuelven 404. El layout/renderer ya está, pero las imágenes requieren recuperación o nueva carga. |
| CAM-02 | Relacionar accesos promocionales/chips con sus banners y conservar gestión comprensible. | Columnas 013 confirmadas por el usuario como aplicadas; API desplegada las devuelve. Se conservan los accesos globales existentes. |
| ADM-03 | Que el acceso al panel admin aparezca desde todas las cabeceras públicas solo para administradores autenticados. | Script compartido desplegado con PR #9; se comprobó el caso público sin sesión (el enlace permanece oculto). Falta confirmar el estado visible con una sesión admin real. |
| CAM-03 | Mantener imágenes de campañas accesibles después de las publicaciones y recuperar las tres imágenes actualmente rotas. | Pendiente urgente: producción devuelve 404 para `/uploads/campaigns/campaign_78587e242de541241ad47012.png`, `campaign_de261b79bec3153602eba6b2.png` y `campaign_3dca57e947a495e338ede290.png`. Las imágenes no existen en el checkout local y están excluidas de Git; aclarar política de persistencia de `uploads/` de Hostinger y volver a cargarlas desde el editor. |
| CAM-04 | Corregir diseño de banners: proporción panorámica, imagen separada del panel de texto, preview fiel; fijar `assets/img/logo.webp` como marca canónica pública sin fallback/override remoto a otro logo. | Layout, preview y logo canónico desplegados en PR #11 (`7dbcc1e`). Las imágenes de campaña aún responden 404; véase CAM-03. |
| CAT-02 | Evitar que la página de categorías destacadas quede vacía si falla la consulta de metadatos/banner de categorías o su conexión PHP. | Desplegado en PR #13 (`e4ec1e3`): fallback SQL y render cliente desde `/api/auth/catalog`; verificado en navegador con 36 categorías visibles y enlaces/conteos. |
| OPS-01 | Desplegar por etapas, confirmar dependencias SQL y verificar cada cambio en producción. | Recurrente. El usuario ejecuta migraciones SQL; documentar nombre, orden y confirmación antes del despliegue dependiente. |
| OPS-02 | Publicar ahora cualquier cambio adicional que ya esté listo. | PR #13 fusionado a `main` (`e4ec1e3`), CI exitosa, Hostinger lo reporta actual y `/categorias-destacadas` se verificó con 36 tarjetas. |

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
- 2026-10-09: el usuario confirma migración 013 aplicada; PR #9 fusionado y publicado en Hostinger. Smoke en tienda confirma formato triple; encontró tres imágenes de campaña configuradas con respuesta 404, registradas para recuperación y corrección de persistencia.
- 2026-10-09: PR #11 desplegó logo canónico y layout de campañas. PR #12 dejó el fallback SQL, pero la página seguía vacía; PR #13 añadió fallback cliente desde catálogo y se verificó en Hostinger con 36 categorías visibles.
