# Seguimiento consolidado de tienda y administración — SmartISP

Registro vivo de requerimientos, estado real, dependencias, prioridades y objetivos funcionales/visuales de la tienda y del panel administrativo.

**Última actualización:** 2026-10-09

---

# 1. Objetivo general

Consolidar la evolución de SmartISP para que:

- la tienda pública tenga una experiencia consistente, rápida y comercial,
- el catálogo, categorías y fichas usen una misma lógica visual y funcional,
- el panel administrativo sea claro, escalable y fácil de usar,
- lo que se configura en administración se refleje realmente en la tienda,
- los banners, categorías, íconos, filtros y destacados sean plenamente dinámicos,
- no se marque nada como desplegado sin confirmar producción.

---

# 2. Estado general resumido

## Ya hecho y desplegado

| ID | Requerimiento | Estado / evidencia |
| --- | --- | --- |
| PUB-01 | Configurar densidad de productos y mostrar cuatro columnas en escritorio, con proporción de imagen adecuada. | **Desplegado.** PR #5; invalidación de caché en PR #6. La opción se gestiona desde configuración. |
| PUB-02 | Hacer más grande la imagen/miniatura de la ficha de producto. | **Desplegado.** PR #7. |
| ADM-01 | Editor de campañas separado de las pestañas del editor de catálogo. | **Desplegado** como pantalla `campanas.html`, PR #8. |
| ADM-02 | Mostrar el acceso a campañas en el menú de administración y corregir la caché del menú. | **Desplegado y verificado** en `main`, PR #10. Etiqueta actual: “Campañas y banners”. |
| PUB-03 | Páginas públicas de categorías destacadas y productos destacados, banners de categorías y actualización inicial del logo público. | **Base desplegada** en PRs #3 y #4. Aún **no equivale** a una cabecera pública totalmente centralizada ni resuelve el fallback al logo legado. |

---

## En curso / pendiente

| ID | Requerimiento | Estado / siguiente paso |
| --- | --- | --- |
| PUB-04 | Separar Catálogo, Productos y Categorías en pantallas independientes. | **No implementado como tres pantallas nuevas.** Existen páginas públicas y una página de catálogo, pero no es una separación completa del área administrativa. Hay que definir alcance antes de construir. |
| PUB-05 | Unificar cabecera pública, navegación móvil y logo para todas las páginas. | **Pendiente.** Algunas páginas usan plantilla compartida y otras mantienen estructuras propias. Hay fallback a logo antiguo. Debe centralizarse markup, estilos y comportamiento. |
| PUB-06 | Hacer coherente el diseño de tarjetas e imágenes del listado entre catálogo y páginas comerciales/categorías. | **Parcial.** Ya se desplegó densidad de 4 columnas y mejor miniatura, pero aún falta unificar completamente las cards entre tienda principal, categorías y páginas comerciales. |
| PUB-07 | Drawer móvil de categorías desde la izquierda, blanco, ~80% ancho, sin enlaces generales, con búsqueda, conteos y subcategorías. | **Pendiente.** La navegación móvil actual no cumple literalmente ese concepto. |
| PUB-08 | Mejorar recomendaciones en ficha de producto para que roten y sean contextuales por categoría, subcategoría o compatibilidad. | **Pendiente.** Primero inspeccionar atributos reales disponibles. Si faltan etiquetas/campos, proponer migración. |
| PUB-09 | Asegurar que categorías, conteos y fotos del catálogo/categorías carguen completo y estable incluso tras recargas rápidas. | **Pendiente de regresión en producción.** Requiere diagnóstico de respuesta, caché, fallback y orden de carga. |
| CAT-01 | Datos dinámicos de categorías y descripciones/banners administrables por categoría. | **PR #9 abierto.** Incluye `migrations/011_category_metadata.sql`. Falta confirmar que el usuario aplicó migraciones 010/011 en producción antes de fusionar/desplegar dependencias. |
| CAM-01 | Rediseñar y ampliar el editor de banners/campañas: más formatos, opacidad, imagen real, chips, fechas, preview, borradores, carruseles por banner, etc. | **En curso local.** Corregida inicialización del editor. Falta terminar render público, pruebas visuales, PR y despliegue. |
| CAM-02 | Relacionar accesos promocionales/chips con sus banners y conservar gestión comprensible. | **Implementado localmente** con `promo_chip_label/icon/target_url` por banner. Requiere `migrations/013_campaign_slide_promotions.sql`, pruebas, PR y despliegue. |
| ADM-03 | Mostrar acceso al panel admin en todas las cabeceras públicas solo para administradores autenticados. | **En implementación local** usando validación `/api/auth/me`. Falta prueba y despliegue. |
| OPS-01 | Desplegar por etapas, confirmar dependencias SQL y verificar cada cambio en producción. | **Recurrente.** El usuario ejecuta migraciones SQL; no desplegar funcionalidades dependientes sin confirmación. |
| OPS-02 | Publicar ahora cualquier cambio adicional listo. | **Revisado 2026-10-09:** no hay código nuevo completo e independiente de esquema para desplegar. PR #9 espera confirmación de migración 011; campañas ampliadas requieren 012/013 y aún no están cerradas. |

