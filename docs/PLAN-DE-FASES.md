# smartisp — Plan por fases y sprints

Inicio: lunes 2026-10-05 · Capacidad: 3 h/día, 10–12 h/semana · Sprint = 1 semana (lunes a domingo).
Las horas son **estimaciones mías** sobre el código actual, con un margen de ±30 %. Se planifica a ~11 h por sprint.
Los IDs `DEV-…` son las tareas de [ops/BACKLOG.md](../ops/BACKLOG.md). Origen: [DIAGNOSTICO.md](DIAGNOSTICO.md), [ARQUITECTURA-Y-DEUDA.md](ARQUITECTURA-Y-DEUDA.md) y [PROPUESTAS-DE-VALOR.md](PROPUESTAS-DE-VALOR.md).

## Resumen

**Núcleo comprometido: 6 fases, ~137 h en 13 sprints (5 oct 2026 – 3 ene 2027).** Con un sobrecosto realista de ×1.3 son ~178 h, unas 16 semanas.
Cubre: seguridad cerrada, login único, un solo backend con rutas centralizadas, catálogo rápido, archivos y assets ordenados, **un panel admin unificado** (productos, categorías, contenido, correo y pedidos en un solo lugar), páginas públicas con layout compartido, y el centro de pedidos con panel de ventas.

**Consolidación:** una versión anterior sumaba ~180 h porque varias tareas se pisaban. El trabajo de front (login, panel, layout y "dividir el front") tocaba las mismas 7 páginas y repetía el cliente de API y la sesión tres veces. Se fundió en **tres bloques** (login único, panel admin unificado, sitio público con layout compartido: 44 h en lugar de 79 h) y se agruparon las tareas de reorganización de archivos (de 12.5 h a 8 h) y las de enrutamiento y catálogo. Ahorro: ~43 h.

| Fase | Foco | Sprints | Fechas | Horas |
|---|---|---|---|---|
| 1 | Seguridad inmediata y accesos críticos | 1–2 | 5 – 18 oct | 16 |
| 2 | Login único y endurecimiento | 2–3 | 12 – 25 oct | 13 |
| 3 | Backend único, pruebas y enrutamiento | 3–5 | 19 oct – 8 nov | 24 |
| 4 | Catálogo rápido y organización de archivos | 5–7 | 2 – 22 nov | 20 |
| 5 | Panel admin y sitio público unificados | 7–10 | 16 nov – 13 dic | 36 |
| 6 | Pedidos y ventas | 10–13 | 7 dic – 3 ene | 28 |
| 7 | *(opcional)* Catálogo avanzado y entrega | 14–17 | 4 – 31 ene | 40 |
| 8 | *(opcional)* Operación: roles, actividad y CI | 18–19 | 1 – 14 feb | 16 |
| 9 | *(opcional, requiere decisiones)* Cobro y facturación | 20–24 | 15 feb – 21 mar | 48 |

Las fases del núcleo se solapan en un sprint donde una termina y la siguiente empieza. El orden importa: el panel unificado va **antes** que los pedidos, para construir esa pantalla una sola vez dentro del panel nuevo y no rehacerla.

## Por qué estas horas (justificación por pantalla y por pieza)

El código a mover es acotado, pero hay mucho duplicado y todo está mezclado en pocas páginas. Cada cifra suma estas piezas:

