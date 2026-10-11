# Backlog: editor de banners multi-página (reingeniería)

Anotado el 2026-10-11 a pedido explícito del usuario (quedándose sin tokens en la sesión)
para no perder la idea.

## Actualización 2026-10-11: la parte "hero editable por página" ya se hizo

Se implementó la versión reducida de los puntos 1 y 2 de abajo ("Qué se pediría construir"),
sin la reingeniería completa de secciones/layouts:

- `STOREFRONT_CAMPAIGN_ZONES` en `api/handlers/campaigns.php` generaliza `code` a una lista
  (`store-home`, `store-secondary`, `nosotros`, `servicios`) en vez de 2 valores hardcodeados.
  Agregar una página más es agregar un string a esa lista + una entrada en `ZONE_CONFIG` de
  `campanas.html` + el mismo snippet de fetch/render que ya tienen `nosotros.html`/
  `servicios.html` (~20 líneas, ver esos archivos).
- `campanas.html`: el selector de zona pasó de 2 radio buttons a 4, y toda la lógica
  condicional (`activeZone==='store-home'?A:B`) se centralizó en un objeto `ZONE_CONFIG` en vez
  de ternarios repetidos — necesario para que agregar páginas no signifique tocar N lugares.
- `nosotros.html` y `servicios.html`: el `<section class="hero">` de texto plano ahora se
  convierte en hero con imagen de fondo + overlay cuando el admin publica un banner para esa
  zona (eyebrow/título/subtítulo de la campaña reemplazan al texto fijo); si no hay banner
  publicado, se ve exactamente igual que antes (fallback a texto plano, sin romper nada).
  No usa carrusel ni botón propio todavía (solo el primer slide activo, sin controles de
  carrusel) — si se necesita carrusel real ahí, falta portar esa parte de `tienda.html`.
- Verificado end-to-end contra MariaDB local: guardar un banner para zona `nosotros` desde el
  editor real (Playwright) → el endpoint público lo expone → `nosotros.html` lo renderiza con
  imagen, overlay y texto reales.

**Lo que sigue sin existir** (puntos 3 y 4 de abajo, sin cambios): grillas de tarjetas,
carrusel de "proyectos realizados", formulario de cotización, tipos de sección con layout
propio. Eso sigue siendo la reingeniería grande — lo de arriba fue la pieza chica y barata
(un hero editable por página), no el sistema completo de secciones.

## Pedido original

> campanas y banners me debería dejar poner banners en otras páginas como categorías,
> nosotros, servicios y determinar cómo se ven — todo un selector de en qué página van
> los banners.

Con 3 capturas de referencia. **Importante — verificado al escribir esto, corrigiendo una
primera lectura apresurada: estas capturas NO son el estado real del sitio, son mockups de
hacia dónde se quiere llegar.** `nosotros.html` (69 líneas) y `servicios.html` (75 líneas) hoy
son páginas mínimas: un solo `<section class="hero">` con eyebrow + `<h1>` + `<p>`, sin imagen,
sin grillas, sin carrusel, sin marcas. Nada de lo que muestran las capturas existe todavía en
esos archivos. Lo que las capturas piden construir (verificar contra las imágenes originales si
se retoma esto, por si la lectura cambia):