---

# 3. Problemas principales detectados

## 3.1. Inconsistencia entre productos, catálogo y categorías
Actualmente hay diferencias entre:
- la tienda principal,
- las páginas dinámicas de categoría,
- las páginas comerciales como productos destacados o categorías destacadas.

Problemas observados:
- distintos sistemas de cards,
- distinta presentación visual,
- banners y headers de categorías con estilos diferentes,
- nomenclatura inconsistente en categorías (ej. “Componentes y Almacenamiento” vs “Componentes Informáticos”),
- falta de una única fuente de verdad para nombres, íconos, banners y subcategorías.

### Objetivo
La tienda debe reflejar una **taxonomía única**, clara y administrable desde un solo origen.

---

## 3.2. Cabecera, logo y navegación pública
La cabecera pública todavía no está totalmente unificada.

Problemas observados:
- algunas páginas tienen implementaciones distintas,
- el logo a veces cambia o carga uno legado,
- existe una sensación de “blip” o cambio visible al cargar,
- algunas páginas no heredan exactamente la misma estructura de navegación.

### Objetivo
- Una sola implementación de cabecera pública,
- un solo logo canónico configurable,
- si el logo falla, dejar espacio vacío o un fallback controlado,
- nunca mostrar una marca antigua de forma accidental,
- comportamiento consistente en desktop y móvil.

---

## 3.3. Página de categorías dinámicas
La idea objetivo ya está clara:
- header hero de categoría con banner,
- breadcrumb,
- descripción,
- conteo,
- filtros laterales reales,
- cards modernas compartidas con el resto del catálogo.

Problemas actuales:
- el diseño real todavía no coincide del todo con el render objetivo,
- la página de categoría no usa exactamente la misma lógica visual que la tienda principal,
- los banners no siempre se renderizan con la composición esperada,
- los filtros aún no siempre son contextuales o completos,
- fichas, miniaturas y estilos no están plenamente armonizados.

### Objetivo
La pantalla dinámica de categoría debe parecer parte del mismo sistema visual del catálogo principal.

---

## 3.4. Filtros contextuales por categoría
Actualmente las categorías dinámicas necesitan filtros reales y útiles.

### Recomendación funcional
Solo mostrar filtros si existen atributos reales en la data.

Ejemplos:

| Categoría | Filtros recomendados |
| --- | --- |
| Componentes Informáticos | Tipo, marca, precio, socket, capacidad |
| Protección de Energía | Tipo de UPS, VA, potencia, voltaje |
| Redes | Tipo de equipo, velocidad, puertos, PoE |
| Impresión | Tecnología, marca, conectividad, funciones |
| Seguridad | Resolución, tipo de cámara, alimentación |

