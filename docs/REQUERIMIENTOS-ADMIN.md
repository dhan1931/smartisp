# Requerimientos del panel administrativo

## Directorio de clientes

- [x] Sustituir el acceso deshabilitado de Clientes por una pantalla real del panel.
- [x] Unificar cuentas registradas y compradores invitados a partir de `users_rows` y `orders_rows`, evitando duplicados por correo.
- [x] Añadir búsqueda, filtro por tipo, paginación, conteo de pedidos, valor no cancelado y última compra.
- [x] Añadir ficha de consulta con datos de contacto e historial de hasta 20 pedidos.
- [x] Proteger la consulta mediante autorización administrativa y reutilizar la navegación compartida.
- [x] Registrar la nueva ruta en el build de producción; no requiere migración de datos.
- [ ] Validar contra la base de producción con una sesión administradora antes de desplegar.
