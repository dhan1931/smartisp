# Seguimiento: portada de la tienda

Requisitos acumulados para alinear la portada comercial con el diseño de referencia, preservando catálogo, navegación, búsqueda, filtros y carrito existentes.

## Este lote

- [x] Carrusel principal: flechas laterales grandes y composición por banner de imagen como fondo con texto legible o imagen lateral con panel de color configurable.
- [x] Guardar color de fondo del panel del banner (`copy_background_color`); requiere aplicar `migrations/020_campaign_copy_background_color.sql` en producción antes de publicar los cambios del API.
- [x] Vista previa del editor alineada con los dos estilos disponibles.
- [x] Mantener el hero, accesos, productos y categorías conectados a sus fuentes actuales.
- [x] Mostrar hasta siete categorías escogidas desde el panel, con sus nombres, descripciones, imágenes y enlaces reales (`categories_rows`); la octava tarjeta abre todas las categorías.
- [x] Ajustar la vitrina de productos a seis columnas en escritorio amplio, cuatro en escritorio/tablet y dos en móvil.
- [x] Añadir una zona independiente para banners secundarios debajo de productos destacados.
- [x] Permitir que la zona secundaria reutilice galerías, enlaces, encuadre, opacidad, chips de esquina y programación del editor.
- [x] Separar estado y borradores locales de portada principal y banners secundarios.
- [x] Evitar que guardar banners secundarios elimine productos destacados o accesos promocionales de la portada.
- [x] Conservar la fila de beneficios y el footer actuales, que ya están conectados a contenido real.

## Pendiente por fuente de datos

- [ ] Franja “Nuestras marcas”: `products_rows` no contiene un campo de marca y el API público no tiene un catálogo de marcas/logotipos. No publicar nombres ni convenios inferidos del título del producto. Definir una fuente real (entidad de marcas, relación producto-marca y logo) antes de activarla.
- [ ] Verificar visualmente en producción la densidad de productos; la cantidad mostrada continúa dependiendo de la selección administrada en Campañas.
- [ ] Revisar destinos de redes sociales, contacto y enlaces legales del footer; varios siguen apuntando a `#` y requieren sus URLs oficiales antes de activarse.

## Despliegue y validación

- Este documento registra los requisitos; no ejecutar migraciones desde la aplicación.
- La campaña secundaria aprovecha `storefront_campaigns` y las tablas de galería existentes. Confirmar que producción tenga el esquema de campañas y los campos de imagen añadidos antes de desplegar el API/editor.
- No marcar como desplegado hasta verificar el build, las comprobaciones del repositorio y la portada con campañas cargadas en producción.