### Objetivo
No inventar filtros vacíos. Mostrar solo filtros con datos útiles y relevantes.

---

## 3.5. Ficha de producto
La ficha actual ya tiene una base, pero todavía no está alineada visualmente con el resto.

Problemas:
- las miniaturas / galería no siguen un sistema de diseño totalmente integrado,
- la experiencia visual de la ficha se siente aparte del resto de pantallas,
- falta mejorar recomendaciones relacionadas,
- faltan especificaciones estructuradas más profundas para productos tecnológicos.

### Objetivo
Mejorar la ficha para que:
- tenga consistencia visual,
- venda mejor,
- incluya mejores recomendaciones,
- use estructura técnica útil para clientes de tecnología.

---

## 3.6. Editor administrativo de campañas y banners
La dirección ya gusta bastante:
- visible / no visible,
- programación,
- etiquetas cargadas,
- banner preview,
- control por pieza.

Ahora se quiere llevar a un nivel mejor.

### Falta lograr
- que cada banner pueda tener **su propio carrusel de imágenes**,
- preview más real,
- formatos múltiples,
- opacidad y ajuste de imagen,
- orden y portada,
- borradores,
- mejor editor visual,
- vínculos más claros con chips y accesos promocionales.

---

## 3.7. Editor administrativo de categorías
Todavía se siente limitado comparado con el resultado deseado.

Problemas:
- no hay buscador fuerte de categorías,
- cuesta localizar categorías específicas,
- editar banner, descripción e ícono no es tan fluido,
- los íconos del admin no siempre coinciden con los que salen en el front.

### Objetivo
- mejor experiencia de edición,
- buscador de categorías,
- acciones rápidas,
- consistencia real entre ícono elegido y ícono mostrado.

---

# 4. Todo lo que falta por resolver

## A. Arquitectura y organización del panel admin

### A1. Separar conceptualmente y visualmente:
- **Productos**
- **Categorías y subcategorías**
- **Campañas y merchandising / banners**
- **Importador inteligente de Excel**

### A2. Definir alcance final de PUB-04
Aún no está implementado como tres pantallas nuevas completas.
Se debe decidir si la separación será:
- solo pública,
- solo administrativa,
- o ambas.

---

## B. Cabecera, logo y navegación

### B1. Centralizar cabecera pública
Aplicarla a:
- tienda principal,
- categorías,
- productos destacados,
- categorías destacadas,
- ficha de producto,
- páginas internas públicas.

### B2. Corregir comportamiento del logo
- usar siempre el logo oficial actual,
- evitar flashes del logo viejo,
- definir fallback controlado,
- revisar si el problema es PHP, cache, carga tardía de JS o mezcla de plantillas.

### B3. Navegación móvil
- implementar drawer de categorías desde izquierda,
- fondo blanco,
- 80% ancho aprox.,
- búsqueda,
- conteos,
- subcategorías,
- sin enlaces generales innecesarios.

---

## C. Tienda pública: catálogo y categorías

### C1. Unificar componente de tarjeta de producto
Debe ser el mismo sistema base para:
- tienda principal,
- páginas de categoría,
- páginas de productos destacados,
- otras vitrinas públicas.

### C2. Unificar estilo de imágenes
- proporciones coherentes,
- botones iguales,
- favoritos / corazón,
- agregar al carrito,
- botón secundario / ficha,
- rating/stock si aplica,
- misma jerarquía visual.

### C3. Hacer que la categoría dinámica se parezca al render objetivo
Especialmente:
- hero/header,
- banner,
- breadcrumb,
- filtros,
- cards,
- toolbar,
- ordenamiento.

### C4. Filtros reales y contextuales
- subcategorías,
- marca,
- precio,
- stock,
- atributos según categoría.

### C5. Carga estable del catálogo
Revisar:
- conteos,
- categorías,
- fotos,
- recargas rápidas,
- caché y fallback.

---

## D. Categorías dinámicas y metadatos

