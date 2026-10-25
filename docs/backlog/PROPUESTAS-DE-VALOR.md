# smartisp — Propuestas de mejora de valor

Fecha: 2026-10-05 · Basado en lo que hace hoy el código; sin datos de ventas ni de operación reales.
Marcado `[Inferencia]` donde asumo algo del negocio. Estas propuestas son de producto: la deuda técnica y de seguridad está en [DIAGNOSTICO.md](DIAGNOSTICO.md) y el plan de organización en [ARQUITECTURA-Y-DEUDA.md](ARQUITECTURA-Y-DEUDA.md).

## 1. Dónde está hoy el negocio en el sistema

**La tienda no cobra ni factura: capta pedidos y los pasa a una persona.** Lo que existe, según el código:

| Área | Qué hace hoy | Qué le falta |
|---|---|---|
| Compra | El checkout registra el pedido (`status: pending`) o una cotización (`quote_requested`) si el producto no tiene precio. El texto promete que "un asesor se comunicará para coordinar el pago y la entrega". | No hay pago en línea. `payment_provider` se guarda fijo como `'manual'`. |
| Facturación | No existe. El cliente recibe un "comprobante" por correo que no es una factura. | Factura electrónica. |
| Pedidos en el panel | **No hay pantalla de pedidos.** `admin.html` y los editores no mencionan "pedido" ni una sola vez. El backend puede listar los últimos 100, pero ningún HTML lo usa. | Ver, filtrar y cambiar el estado de un pedido. Hoy el administrador se entera por correo y WhatsApp. |
| Estado del pedido | Solo `pending` o `quote_requested`. No hay endpoint que actualice un pedido. | Un flujo de estados y su historial. |
| Entrega | Campo libre de dirección y "notas" (horario, detalles). | Método de entrega, costo, seguimiento y estado. |
| Inventario | No hay stock. El único límite es 500 unidades por pedido. | Existencias y disponibilidad por producto. |
| Ventas / reportes | No hay ni una cifra de ventas en el panel. | Totales, ticket promedio, productos más vendidos, cotizaciones que se concretan. |
| Catálogo | Producto con nombre, descripción, precio, categoría, SKU, imagen y visible. Importación por CSV/Excel y script de Intcomex. Búsqueda de imágenes por proveedor. | Marca, costo, descuento, IVA, stock, variantes, estado de la importación. |

**Cosas a medias** (código que existe pero no llega a completarse):
1. **Pagos:** el backend rellena `payment_status` y `payment_provider` solo si esas columnas existen en la tabla. El esquema que crea el propio código no las trae. Son restos de una idea de pago que no se terminó.
2. **Pedidos para administración:** la rama del backend que lista pedidos como admin existe sin una pantalla que la use.
3. **Wishlist:** funciona para el cliente en `tienda.html`, pero el negocio no ve qué productos se guardan; es información de demanda sin explotar.
4. **Cotizaciones:** el sistema distingue pedido de cotización, pero no hay forma de responder la cotización ni convertirla en pedido.
5. **Importación de catálogo:** hay tres caminos (CSV/Excel en el panel, script de Intcomex, `products-storage.json`) sin un registro de qué se importó, cuándo ni con qué errores.
6. **Buscador de imágenes:** consulta fuentes externas y hay proxy de imágenes; no hay control de calidad ni de derechos de uso `[Inferencia]`.
7. **Cuentas y perfil:** hay actualización de perfil y cambio de contraseña, pero el cliente no tiene un historial de pedidos con estados, solo el listado.

## 2. Propuestas

### P1. Centro de pedidos en el panel admin — **valor muy alto, esfuerzo medio**
El hueco más grande: hoy los pedidos viven en correos y en la base de datos.
- Lista de pedidos con filtros por estado, fecha, cliente y tipo (pedido o cotización).
- Detalle del pedido: productos, cliente, dirección, notas, historial de cambios.
- Cambio de estado con un clic, que dispara un correo al cliente.
- Notas internas y asignación a quien atiende.
- Alerta de pedidos sin atender por más de 24 o 48 horas.

**Flujo de estados propuesto:**

```text
quote_requested → quoted → pending → paid → preparing → shipped / ready_for_pickup → delivered
        └──────────────┴──────────┴────────→ cancelled   (con motivo)
```

Se guarda cada cambio en una tabla `order_events` (quién, cuándo, de qué estado a cuál). Eso da historial y es la base de los reportes de la P4.