- **Categorías** (`/categorias-destacadas`): hero con imagen de fondo ("Categorías de
  Productos" + franja de "marcas líderes") arriba de la grilla de categorías/catálogo real
  (esta página sí tiene contenido real detrás, ver `categorias-destacadas.php`, pero sin ese
  hero con imagen).
- **Nosotros**: hero con imagen (hoy es sin imagen) + grilla "Nuestras especialidades" (4
  tarjetas con ícono) + "Nuestra diferencia" (4 tarjetas) + "Experiencia que genera confianza"
  (carrusel de proyectos realizados, imagen+título+descripción) + franja de marcas + CTA final.
- **Servicios**: hero con imagen + grilla "Servicios Especializados" (6 tarjetas con foto) +
  "¿Cómo trabajamos?" (4 pasos numerados) + formulario de cotización + "Proyectos Realizados"
  (carrusel) + franja de marcas.

O sea: esto no es "conectar secciones existentes a un editor", es **construir estas secciones
desde cero** (contenido + diseño) y además hacerlas editables. Dos proyectos en uno, y el
primero (construir las páginas) es el más grande de los dos.

## Qué existe hoy (arquitectura actual)

- `storefront_campaigns` tiene una fila por `code` (`store-home`, `store-secondary`), cada una
  con sus propios `storefront_campaign_slides` (banners) + imágenes de galería.
- `campanas.html` tiene un selector de **zona** fijo de 2 opciones (radio buttons), hardcodeado
  en el HTML: "Banner principal" (`store-home`) / "Banners secundarios" (`store-secondary`).
- `tienda.html` es la única página que lee de `storefront_campaigns` (vía
  `/api/auth/store-campaigns?zone=...`), en sus dos modos (portada curada vs modo catálogo,
  ver `isCategoriasView` en `tienda.html`).
- `nosotros.html`, `servicios.html`, `categorias-destacadas.php` (vía `/categorias-destacadas`,
  que hoy en realidad redirige a `tienda.html` en modo catálogo — ver conversación previa)
  NO tienen banners editables; su hero/secciones son texto e imágenes fijas en el archivo.

## Qué se pediría construir

1. **Generalizar `code` a N páginas reales**, no solo 2 valores fijos. Cada página
   (`nosotros`, `servicios`, `categorias`, y las que se agreguen) tendría su propia fila/zona en
   `storefront_campaigns`, con su propio hero editable.
2. **Selector de página en `campanas.html`**: en vez de 2 radio buttons hardcodeados, una lista
   dinámica de páginas disponibles (probablemente otra tabla, p.ej. `storefront_pages` con
   `code`, `label`, `layout_sections` disponibles para esa página).
3. **Tipos de sección por página**, porque cada página tiene secciones distintas, no solo
   "hero + banners secundarios" como tienda.html:
   - Hero (ya existe el sistema, reutilizable).
   - Grilla de tarjetas con imagen (Nosotros: "especialidades"/"diferencia"; Servicios:
     "servicios especializados") — hoy fijas en el HTML, con texto/iconos propios.
   - "Proyectos realizados" (carrusel de tarjetas imagen+título+descripción) — aparece igual en
     Nosotros y Servicios, candidato a sección reutilizable entre páginas.
   - Franja de marcas — esta YA es real y reutilizable (`storefront_brands`, recién construido),
     solo falta decidir en qué páginas se muestra (hoy solo en tienda.html).
4. **El editor necesitaría, por sección, un selector de layout** (cómo se ve: cuántas columnas,
   con imagen o sin ella, etc.), similar al selector de "Presentación" que ya existe para el
   hero de tienda.html pero generalizado a cualquier tipo de sección.

## Por qué es reingeniería y no un parche

El sistema actual asume implícitamente "hay un hero y como mucho una zona secundaria de
banners, y es siempre para tienda.html". Pasar a "N páginas, cada una con M secciones de
tipos distintos, cada sección con su propio layout" cambia:
- El modelo de datos (`storefront_campaigns.code` deja de ser un enum fijo).
- El editor completo (`campanas.html` pasaría de 2 pestañas fijas a una lista dinámica de
  páginas × secciones).
- Cada página pública (`nosotros.html`, `servicios.html`, etc.) necesitaría el mismo tipo de
  JS que ya tiene `tienda.html` para pedir su zona y renderizarla, en vez de contenido fijo.

## Siguiente paso sugerido (cuando se retome)

Lo más barato con valor real inmediato, sin esperar a decidir nada del editor genérico: agregar
la franja de marcas (`storefront_brands`, ya construida y probada en `tienda.html`) también en
`nosotros.html` y `servicios.html`, debajo del hero de texto que ya tienen — mismo patrón que
`loadStoreBrands()` en `tienda.html` (fetch a `/api/auth/brands`, renderizar si hay marcas
activas). No requiere tocar `storefront_campaigns` ni el editor todavía, es trabajo aislado y
de bajo riesgo.

Después de eso, el resto (hero con imagen, grillas, proyectos realizados, formulario) es
trabajo de diseño/contenido nuevo primero, y recién ahí vale la pena evaluar si conviene
generalizar el selector de página/zona del editor o seguir agregando zonas fijas una por una
(más simple, menos flexible) en vez del sistema genérico completo.