### D1. Confirmar y aplicar migraciones 010/011
Sin eso no debe darse por cerrado CAT-01.

### D2. Categorías administrables con:
- nombre,
- slug,
- ícono,
- banner,
- descripción corta,
- texto alternativo,
- afinidad para Excel,
- visibilidad,
- orden/destacados.

### D3. Fuente única de verdad
Lo que se configure en admin debe reflejarse exactamente en:
- categorías destacadas,
- menús,
- filtros,
- cards de categoría,
- páginas de categoría.

---

## E. Íconos dinámicos admin ↔ front

### E1. Detectar dónde están hardcodeados los íconos
En el front todavía hay íconos que no respetan la configuración del admin.

### E2. Normalizar sistema de íconos
- guardar el nombre del ícono elegido,
- mapearlo al renderer correcto,
- usar fallback si no existe,
- reflejarlo en toda la tienda.

### E3. Evitar disputa entre iconografía “manual” y “administrable”
Debe haber una sola lógica.

---

## F. Campañas, banners y merchandising

### F1. Cerrar CAM-01
El editor debe soportar:
- formatos múltiples,
- imagen real con ajuste,
- opacidad,
- preview,
- programación,
- estado visible/no visible,
- borrador/publicado,
- orden,
- portada,
- carrusel por banner.

### F2. Cerrar CAM-02
Relacionar chips / accesos promocionales con los banners sin romper la gestión global existente.

### F3. Render público de campañas
El front debe reflejar:
- carruseles por banner,
- fechas activas,
- visibilidad,
- formatos,
- orden.

---

## G. Ficha de producto y recomendaciones

### G1. Hacer consistente la ficha con el resto
- misma línea visual,
- galería/miniaturas mejor integradas,
- mejor bloque de compra.

### G2. Recomendaciones contextuales
No solo random:
- por categoría,
- por subcategoría,
- por compatibilidad si existe,
- por relación técnica real.

### G3. Especificaciones técnicas estructuradas
Ir agregándolas progresivamente para tecnología:
- compatibilidad,
- dimensiones,
- tipo,
- almacenamiento,
- conectividad,
- potencia, etc.

---

## H. SEO, indexación y estructura pública

### H1. Revisar HTML inicial del catálogo
La portada y `/tienda.html` dependen de JS y muestran un fallback limitado en HTML accesible.

### H2. Validar:
- descubrimiento de URLs por buscadores,
- sitemap XML,
- robots,
- canonical,
- structured data `Product` y `Offer`,
- control de duplicados por filtros,
- productos ocultos no indexados.

### H3. Mejorar estructura HTML pública
Especialmente en listados y catálogo para que el contenido principal sea más indexable y consistente.

---

# 5. Prioridades funcionales consolidadas

## P0 — Integridad y consistencia base
1. Unificar taxonomía, nombres y jerarquía de categorías.
2. Resolver logo/cabecera pública.
3. Confirmar migraciones 010/011 para categorías.
4. Hacer que admin gobierne realmente front en categorías, íconos y banners.

## P1 — Experiencia de catálogo
1. Unificar cards entre tienda principal, categorías y vitrinas.
2. Hacer real el diseño de categoría dinámica con banner.
3. Filtros contextuales verdaderos.
4. Mejorar ficha y recomendaciones.

## P2 — Merchandising y configuración comercial
1. Terminar editor avanzado de campañas.
2. Carruseles por banner.
3. Programación y estados por banner.
4. Accesos promocionales vinculados.

## P3 — SEO, rendimiento y conversión
1. Revisar indexación y HTML inicial.
2. Mejorar carga de imágenes y conteos.
3. Mejorar structured data.
4. Hacer pruebas reales de compra en móvil.

---

# 6. Orden de trabajo recomendado

## Etapa 1
Cerrar y verificar localmente:
- CAM-01
- CAM-02
- ADM-03

Luego:
- solicitar al usuario aplicar migración 013,
- abrir PR,
- desplegar,
- verificar producción.