| Bloque | h | De qué se compone |
|---|---|---|
| **Login único** (DEV-012) | 8 | `login.html` 1.5 · endpoint `/auth/me` y `session.js` 1.5 · cookie HttpOnly + SameSite 2 · cierre de sesión en servidor 1 · migrar el login de clientes de `tienda.html` 1.5 · pruebas 0.5 |
| **Panel admin unificado** (DEV-037, 038, 039 y la parte de admin de DEV-024) | 24 | Shell con navegación y guardia por rol 4 · sección **Catálogo**: lista, filtros, modal, importación y categorías, de `editor-catalogo.html` (3.674 líneas) 8 · sección **Contenido** de `editor-landing.html` (3.930 líneas) 5 · secciones Inicio, Correo y Configuración del resto de `admin.html` 5 · `api.js` común y estilos del panel 2 |
| **Sitio público con layout compartido** (DEV-040 y el resto de DEV-024) | 12 | Layout compartido (cabecera, pie, tokens de estilo, bloque de favicons) 4 · extraer JS y CSS de `tienda.html` 3 · `index.html` 2 · `checkout.html` 2 · `reset-password.html` 1 |
| **Router y rutas** (DEV-015 + 016) | 13 | Tabla de rutas y guardia por nivel 4 · mover ~40 bloques a la tabla, ~0.2 h cada uno 8 · convención `/api/<recurso>` con alias 1 |
| **Pruebas de contrato** (DEV-013) | 8 | ~35 endpoints a ~0.2 h cada uno 7 · preparación 1 |
| **Catálogo en SQL + esquema base** (DEV-022 + 023) | 12 | Consulta con filtro y paginación 4 · caché corta e índices 2 · exportar y versionar el esquema real 4 · retirar `DESCRIBE` en tiempo de petición 2 |
| **Archivos y assets** (DEV-032 a 036) | 8 | Una sola copia de los 19 archivos duplicados y despliegue desde `public/` 3 · borrar `app.js`, `styles.css` y `db.js` 0.5 · logos duplicados y peso 1.5 · bloque de favicons y URLs relativas 1 · rutas limpias con redirecciones 2 |
| **Pedidos y ventas** (DEV-019, 021, 020) | 28 | Centro de pedidos 14 (tabla de eventos 2, lista con filtros 4, detalle, estado y correo 5, notas 1, pruebas 2) · cotizaciones a pedido 6 · panel de ventas 8 |
| **Seguridad inmediata** (DEV-001 a 011) | 16 | 6 críticos de 1 h cada uno 6 · hash de contraseñas 4 · TLS 2 · `SESSION_SECRET` 1 · `.htaccess` 1.5 · endpoints `test-*` 1.5 |

**Qué no está en esta cuenta:** el trabajo de *pensar* lo que no hay. No hay entorno de pruebas, ni el esquema real de la base, ni sabemos cómo está guardada la contraseña real del administrador. Por eso la cifra realista es ×1.3.

## Sprints del núcleo

| Sprint | Fechas | Horas | Tareas |
|---|---|---|---|
| 1 | 5–11 oct | 12 | **Seguridad rápida:** DEV-001, 007, 002, 003, 005, 004 (1 h cada una, en ese orden), DEV-008 (2 h), 009 (1 h), 010 (1.5 h), 011 (1.5 h) |
| 2 | 12–18 oct | 11 | DEV-006 hash de contraseñas (4 h) · login único, parte A (7 h) |
| 3 | 19–25 oct | 11 | Login único, cierre (1 h) · DEV-017 intentos (3 h) y DEV-018 SSRF (2 h) · DEV-014 backend canónico (3 h) · pruebas de contrato, inicio (2 h) |
| 4 | 26 oct – 1 nov | 11 | Pruebas de contrato (6 h) · router y rutas, inicio (5 h) |
| 5 | 2–8 nov | 11 | Router y rutas (8 h) · catálogo en SQL y esquema, inicio (3 h) |
| 6 | 9–15 nov | 11 | Catálogo en SQL y esquema (9 h) · archivos y assets, inicio (2 h) |
| 7 | 16–22 nov | 11 | Archivos y assets (6 h) · panel admin unificado, inicio (5 h) |
| 8 | 23–29 nov | 11 | Panel admin unificado (11 h) |
| 9 | 30 nov – 6 dic | 11 | Panel admin unificado, cierre (8 h) · sitio público, inicio (3 h) |
| 10 | 7–13 dic | 11 | Sitio público (9 h) · pedidos y ventas, inicio (2 h) |
| 11 | 14–20 dic | 11 | Pedidos y ventas (11 h) |
| 12 | 21–27 dic | 11 | Pedidos y ventas (11 h) |
| 13 | 28 dic – 3 ene | 4 | Pedidos y ventas, cierre (4 h). **Punto de decisión:** elegir la fase opcional siguiente con datos reales. |