### P2. Cotizaciones que se convierten en venta — **valor alto, esfuerzo bajo**
Si hay productos sin precio, el negocio ya vende "por cotización". Falta cerrar el ciclo:
- El administrador responde con precio y vigencia desde el pedido.
- El cliente recibe un enlace para aceptar; al aceptar, la cotización pasa a pedido.
- Se mide cuántas cotizaciones se concretan (conversión) y en cuánto tiempo.

### P3. Flujo de entrega — **valor alto, esfuerzo medio**
- Métodos de entrega configurables: retiro en tienda, mensajería local, transporte nacional.
- Costo de envío por ciudad o zona, mostrado en el checkout antes de confirmar.
- Estado de entrega visible para el cliente (`preparing`, `shipped`, `delivered`), con número de guía cuando aplique.
- Correo automático en cada cambio y página "mi pedido" con el estado.
- Registro de la entrega: quién recibió y cuándo.

### P4. Panel de ventas — **valor alto, esfuerzo bajo una vez exista P1**
Responde "cuánto se vende". Con `orders_rows` y `order_events` se calcula casi todo:

| Indicador | Para qué sirve |
|---|---|
| Ventas del día, semana y mes; comparación con el período anterior | Saber si se crece |
| Número de pedidos y ticket promedio | Entender el tamaño de la compra |
| Cotizaciones recibidas y porcentaje que se concreta | Medir el cierre comercial |
| Productos y categorías más vendidos; productos sin ventas | Decidir qué promover y qué retirar |
| Ventas por ciudad | Planear entrega y publicidad |
| Tiempo desde el pedido hasta la entrega | Medir el servicio |
| Pedidos cancelados y motivos | Detectar fricción |
| Productos en lista de deseos que no se compran | Demanda sin cubrir |

Con costo cargado en el producto se añade **margen por producto y por pedido**. Se empieza con tablas y gráficos simples; no hace falta una herramienta de análisis aparte.

### P5. Cobro en línea — **valor alto, esfuerzo medio-alto, requiere decisión comercial**
- Mantener el pago manual (transferencia o depósito) con **confirmación de pago desde el panel**: el administrador marca el pedido como pagado y sube el comprobante. Es la versión barata que cubre lo que ya se hace.
- Sumar pasarelas de pago locales. `[Inferencia]` El dominio `.ec` y la zona horaria `America/Guayaquil` indican mercado ecuatoriano; hay que verificar cuáles ofrecen tarjeta, transferencia o QR con comisión y cuenta empresarial (por ejemplo PayPhone, Datafast, PlacetoPay, DeUna).
- El pago se confirma por **webhook firmado del proveedor**, nunca por lo que diga el navegador. Se guarda en una tabla `payments` (monto, estado, referencia, proveedor) y se concilia con el pedido.
- Antes de cobrar en línea deben estar resueltos los críticos de seguridad (ver DIAGNOSTICO.md).

### P6. Facturación electrónica — **valor alto para cumplimiento, esfuerzo alto, requiere trámite**
`[Inferencia]` En Ecuador la factura electrónica se emite al SRI y exige certificado de firma electrónica y autorización de comprobantes.
- Datos fiscales en el checkout: cédula o RUC, razón social, correo de facturación.
- Emitir la factura desde el panel cuando el pedido pasa a `paid`, a través de un proveedor autorizado en lugar de implementar el esquema del SRI desde cero.
- Guardar el número de factura, la clave de acceso y el PDF en el pedido; enviarlos al cliente.
- Para el cliente externo es lo que más reduce trabajo manual y riesgo tributario.
- Decisión previa: quién es el emisor (el cliente) y qué proveedor de facturación usa.

### P7. Manejo de catálogo — **valor alto, esfuerzo medio**
- **Inventario:** campo de stock, alerta de bajo inventario, "agotado" automático y reserva al crear el pedido para evitar vender lo que no hay.
- **Datos del producto:** marca, costo, descuento con vigencia, IVA, peso (para el envío) y estado (borrador, publicado, archivado) en lugar de solo `visible`.
- **Importaciones con registro:** una pantalla que muestra cada importación (origen, fecha, filas nuevas, actualizadas, con error) y permite revisar antes de publicar.
- **Edición masiva:** cambiar precio, categoría o visibilidad de varios productos a la vez, con vista previa.
- **Sincronización con proveedores** (Intcomex y otros): actualización programada de precio y existencias, con reglas de margen.
- **Control de calidad:** lista de productos sin imagen, sin precio, sin categoría o con SKU duplicado.
- **Variantes** (talla, capacidad, color) solo si el catálogo lo necesita; no es prioridad `[Inferencia]`.