## Etapa 2
Resolver CAT-01:
- confirmar/aplicar 010 y 011,
- fusionar PR #9,
- validar páginas de categorías en producción.

## Etapa 3
Centralizar cabecera/logo públicos y drawer móvil:
- PUB-05
- PUB-07

Validar:
- tienda,
- categoría,
- categorías destacadas,
- productos destacados,
- ficha de producto,
- móvil y escritorio.

## Etapa 4
Definir el alcance definitivo de PUB-04:
- separar Catálogo / Productos / Categorías
- sin duplicar funciones ni mezclar responsabilidades

## Etapa 5
Diagnosticar:
- PUB-09 (carga estable),
- PUB-08 (recomendaciones),
- PUB-06 (consistencia visual completa).

## Etapa 6
SEO y conversión:
- HTML accesible,
- sitemap,
- canonical,
- datos estructurados,
- flujo de compra real desde móvil.

---

# 7. Criterios de aceptación

## Se considerará correcto cuando:

### Cabecera / branding
- todas las páginas públicas usen la misma cabecera,
- el logo no parpadee ni cambie a uno viejo,
- la versión móvil tenga un drawer funcional de categorías.

### Categorías
- las categorías se administren desde un solo origen,
- los íconos del admin coincidan exactamente con el front,
- los banners configurados se reflejen en la tienda,
- la categoría dinámica use el diseño visual esperado.

### Catálogo y cards
- las cards sean consistentes entre todas las pantallas públicas,
- la tienda y las categorías usen el mismo sistema visual,
- las imágenes se vean bien,
- la grilla en escritorio se mantenga en 4 columnas.

### Campañas
- cada banner pueda tener carrusel propio,
- cada banner pueda programarse,
- cada banner tenga visibilidad controlable,
- exista preview confiable y gestión clara.

### Ficha
- recomendaciones contextuales,
- mejor bloque visual,
- más consistencia con el resto del sistema.

### Operación
- nada se marque como desplegado sin verificación en producción,
- cada cambio dependiente de SQL se documente antes del despliegue.

---

# 8. Dependencias técnicas y operativas

## SQL / migraciones pendientes
- 010
- 011
- 012
- 013

## Reglas operativas
- el usuario ejecuta migraciones SQL,
- documentar nombre, orden y confirmación,
- no fusionar ni desplegar cambios dependientes sin esa confirmación.

---

# 9. Registro de cambios consolidado

- **2026-10-09:** consolidado el estado de PRs #3–#10, PR #9 abierto y cambios locales de campañas.
- **2026-10-09:** se confirmó que la densidad de 4 columnas y miniatura ampliada sí están desplegadas.
- **2026-10-09:** se aclaró que la separación completa de Catálogo / Productos / Categorías aún no está implementada.
- **2026-10-09:** se añadió la necesidad de acceso al panel desde todas las cabeceras públicas para administradores autenticados.
- **2026-10-09:** se consolidó como prioridad la coherencia entre taxonomía, íconos, banners, cards, filtros y cabecera.
- **2026-10-09:** se integraron observaciones sobre SEO, HTML inicial del catálogo, recomendaciones de ficha y filtros contextuales.

---

# 10. Resumen ejecutivo corto

## Lo más urgente
1. Confirmar migraciones pendientes.
2. Cerrar categorías dinámicas administrables.
3. Unificar cabecera + logo + drawer móvil.
4. Unificar cards entre tienda y categorías.
5. Terminar editor de campañas con carruseles por banner.
6. Estabilizar carga de categorías, imágenes y conteos.
7. Revisar SEO técnico del catálogo.

## Conclusión
SmartISP ya tiene una base comercial muy buena.  
Lo que falta no es rehacer todo, sino **terminar de conectar de forma consistente lo que se administra con lo que se muestra**.

La prioridad real debe ser:
- estabilizar taxonomía,
- unificar experiencia de catálogo,
- cerrar campañas dinámicas,
- y luego optimizar SEO, conversión y detalle visual.