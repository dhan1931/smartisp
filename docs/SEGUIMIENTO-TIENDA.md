# Seguimiento de tienda y administración — SmartISP

Registro vivo de requerimientos, estado real, dependencias, prioridades y objetivos
funcionales/visuales de la tienda y del panel administrativo. Agregar cada nuevo pedido
aquí antes de implementarlo, actualizar el estado al terminar y no marcar nada como
desplegado sin comprobar producción.

**Última actualización:** 2026-10-09

> Este archivo reemplaza a `docs/seguminto-consolidado.md` (nombre con typo, contenido
> fusionado aquí) — ver el registro de cambios al final.

---

## 1. Objetivo general

- La tienda pública tiene una experiencia consistente, rápida y comercial.
- El catálogo, categorías y fichas usan una misma lógica visual y funcional.
- El panel administrativo es claro, escalable y fácil de usar.
- Lo que se configura en administración se refleja realmente en la tienda.
- Los banners, categorías, íconos, filtros y destacados son plenamente dinámicos.
- Nada se marca como desplegado sin confirmar producción.

---

## 2. Estado real

### Hecho y desplegado

| ID | Requerimiento | Estado / evidencia |
| --- | --- | --- |
| PUB-01 | Densidad de productos, cuatro columnas en escritorio, proporción de imagen. | **Desplegado.** PR #5; invalidación de caché en PR #6. Se gestiona desde configuración. |
| PUB-02 | Imagen/miniatura de la ficha de producto más grande. | **Desplegado.** PR #7. |
| ADM-01 | Editor de campañas separado de las pestañas del editor de catálogo. | **Desplegado** como `campanas.html`, PR #8. |
| ADM-02 | Acceso a campañas en el menú de administración, caché del menú corregida. | **Desplegado y verificado** en `main`, PR #10. Etiqueta: "Campañas y banners". |
| PUB-03 | Páginas públicas de categorías/productos destacados, banners de categoría, logo público. | **Desplegado** en PRs #3, #4, #11. Logo canónico: `assets/img/logo.webp`. |
| CAT-01 | Datos dinámicos de categorías (banner, descripción, ícono) administrables. | **Desplegado.** PR #9 fusionado; migraciones 010/011 confirmadas aplicadas en producción vía `migrations/015` (2026-10-09). |
| CAM-01 / CAM-02 | Editor de campañas ampliado (formatos, opacidad, chips, galería) y accesos promocionales por banner. | **Desplegado.** Migraciones 012/013 confirmadas aplicadas en producción vía `migrations/015` (2026-10-09). |
| CAM-04 | Layout de banners panorámico, logo canónico sin fallback. | **Desplegado** en PR #11 (`7dbcc1e`). |
| CAT-02 | Categorías destacadas no quedan vacías si falla la consulta de metadatos. | **Desplegado** en PR #13 (`e4ec1e3`): fallback SQL + render cliente desde `/api/auth/catalog`. |
| PUB-09 | Categorías/conteos/fotos cargan completos y estables tras recargas. | **Desplegado** en PR #13: la API pública aporta categorías al hub cuando el render PHP viene vacío. |
| PUB-11 (parcial) | Página dinámica de categoría con sidebar, precio y disponibilidad real. | **Mejorado 2026-10-09**: sidebar con badge de categoría activa, buscador interno, secciones colapsables, orden real por precio/nombre. Filtro de stock funcional pero sin datos (`product_inventory` vacía en este corte). Sin filtro de "Marca" a propósito: no existe esa columna en `products_rows`. |
| ADM-04 (parcial) | Sidebar del panel admin reorganizado por dominios. | **Desplegado 2026-10-09**: grupos Operación/Catálogo/Tienda/Análisis/Sistema; "Catálogo" renombrado a "Productos"; `editor-landing.html` ("Contenido") ya no queda huérfano del menú. Clientes/Inventario/Estadísticas/Reportes mostrados como "Próximamente" (sin página real todavía). |
| H2 (parcial) | Datos estructurados `Offer` completos en ficha de producto. | **Desplegado 2026-10-09**: se agregó `availability` al JSON-LD de `producto.php` (Search Console lo marcaba como campo faltante), calculado con stock real cuando existe. |

### En curso / pendiente

