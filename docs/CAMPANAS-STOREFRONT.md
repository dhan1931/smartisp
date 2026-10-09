# Campanas y productos destacados

La portada comercial se administra desde **Configuracion > Campanas y productos destacados**. La campana inicial `store-home` contiene diapositivas ordenables, texto, imagen, enlace y hasta 12 productos destacados. Los banners rotan de forma automatica; el visitante tambien puede cambiar de diapositiva. La seleccion de productos usa el catalogo actual y el carrito existente.

## Modelo de datos

- `storefront_campaigns`: nombre, ubicacion, activacion, intervalo de rotacion y ventana opcional de vigencia.
- `storefront_campaign_slides`: textos, portada, enlace, orden y visibilidad de cada banner.
- `storefront_campaign_products`: relacion ordenada entre la campana y productos existentes.

Las claves foraneas mantienen la integridad. Eliminar una campana elimina sus diapositivas y relaciones; eliminar un producto elimina solo su relacion de destacado. Los datos del catalogo, pedidos y ajustes existentes no se reescriben. Las fechas de vigencia estan preparadas en el esquema para una siguiente iteracion; el editor actual no las modifica.

## Activacion en Hostinger

1. Haz un respaldo de la base de datos.
2. Aplica `migrations/008_storefront_campaigns.sql` con el runner de migraciones o desde phpMyAdmin. No ejecutes otra vez migraciones ya registradas.
3. Confirma que existan las tres tablas y la fila `store-home` en `storefront_campaigns`.
4. Despliega el frontend y `api/handlers/campaigns.php` junto con `api/router.php`.
5. Entra al panel, configura una portada, selecciona productos y guarda.

Esta migracion no se ejecuta desde el navegador ni durante el build. Sin ella, el catalogo normal sigue funcionando, pero la API de campanas no puede leer ni guardar contenido.

## Limites y seguridad

El panel requiere rol administrador. Se aceptan portadas JPG, PNG o WebP de hasta 8 MB y 40 megapixeles; se guardan en `uploads/campaigns/`. Los destinos admiten rutas internas o HTTPS. La tienda publica solo diapositivas activas y productos que sigan visibles. No subir imagenes con informacion sensible.