Las semanas de fin de año (sprints 12 y 13) pueden rendir menos; por eso ahí no hay nada de seguridad.

---

## Fase 1 — Seguridad inmediata y accesos críticos (sprints 1–2)

**Objetivo:** cerrar lo que hoy permite entrar como administrador o expone credenciales, con cambios mínimos y sin rediseñar el login.
**Hecho cuando:** sin críticos de seguridad en `devflow auditar`, `devflow secretos` limpio y contraseñas con hash seguro.

| Tarea | h | Detalle |
|---|---|---|
| DEV-001 Rotar credenciales de MySQL y quitar fallbacks | 1 | Cambiar la contraseña en Hostinger (tú), ponerla en config local fuera de git, dejar `config.example.php`. **Primero.** |
| DEV-007 Cerrar exposición de SMTP/Resend en `/catalog` | 1 | Lista blanca de claves; confirmar con una petición de solo lectura; rotar las credenciales de correo si estuvieron expuestas. |
| DEV-002 Quitar `X-Admin-Email` del front | 1 | `admin.html`, `editor-catalogo.html` y `tienda.html` ya envían `Authorization: Bearer`; basta borrar la cabecera de identidad y el admin por defecto. **Verificar que el panel funciona solo con el token.** |
| DEV-003 Quitar el bypass por `X-Admin-Email` del backend | 1 | **Solo después de verificar DEV-002.** |
| DEV-005 Clave de firma de tokens a configuración y rotarla | 1 | Los administradores vuelven a entrar una vez. |
| DEV-004 Eliminar la contraseña universal de admin | 1 | Solo si el administrador entra con su contraseña real; comprobarlo antes. |
| DEV-008 Verificar TLS en correo, Postgres e importación | 2 | Activar verificación de certificado y probar cada conexión. |
| DEV-009 Quitar el `SESSION_SECRET` por defecto | 1 | Fallar al arrancar si no está configurado. |
| DEV-010 Proteger archivos internos en la raíz web | 1.5 | `.htaccess` y verificación en el servidor. |
| DEV-011 Deshabilitar endpoints `test-*` | 1.5 | Exigir admin o quitarlos de producción. |
| DEV-006 Migrar a `password_hash` y retirar texto plano/MD5/SHA1 | 4 | Rehash en el siguiente login exitoso y script para migrar por lotes. **Copia de la tabla de usuarios antes.** No baja a 1 h porque toca datos de usuarios. |

**Orden obligatorio:** 001 → 007 → 002 → *verificar* → 003 → 005 → 004. DEV-006 va antes de retirar la compatibilidad con contraseñas viejas.
**Riesgos:** no hay staging; probar en horas de poco tráfico y con la versión anterior a mano. Al rotar MySQL el sitio cae hasta poner la contraseña nueva en el servidor.

## Fase 2 — Login único y endurecimiento (sprints 2–3)

**Objetivo:** un solo login separado del panel; el rol lo decide el backend.
**Hecho cuando:** sin emails de admin en los HTML, sin token en `localStorage`, el login de clientes y de administradores unificado.

| Tarea | h | Detalle |
|---|---|---|
| DEV-012 Login único y sesión (versión acotada) | 8 | `login.html`, `session.js` contra `/api/auth/me`, cookie HttpOnly + SameSite, cierre de sesión en servidor, login de clientes migrado. El panel unificado y `api.js` salen de esta tarea y van en la fase 5. |
| DEV-017 Límite de intentos en el login | 3 | Bloqueo temporal tras N fallos. |
| DEV-018 Lista de dominios permitidos en el proxy de imágenes | 2 | Cerrar el SSRF. |

## Fase 3 — Backend único, pruebas y enrutamiento (sprints 3–5)

**Objetivo:** una sola implementación de la API, red de seguridad de pruebas y autorización centralizada por ruta.
**Hecho cuando:** `server.js` congelado, una prueba por endpoint público y admin, ninguna ruta sin nivel de acceso declarado y una sola convención `/api/<recurso>`.