| ID | Requerimiento | Estado / siguiente paso |
| --- | --- | --- |
| PUB-04 | Separar Catálogo, Productos y Categorías en pantallas independientes. | **Parcial.** El sidebar admin ya distingue Productos/Categorías como entradas separadas (2026-10-09), pero siguen siendo páginas construidas por separado sin un alcance final acordado (¿solo pública, solo admin, o ambas?). |
| PUB-05 | Unificar cabecera pública, navegación móvil y logo para todas las páginas. | **Parcial.** `nosotros.html`, `productos-destacados.php`, `categoria.php` comparten `includes/storefront-discovery-nav.php`. Falta auditar el resto de páginas públicas contra la misma plantilla. |
| PUB-06 | Coherencia de cards entre catálogo, categorías y páginas comerciales. | **Parcial.** Densidad e imagen de ficha desplegadas; falta confirmar que todas las vitrinas públicas usan exactamente el mismo componente de card. |
| PUB-07 | Drawer móvil de categorías desde la izquierda, blanco, ~80% ancho, sin enlaces generales. | **Pendiente.** La navegación móvil actual no cumple literalmente ese concepto. |
| PUB-08 | Recomendaciones de ficha de producto rotativas y contextuales. | **Pendiente.** Inspeccionar atributos reales disponibles antes de prometer compatibilidad que no existe en los datos. |
| PUB-10 | Cards compartidas de destacados/categorías: 4 por fila, disponibilidad real, botón Agregar conectado. | **En curso.** Base de card ya consistente en `nosotros`/`productos-destacados`/`categoria.php`; falta auditoría final cross-página. |
| PUB-12 | Solo familias principales destacadas usan banners; páginas internas permiten imagen opcional o ícono por subcategoría. | **Pendiente.** El editor actual administra banners por categoría padre, no por subcategoría individual; definir persistencia/API antes de implementar. |
| ADM-03 | Acceso al panel admin visible en cabeceras públicas solo para administradores autenticados. | **Desplegado en el experimento React** (`StorefrontHeader`, vía `/api/auth/me`); **pendiente en el PHP real** fuera de `includes/storefront-discovery-nav.php` (`public-admin-link.js` ya lo hace ahí). |
| ADM-04 (resto) | Home del dashboard con stat cards, gráficas y tablas con datos reales (no simulados). | **Pendiente.** Sidebar ya reorganizado; falta el contenido real del home (ventas/pedidos hoy, pendientes de pago, productos más vendidos, stock bajo) con queries reales contra `orders_rows`/`products_rows`. Estadísticas/Reportes como secciones aparte: **sin construir**, marcadas "Próximamente" en el menú a propósito. |
| CAM-03 | Imágenes de campañas rotas (404) tras publicarse. | **Pendiente de verificación.** Última revisión (2026-10-09) encontró 3 URLs de `/uploads/campaigns/` devolviendo 404; recargar desde el editor. |
| OPS-01 | Desplegar por etapas, confirmar dependencias SQL antes de fusionar. | **Recurrente.** El usuario ejecuta las migraciones; no desplegar código dependiente sin esa confirmación. |

---

## 3. Problemas principales detectados

### 3.1. Inconsistencia entre productos, catálogo y categorías
- Nomenclatura inconsistente entre niveles de taxonomía (ej. "Componentes y Almacenamiento" en el nav vs "Componentes Informáticos" en categorías destacadas) — **no es necesariamente un bug de datos**, puede ser una jerarquía de dos niveles sin explicar visualmente.
- Falta una única fuente de verdad para nombres, íconos, banners y subcategorías que el admin controle y la tienda refleje exactamente.

### 3.2. Cabecera, logo y navegación pública
- No todas las páginas públicas usan la misma implementación de cabecera todavía.
- Objetivo: una sola cabecera, un logo canónico configurable, fallback controlado si falla (nunca mostrar marca vieja por accidente), comportamiento consistente desktop/móvil.

### 3.3. Página de categorías dinámicas
- El render objetivo (hero con banner, breadcrumb, descripción, conteo, filtros laterales reales, cards compartidas) ya está mayormente implementado en `categoria.php`; falta terminar de armonizar con el resto del catálogo.