### P8. Mejor manejo del admin — **valor medio, esfuerzo medio**
Aprovecha la separación de login y panel ya propuesta.
- **Roles y usuarios:** hoy hay un único administrador definido por email. Añadir roles (administrador, ventas, catálogo) con permisos por sección, y que se gestionen desde el panel y no desde el código.
- **Registro de actividad:** quién cambió un precio, borró un producto o cambió un pedido, y cuándo. Es la contrapartida de poder dar acceso a más personas.
- **Acciones peligrosas con respaldo:** "Eliminar todos los productos" pasa a requerir confirmación reforzada y genera una copia exportable antes de ejecutarse.
- **Búsqueda global** de pedidos, clientes y productos.
- **Clientes:** ficha con historial de pedidos y cotizaciones, total comprado y notas.

### P9. Experiencia del cliente — **valor medio, esfuerzo bajo-medio**
- "Mis pedidos" con estado, seguimiento y descarga de factura.
- Correos transaccionales coherentes: pedido recibido, pago confirmado, enviado, entregado.
- Recuperación de carritos abandonados (el carrito ya vive en `localStorage`; falta asociarlo a la cuenta).
- Aviso al cliente cuando un producto de su lista de deseos baja de precio o vuelve a tener stock.

## 3. Priorización

| Orden | Propuesta | Por qué en ese lugar |
|---|---|---|
| 0 | Cerrar los fallos de seguridad y separar login/admin | No se debe manejar dinero ni datos fiscales sobre el estado actual |
| 1 | P1 Centro de pedidos | Es el hueco más grande y habilita todo lo demás (estados, reportes, cobro) |
| 2 | P4 Panel de ventas | Poco esfuerzo una vez hay P1; responde la pregunta de cuánto se vende |
| 3 | P2 Cotizaciones → pedido | El negocio ya vende por cotización; cierra un ciclo abierto |
| 4 | P7 Catálogo (stock, calidad, importaciones) | Evita vender sin existencias y reduce trabajo manual |
| 5 | P3 Flujo de entrega | Depende de los estados de P1 |
| 6 | P5 Cobro en línea | Requiere decisión comercial, cuenta con proveedor y seguridad al día |
| 7 | P6 Facturación electrónica | Requiere trámites y proveedor; se apoya en P5 |
| 8 | P8 Roles y actividad, P9 experiencia | Valor creciente cuando hay más usuarios y volumen |

**Olas sugeridas**
- **Ola 1 (operar con orden):** P1 + P4 + P2 sobre el manejo manual de pagos actual.
- **Ola 2 (vender mejor):** P7 + P3 + P9.
- **Ola 3 (cobrar y facturar):** P5 + P6 + P8.

## 4. Datos que habría que agregar (mínimo)

```text
orders          + payment_status, delivery_method, shipping_cost, tracking_code, tax_id, assigned_to, updated_at
order_events    order_id, from_status, to_status, actor, note, created_at
payments        order_id, provider, reference, amount, status, paid_at
invoices        order_id, number, access_key, pdf_url, issued_at
products        + stock, cost, brand, discount_price, discount_until, tax_rate, weight, status
import_runs     source, started_at, created, updated, failed, report
audit_log       actor, action, entity, entity_id, before, after, created_at
```

Todo con migraciones numeradas, no con `ALTER TABLE` automático en cada petición como hoy.

## 5. Decisiones que necesito del negocio

| ID | Pregunta | Por qué importa |
|---|---|---|
| N1 | ¿Cómo se cobra hoy y con qué frecuencia? ¿Transferencia, efectivo, tarjeta, otro? | Define P5 |
| N2 | ¿Quién factura y con qué sistema? | Define P6 |
| N3 | ¿Cuántos pedidos y cotizaciones llegan por semana? | Define cuánto invertir en automatizar |
| N4 | ¿Se maneja stock propio o se vende bajo pedido a proveedor (Intcomex)? | Define P7: reserva de stock o disponibilidad del proveedor |
| N5 | ¿Cómo se entrega hoy y en qué zonas? | Define P3 |
| N6 | ¿Cuántas personas usarán el panel y con qué responsabilidades? | Define P8 |
| N7 | ¿Qué indicadores se quieren ver primero? | Ajusta P4 |

## 6. Cómo medir que funciona

- Tiempo medio desde el pedido hasta la primera respuesta.
- Porcentaje de pedidos sin estado actualizado en 48 horas.
- Conversión de cotización a pedido.
- Pedidos entregados sin incidencia.
- Horas por semana dedicadas a tareas manuales (confirmar pagos, emitir facturas, actualizar precios).