| Tarea | h | Detalle |
|---|---|---|
| DEV-014 Decidir el backend canónico y congelar el otro | 3 | Recomendación: PHP. Mover `server.js` a `legacy/` y retirar dependencias que ya no se usen. |
| DEV-013 Pruebas de contrato de la API | 8 | Incluye 403 sin credenciales en cada endpoint admin. |
| DEV-015 + DEV-016 Router con tabla de rutas y URL `/api/<recurso>` | 13 | Cada ruta declara `public`, `customer` o `admin`; alias antiguos como redirecciones y retirados después. |

## Fase 4 — Catálogo rápido y organización de archivos (sprints 5–7)

**Objetivo:** catálogo que no cargue toda la tabla en cada visita, esquema reproducible y un solo origen de archivos y assets.
**Hecho cuando:** el catálogo filtra y pagina en SQL con tiempos medidos antes y después, no hay `DESCRIBE` en tiempo de petición y no quedan archivos duplicados ni código muerto.

| Tarea | h | Detalle |
|---|---|---|
| DEV-022 + DEV-023 Catálogo en SQL y esquema base | 12 | Filtro y paginación en SQL, caché corta, índices, esquema real exportado y versionado, sin `DESCRIBE`. |
| DEV-032 a 036 Archivos y assets | 8 | Una sola copia de los 19 archivos duplicados entre raíz y `public/` y despliegue desde `public/`; borrar `app.js`, `styles.css` y `db.js`; logos duplicados; bloque de favicons con URLs relativas; rutas de páginas limpias con redirecciones. |

## Fase 5 — Panel admin y sitio público unificados (sprints 7–10)

**Objetivo:** un solo panel donde esté todo lo que hoy está repartido en tres páginas, y páginas públicas con un layout común.
**Hoy:** la gestión de productos existe, pero está escondida: `editor-catalogo.html` solo se llega con botones sueltos dentro de `admin.html`, que además tiene su propio modal de producto, importación y resumen de categorías; `editor-landing.html` es otra página aparte.
**Hecho cuando:** el panel tiene navegación con las secciones Inicio, Catálogo, Contenido, Correo y Configuración (y Pedidos al llegar la fase 6), ninguna sección supera ~800 líneas, `editor-catalogo.html` y `editor-landing.html` están retirados, y todas las páginas comparten cabecera, pie y estilos base.

| Tarea | h | Detalle |
|---|---|---|
| DEV-037 + 038 + 039 Panel admin unificado | 24 | Shell con navegación y guardia por rol; sección **Catálogo** (lista con búsqueda y filtros, edición rápida y masiva con vista previa, categorías y reasignación, importación, búsqueda de imágenes); sección **Contenido** (apariencia, visibilidad y textos); Inicio, Correo y Configuración. |
| DEV-040 + DEV-024 Sitio público con layout compartido | 12 | Cabecera, pie, tokens de estilo y componentes comunes; extraer JS y CSS de `tienda.html`, `index.html`, `checkout.html` y `reset-password.html`. |

## Fase 6 — Pedidos y ventas (sprints 10–13)

**Objetivo:** que el negocio vea y gestione sus pedidos y sepa cuánto vende. Es valor nuevo, no un fix, y va dentro del panel unificado.
**Hecho cuando:** un administrador ve y cambia el estado de cualquier pedido, cada cambio queda registrado y el panel muestra las ventas del período.

| Tarea | h | Detalle |
|---|---|---|
| DEV-019 Centro de pedidos | 14 | Tabla `order_events`, lista con filtros, detalle, cambio de estado con correo al cliente, notas internas. |
| DEV-021 Cotizaciones que se convierten en pedido | 6 | Flujo simple, un solo paso de aceptación. |
| DEV-020 Panel de ventas | 8 | Ventas por período, ticket promedio, más vendidos, conversión de cotizaciones. |

---

# Fases opcionales (se deciden en el sprint 13)

