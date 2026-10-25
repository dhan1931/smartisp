# Campanas y productos destacados

La portada comercial se administra desde **Configuracion > Campanas y productos destacados**. La campana inicial `store-home` contiene diapositivas ordenables, texto, imagen, enlace y hasta 12 productos destacados. Los banners rotan de forma automatica; el visitante tambien puede cambiar de diapositiva. La seleccion de productos usa el catalogo actual y el carrito existente.

## Modelo de datos

- `storefront_campaigns`: nombre, ubicacion, activacion, intervalo de rotacion y ventana opcional de vigencia.
- `storefront_campaign_slides`: textos, portada, enlace, orden y visibilidad de cada banner.
- `storefront_campaign_products`: relacion ordenada entre la campana y productos existentes.
- `storefront_campaign_slide_images`: galeria, orden y portada de cada banner.
- Desde la migracion 014, `storefront_campaign_slides.image_width_pct` guarda por banner el porcentaje de ancho de imagen; la interfaz mantiene el texto en el espacio restante.

Las claves foraneas mantienen la integridad. Eliminar una campana elimina sus diapositivas y relaciones; eliminar un producto elimina solo su relacion de destacado. Los datos del catalogo, pedidos y ajustes existentes no se reescriben. Las fechas de vigencia estan preparadas en el esquema para una siguiente iteracion; el editor actual no las modifica.

## Activacion en Hostinger

1. Haz un respaldo de la base de datos.
2. Aplica en orden las migraciones pendientes desde `008` hasta `014` con el runner o phpMyAdmin. No ejecutes otra vez migraciones ya registradas. El usuario confirmó `014_campaign_image_layout.sql` aplicada el 2026-10-09.
3. Confirma que existan las tres tablas y la fila `store-home` en `storefront_campaigns`.
4. Despliega el frontend y `api/handlers/campaigns.php` junto con `api/router.php` después de confirmar las columnas y tablas de las migraciones pendientes.
5. Entra al panel, configura una portada, selecciona productos y guarda.

Esta migracion no se ejecuta desde el navegador ni durante el build. Sin ella, el catalogo normal sigue funcionando, pero la API de campanas no puede leer ni guardar contenido.

## Limites y seguridad

El panel requiere rol administrador. Se aceptan portadas JPG, PNG o WebP de hasta 8 MB y 40 megapixeles; se guardan en `uploads/campaigns/`. Los destinos admiten rutas internas o HTTPS. La tienda publica solo diapositivas activas y productos que sigan visibles. No subir imagenes con informacion sensible.