### 3.4. Filtros contextuales por categoría
- Solo mostrar filtros cuyos atributos existan realmente en la base de datos — no inventar características vacías por estética (ej. no hay "Marca" real en `products_rows`).
- Ejemplos de filtros que sí tendrían sentido si hubiera el dato: Componentes Informáticos (tipo, precio, socket, capacidad), Protección de Energía (tipo de UPS, VA, potencia), Redes (tipo de equipo, velocidad, puertos, PoE).

### 3.5. Ficha de producto
- Base sólida (SKU, precio, disponibilidad, carrito, WhatsApp); falta integrarla visualmente con el resto del sitio, mejorar galería, recomendaciones contextuales y especificaciones técnicas estructuradas.

### 3.6. Editor administrativo de campañas y banners
- Dirección ya validada (visible/no visible, programación, preview, control por pieza); falta carrusel propio por banner, formatos múltiples, opacidad/ajuste, borradores.

### 3.7. Editor administrativo de categorías
- Falta buscador fuerte de categorías, edición más fluida de banner/descripción/ícono, y consistencia real entre el ícono elegido en admin y el que se muestra en el front.

---

## 4. Lo que falta por resolver (detalle por área)

**A. Arquitectura del panel admin** — separar conceptualmente Productos / Categorías y subcategorías / Campañas y merchandising / Importador de Excel; decidir el alcance final de PUB-04.

**B. Cabecera, logo y navegación** — centralizar la cabecera pública en todas las páginas; logo sin flashes ni fallback accidental a marca vieja; drawer móvil de categorías (izquierda, blanco, ~80% ancho, búsqueda, conteos, subcategorías, sin enlaces generales).

**C. Tienda pública: catálogo y categorías** — un solo componente de card de producto en todas las vitrinas; imágenes/botones/jerarquía visual consistentes; categoría dinámica con el mismo sistema visual del catálogo principal; filtros reales y contextuales; carga estable (conteos, fotos, recargas rápidas, caché).

**D. Categorías dinámicas y metadatos** — categorías administrables con nombre, slug, ícono, banner, descripción, visibilidad y orden; una sola fuente de verdad que se refleje en categorías destacadas, menús, filtros y cards.

**E. Íconos dinámicos admin ↔ front** — detectar íconos hardcodeados que no respetan la configuración del admin; normalizar un solo sistema (nombre guardado → renderer → fallback si no existe).

**F. Campañas, banners y merchandising** — cerrar el editor avanzado (formatos, opacidad, preview, programación, borrador/publicado, carrusel por banner); render público reflejando carruseles, fechas activas y visibilidad.

**G. Ficha de producto y recomendaciones** — consistencia visual con el resto del sitio; recomendaciones por categoría/subcategoría/compatibilidad real (no aleatorias); especificaciones técnicas estructuradas progresivas.

**H. SEO, indexación y estructura pública** — revisar HTML inicial del catálogo (depende de JS, fallback limitado para crawlers); validar sitemap XML, robots, canonical, datos estructurados `Product`/`Offer` (`availability` ya agregado 2026-10-09), duplicados por filtros, productos ocultos no indexados.

---

## 5. Prioridades funcionales

- **P0 — Integridad y consistencia base**: unificar taxonomía/nombres/jerarquía de categorías; resolver logo/cabecera pública; ✅ migraciones de categorías/campañas confirmadas; que el admin gobierne realmente el front en categorías, íconos y banners.
- **P1 — Experiencia de catálogo**: unificar cards entre tienda, categorías y vitrinas; filtros contextuales verdaderos; mejorar ficha y recomendaciones.
- **P2 — Merchandising y configuración comercial**: terminar editor avanzado de campañas, carruseles por banner, programación/estados, accesos promocionales vinculados.
- **P3 — SEO, rendimiento y conversión**: indexación y HTML inicial; ✅ `availability` en structured data; mejorar carga de imágenes/conteos; pruebas reales de compra en móvil.

---

## 6. Orden de trabajo acordado