## Fase 7 — Catálogo avanzado y entrega (sprints 14–17)
Tareas de StellarCode aún **por crear**: Catálogo, Flujo de entrega, "Mi pedido".
- **Catálogo (18 h):** stock con alerta y reserva, costo, marca, descuento con vigencia, IVA, estado del producto; pantalla de importaciones con errores, edición masiva y lista de productos sin imagen, sin precio o con SKU duplicado.
- **Flujo de entrega (14 h):** métodos, costo por ciudad en el checkout, estado y número de guía visibles para el cliente.
- **Mi pedido (8 h):** estados, seguimiento y recuperación de carritos.

## Fase 8 — Operación: roles, actividad y CI (sprints 18–19)
- **Roles y registro de actividad (10 h):** administrador, ventas y catálogo; quién cambió qué; confirmación reforzada de acciones destructivas.
- **Comandos `build`/`test`/`lint` en `.devflow.yml` y CI (6 h).**

## Fase 9 — Cobro y facturación (sprints 20–24)
**Requiere decisiones del negocio antes de empezar** (cómo se cobra hoy, quién factura y con qué proveedor; preguntas N1 y N2 de [PROPUESTAS-DE-VALOR.md](PROPUESTAS-DE-VALOR.md)).
- **Cobro en línea (20 h):** confirmación de pago manual con comprobante y, después, pasarela local con webhook firmado y tabla `payments`.
- **Facturación electrónica (28 h):** con proveedor autorizado, datos fiscales en el checkout, emisión al pagar y guardado de número, clave de acceso y PDF.

---

# Fases futuras — cosas nuevas que se pueden hacer (propuestas a validar)

Esto **no es compromiso**: son ideas ordenadas por fase para elegir con datos. Con el panel de ventas ya activo (sprint 13) habrá cifras reales para decidir; se descarta lo que no aplique. `[Inferencia]` marca lo que asumo del negocio sin haberlo confirmado; por el nombre y el contenido del sitio, el negocio combina servicio de internet y venta de equipos, pero no lo verifiqué.

## Fase 10 — Crecimiento de ventas

**Objetivo:** vender más con lo que ya existe.

| Idea | Qué aporta | Valor | Esfuerzo | Condición |
|---|---|---|---|---|
| **Búsqueda y filtros mejores** | Filtrar por marca, rango de precio y disponibilidad; ordenar; búsqueda tolerante a errores de escritura; registrar las búsquedas sin resultado para completar el catálogo. | Alto | Medio | Catálogo con marca y stock (fase 7). |
| **Promociones y cupones** | Descuentos por período, cupones, precio por volumen y kits (por ejemplo equipo + accesorios). | Alto | Medio | Descuento con vigencia en el producto (fase 7). |
| **SEO de producto y categoría** | Páginas por categoría, datos estructurados de producto, sitemap dinámico y textos propios en lugar de los del proveedor. | Alto | Bajo-medio | URLs de producto estables (fase 3). |
| **Pedido y avisos por WhatsApp** | Confirmaciones de pedido, cambios de estado y enlace de pago por WhatsApp. El sistema ya envía al administrador un aviso con enlace a WhatsApp. | Medio-alto | Medio | Confirmar si el negocio atiende por WhatsApp. |
| **Reseñas y preguntas de producto** | Confianza para comprar y contenido útil para SEO. | Medio | Medio | Moderación desde el panel. |
| **Productos relacionados y recomendados** | "Compatible con" y "quienes compraron esto compraron". | Medio | Bajo-medio | Historial de pedidos suficiente. |
| **Carritos abandonados con aviso** | Correo o WhatsApp al cliente que dejó el carrito. | Medio | Bajo | Carrito asociado a la cuenta (fase 7). |

## Fase 11 — Clientes empresariales y servicios
`[Inferencia]` Tiene sentido si el negocio vende a empresas o a clientes que contratan internet.

| Idea | Qué aporta | Valor | Esfuerzo | Condición |
|---|---|---|---|---|
| **Cuentas empresariales (B2B)** | Lista de precios por cliente, compra recurrente, aprobación interna del pedido y cotización masiva. | Alto | Alto | Que existan clientes que compren en volumen. |
| **Portal del cliente** | Historial de pedidos y facturas, garantías, soporte y estado de servicios contratados. | Medio-alto | Alto | Definir si hay servicio de internet con contrato. |
| **Garantías y postventa** | Registro de garantía por serie, solicitud de soporte o cambio, seguimiento de la reparación. | Medio | Medio | Definir la política de garantía. |
| **Tickets de soporte** | Reclamos y consultas con estado, en lugar de correos sueltos. | Medio | Medio | Volumen de consultas que lo justifique. |
| **Pagos recurrentes** | Cobro automático de suscripciones o servicios mensuales. | Alto si hay servicios | Alto | Pasarela con débito recurrente y facturación (fase 9). |

## Fase 12 — Plataforma e innovación

| Idea | Qué aporta | Valor | Esfuerzo | Condición |
|---|---|---|---|---|
| **Monitoreo, alertas y respaldos automáticos** | Saber cuándo el sitio falla antes de que avise un cliente; recuperación probada. | Alto | Bajo-medio | Se puede adelantar a cualquier fase. |
| **Entorno de staging y despliegue automático** | Probar antes de publicar y volver atrás rápido. | Alto | Medio | Se puede adelantar a la fase 1 si hay hosting disponible. |
| **Sincronización automática con proveedores** | Precios y existencias de Intcomex y otros, con reglas de margen; hoy es un script manual. | Alto | Medio-alto | Acceso a una API o archivo periódico del proveedor. |
| **Guías de transporte automáticas** | Generar la guía y el seguimiento con el transportista desde el pedido. | Medio | Medio | Transportista con API. |
| **Catálogos externos** | Publicar el catálogo en Google Merchant y Meta, y recibir pedidos de ahí. | Medio | Medio | Catálogo completo y con SEO (fase 10). |
| **Multi-sucursal** | Stock por local y retiro en tienda. | Medio | Alto | Que existan varios puntos de venta. |
| **Asistente con IA** | Recomendar equipo según la necesidad del cliente, redactar fichas de producto y clasificar el catálogo importado. | Medio | Medio | Catálogo estructurado; revisión humana de lo generado. |
| **Contabilidad / ERP** | Pedidos y facturas hacia el sistema contable, sin digitar. | Medio | Medio-alto | Saber qué sistema usa quien lleva la contabilidad. |
| **Aplicación instalable (PWA) y notificaciones** | Acceso rápido, catálogo con poca conexión, avisos de pedido. | Bajo-medio | Medio | Uso móvil significativo (medirlo antes). |
| **Accesibilidad e idiomas** | Cumplir criterios de accesibilidad y, si hay demanda, otro idioma. | Bajo-medio | Medio | Demanda real. |

**Dos ideas para adelantar:** el monitoreo con respaldos y el entorno de staging. Reducen el riesgo de todas las demás fases y no dependen de ellas.

## Cómo elegir entre las fases opcionales y futuras

1. En el sprint 13, mirar qué dice el panel de ventas: dónde se pierden clientes (búsquedas sin resultado, cotizaciones sin responder, carritos abandonados).
2. Elegir la fase cuya condición ya se cumpla y que ataque ese punto.
3. Estimar entonces en horas, con el mismo método de arriba, antes de comprometer sprints.

---

## Reglas de trabajo

- **Cada sprint cierra con `devflow auditar`.** El reporte versionado (`audit_vNN_AAMMDD-HHMM`) debe mostrar los hallazgos de ese sprint como `fixed`.
- **Un sprint a la vez:** lo que no entra se mueve al siguiente; no se agranda el sprint.
- **No se mezclan fases:** no se construyen pedidos sobre un login abierto.
- **Copia antes de tocar datos:** respaldo de la base antes de DEV-006, DEV-023 y de cualquier migración.
- **Despliegue en horario tranquilo** y con la versión anterior a mano para volver atrás.

## Si hay menos tiempo

1. **No se pueden saltar:** DEV-001, 002, 003, 004, 005, 006 y 007 (sprints 1–2). Son los accesos abiertos.
2. Las fases 4 y 5 pueden esperar a la 3 si el negocio lo pide; la seguridad no.
3. Si un sprint se atrasa, se corre todo lo siguiente una semana; los plazos son referencias, no compromisos.