1. ~~Cerrar y verificar CAM-01/CAM-02/ADM-03 localmente; aplicar migración 013/014.~~ **Hecho** (confirmado 2026-10-09 vía `migrations/015`).
2. ~~Resolver CAT-01: aplicar 010/011, fusionar PR #9.~~ **Hecho.**
3. Reponer las 3 imágenes de campaña rotas (CAM-03) desde el editor.
4. Implementar PUB-12 (decisión de almacenamiento para visuales por subcategoría); luego centralizar cabecera/logo públicos (PUB-05) y resolver el drawer móvil (PUB-07).
5. Decidir el alcance de PUB-04 (pantallas públicas, administrativas o ambas) y diseñar la navegación sin duplicar funciones.
6. Construir el home del dashboard admin con datos reales (ADM-04 resto) antes de avanzar a Estadísticas/Reportes.
7. Diagnosticar PUB-08 (recomendaciones) y PUB-06 (consistencia visual completa).
8. SEO y conversión: sitemap, canonical, resto de datos estructurados, pruebas reales de compra en móvil.

---

## 7. Criterios de aceptación

- **Cabecera/branding**: todas las páginas públicas usan la misma cabecera; el logo no parpadea ni cambia a uno viejo; la versión móvil tiene un drawer funcional de categorías.
- **Categorías**: se administran desde un solo origen; íconos del admin coinciden exactamente con el front; banners configurados se reflejan en la tienda; la categoría dinámica usa el diseño visual esperado.
- **Catálogo y cards**: cards consistentes entre todas las pantallas públicas; mismo sistema visual en tienda y categorías; grilla de escritorio en 4 columnas.
- **Campañas**: cada banner con carrusel propio, programable, con visibilidad controlable y preview confiable.
- **Ficha**: recomendaciones contextuales, mejor bloque visual, consistencia con el resto del sistema.
- **Operación**: nada se marca como desplegado sin verificación en producción; cada cambio dependiente de SQL se documenta antes del despliegue.

---

## 8. Dependencias técnicas y operativas

- Migraciones 010-015: **confirmadas aplicadas en producción** (2026-10-09, vía `migrations/015_consolidar_pendientes_y_schema_migrations.sql`).
- `migrations/005` (originalmente borraba `password_resets_rows`): **decisión revertida** — la tabla se conserva; el archivo quedó sin acción.
- El usuario ejecuta las migraciones SQL manualmente; documentar nombre, orden y confirmación; no fusionar ni desplegar cambios dependientes sin esa confirmación.

---

## 9. Registro de cambios

- **2026-10-09:** consolidado el estado de PRs #3–#10, PR #9 abierto, cambios locales de campañas/migración 012.
- **2026-10-09:** confirmado que densidad de 4 columnas y miniatura ampliada están desplegadas; separación completa de Catálogo/Productos/Categorías aún no implementada.
- **2026-10-09:** se agrega la necesidad de acceso al panel desde todas las cabeceras públicas para administradores autenticados.
- **2026-10-09:** usuario confirma migración 013 aplicada; PR #9 fusionado y publicado en Hostinger. Tres imágenes de campaña con 404 detectadas (CAM-03).
- **2026-10-09:** PR #11 despliega logo canónico y layout de campañas; PR #13 añade fallback cliente desde catálogo, 36 categorías visibles verificadas.
- **2026-10-09:** usuario confirma migración 014 aplicada.
- **2026-10-09:** verificado contra un corte real de producción (`basecompletaactualizada.sql`) que migraciones 009, 011-014 **nunca se habían aplicado** pese a estar escritas; se crea `migrations/015` para ponerlas al día y sembrar `schema_migrations`. Se revierte la decisión de borrar `password_resets_rows` (migración 005 retirada). Usuario aplica 015 en producción.
- **2026-10-09:** `categoria.php` mejorado (sidebar con buscador/colapsables, orden real por precio/nombre). `producto.php` corrige `availability` faltante en structured data (reporte de Search Console). `assets/js/admin-nav.js` reorganizado por dominios (Operación/Catálogo/Tienda/Análisis/Sistema). Desplegado a `main` vía PR #16.
- **2026-10-09:** limpieza de repositorio — 3 worktrees locales vacías eliminadas, `docs/007_expandir_base_segura.sql` (copia duplicada y desactualizada de `migrations/007`) y boilerplate ajeno (`docs/START.html`, `docs/QUICKSTART.txt`) retirados. Este archivo fusiona y reemplaza a `docs/seguminto-consolidado.md`.
