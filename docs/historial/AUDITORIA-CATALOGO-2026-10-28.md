# Auditoría del catálogo — 2026-10-28

Fuente: API pública de producción (`action=catalog`, 2758 productos visibles). Cada URL de imagen y cada
página `/producto/<id>-<slug>` se pidió una vez.

## Resumen

| Revisión | Resultado | Estado |
|---|---|---|
| Páginas de detalle | 2758 de 2758 responden 200 (clic probado en tienda, catálogo, búsqueda y página 2, escritorio y móvil) | Sin fallo reproducible |
| Imagen con varias URLs juntas («url1 \| url2») | 492 productos | Corregido: se usa la primera y el resto va a la galería |
| Sin imagen en el proveedor | 132 productos | Muestran el placeholder de SmartISP; hay que cargar foto a mano |
| Imagen que ya no existe (404 o redirige a «no encontrado») | 16 productos | Hay que reemplazar la foto |
| Imagen de cloudhub (`application/octet-stream`) | 6 productos | El proxy de producción devuelve el placeholder; sin verificar la causa desde el servidor |
| Precio 0 (se muestran como «a cotizar») | 437 productos | Revisar si deben tener precio |
| SKU repetido | 19 SKU en 56 productos | Revisar duplicados |
| Producto de prueba visible | 2 | Ocultar o borrar |
| Sin descripción | 2758 productos | El proveedor no la envía |

## Categorías (migración 048)

- «Celulares» tenía 66 accesorios (cables, cargadores, power banks, fundas) y ningún teléfono; los
  teléfonos estaban en «Celulares y Smartphones». Ahora: **Celulares** (40 teléfonos) y **Accesorios para Celulares** (67).
- Fusiones de nombres equivalentes de los dos proveedores: Electrodomésticos, Protección de Energía, Software,
  Consolas y Videojuegos, Hogar y Oficina, Memorias, Consumibles de Impresión, Impresoras y Multifuncionales,
  Cables y Adaptadores, Audio y Video, Dispositivos Vestibles, Redes y Conectividad, Periféricos de Entrada,
  Maletines y Mochilas, Monitores, Televisores.
- Reubicados por nombre: 16 generadores (estaban en «Computadores Portátiles»), 268 consumibles (en
  «Impresoras y Multifuncionales»), 9 computadores (en Almacenamiento/Software), 5 gabinetes y 1 SSD.
- Las URLs viejas `/categoria/<slug>` redirigen (301) y los nombres viejos quedan como palabras clave
  para el importador.
- Siguen con el mismo nombre que su familia, a propósito porque son tarjetas destacadas: «Redes y Conectividad»
  y «Seguridad y Vigilancia».

## Precio 0 por categoría

- Vigilancia de Video: 206
- Redes: 77
- Seguridad y Vigilancia: 32
- Videojuegos: 31
- Impresoras y Multifuncionales: 24
- Computadores Portátiles: 17
- Consumibles de Impresión: 14
- Componentes Informáticos: 9
- Accesorios Generales: 4
- Redes y Conectividad: 4
- Almacenamiento: 3
- Memorias RAM: 3
- Cables y Adaptadores: 2
- Periféricos de Entrada: 2
- Computadores de Escritorio: 2
- Software y Licencias: 2
- Electrodomésticos y Línea Blanca: 2
- Protección de Energía: 1
- Televisores y Pantallas: 1
- Celulares y Smartphones: 1

## Imágenes que ya no existen

- `prod_6ab04f55a5fa4` (302) SILLA ENV GAMER OMNIREST GRAY
- `prod_6ab04f55d0658` (302) LECTOR 3NSTAR DE MANO SC100 LASER 1D LECTURA AUTOMATICA O MANUAL/IP54/ BASE/USB/
- `prod_6ab04f562fb74` (302) DISCO EXTERNO ADATA HD710P 2TB NEGRO USB 3.1 IP68 AGUA POLVO MIL-STD-810G 516.6 
- `prod_6ab04f58d051c` (302) UPS ONLINE CDP UPO11-3 3000VA/ 2700W/ E/S:120V/ MONOFASICO/ S:(4)BAT/ DOBLE CONV
- `prod_6ab04f5939701` (302) EXTENSOR DE RANGO TP-LINK TL-WA855RE N300/2 ANT/EXTERNAS/1 PUERTO FE
- `prod_6ab04f59399ae` (302) ACCESS POINT D-LINK ACCESS POINT D-LINK AC1200 WAVE 2 DUAL-BAND OUTDOOR POE DAP‑
- `prod_6ab04f5a8606a` (302) SMARTWATCH MIBRO/C4 GRIS OSCURO BT 5.3 LLAMADAS 2.01 TFT 2ATM 100 MODOS ANDROID 
- `prod_6ab04f5b5aaa5` (302) KIT DE MANTENIMIENTO CANON MC-G02 G510/G610/G2160/G3160 4589C001AA
- `prod_6ab04f5b7c216` (302) TINTA EPSON SJIC35P NEGRO CW-C6500P/6000A/6000P/6500A C13T44B120
- `prod_6ab04f5bcc858` (302) TINTA HPINC 954XL AMARILLO 7740/8210/8710 1600 PAG L0S68AL
- `prod_6ab04f5e94806` (302) COMBO TECLADO Y MOUSE GENIUS KM-200 USB NEGRO
- `prod_6ab5b6eac47e0` (404) Nexxt RJ45 Connector 50u" Cat5e
- `prod_6ab5b6eb206ed` (404) Nexxt Solutions - Nexxt
- `prod_6ab5b6ed42454` (404) LG - Refrigerator - Side by Side - 508 Lts - No frost
- `prod_muqasf1y` (404) Switch Cisco Catalyst 2960-X 24 Puertos
- `prod_test_browser_1` (404) Servidor HP Proliant Gen10

## SKU repetidos

- `SM-A075MDS` ×6: `prod_6ab04f5a310f0` $138.45; `prod_6ab04f5a312b0` $139.71; `prod_6ab04f5a31485` $138.45; `prod_6ab04f5a3164a` $139.71; `prod_6ab04f5a31814` $138.45; `prod_6ab04f5a5b860` $139.71
- `HOT60I` ×5: `prod_6ab04f5a2ff3f` $172.43; `prod_6ab04f5a30109` $185.5; `prod_6ab04f5a302c5` $187.54; `prod_6ab04f5a30487` $185.68; `prod_6ab04f5a30646` $187.54
- `XIA15CMDN` ×5: `prod_6ab04f5a5d39c` $128.38; `prod_6ab04f5a5d501` $128.38; `prod_6ab04f5a5d68d` $143.48; `prod_6ab04f5a5d817` $143.48; `prod_6ab04f5a5d981` $174.95
- `GRID` ×4: `prod_6ab04f5823520` $125.07; `prod_6ab04f582361c` $125.07; `prod_6ab04f5823712` $125.07; `prod_6ab04f59c5091` $125.07
- `SAMSUNGA565G` ×4: `prod_6ab04f5a5c9a3` $446.82; `prod_6ab04f5a5cb0d` $446.82; `prod_6ab04f5a5cc7d` $446.82; `prod_6ab04f5a5cde3` $446.82
- `SM-A26` ×3: `prod_6ab04f5a309e0` $283.27; `prod_6ab04f5a5c212` $283.27; `prod_6ab04f5a5c3b1` $283.27
- `SM-A16` ×3: `prod_6ab04f5a5b9c1` $148.53; `prod_6ab04f5a5ba9f` $0; `prod_6ab04f5a5bb7f` $245.44
- `SAMSUNGA36` ×3: `prod_6ab04f5a5c51d` $371.29; `prod_6ab04f5a5c6ad` $371.29; `prod_6ab04f5a5c835` $371.29
- `SM-X110NZAAL15` ×3: `prod_6ab04f5a869eb` $229.06; `prod_6ab04f5a86ba1` $186.28; `prod_6ab04f5a86d40` $186.28
- `45496885311` ×2: `prod_6ab04f5547999` $898.7; `prod_6ab04f5547a1f` $778.7
- `TAPOC320` ×2: `prod_6ab04f5f127d7` $0; `prod_6ab04f5f1344e` $24.88
- `AUV240-64G` ×2: `prod_6ab04f565d09a` $7.97; `prod_6ab04f565d4b7` $7.97
- `C5CURVEARGB` ×2: `prod_6ab04f56bc850` $108.77; `prod_6ab04f56bc8c2` $115.38
- `B0H4NX3MM6` ×2: `prod_6abf2459d4468` $480; `prod_6abf29634ef06` $480
- `FQC-10553` ×2: `prod_6ab04f59056ec` $204.36; `prod_6ab04f5905947` $198.02
- `DF50WV2S6W` ×2: `prod_6ab04f59c5dd1` $774.38; `prod_6ab04f59c5f24` $789.4
- `HOT60PROPLUS` ×2: `prod_6ab04f5a2fbc4` $275.02; `prod_6ab04f5a2fd84` $275.02
- `REDMIA5` ×2: `prod_6ab04f5a30d64` $101.95; `prod_6ab04f5a5daf6` $100.65
- `PAD2` ×2: `prod_6ab04f5a87223` $212.8; `prod_6ab04f5a873a8` $227.18

## Productos de prueba visibles

- `prod_test_browser_1` Servidor HP Proliant Gen10
- `prod_test_sony` CONSOLA/DE/JUEGO/SONY DISCO SLIM STANDARD CORE – LATAM

## Sin imagen

- Vigilancia de Video: 42
- Computadores: 18
- Control de Acceso: 8
- Componentes Informáticos: 7
- Periféricos y Accesorios: 7
- Puntos de Venta: 7
- Almacenamiento: 7
- Monitores y Pantallas: 6
- Electrodomésticos: 6
- Redes: 4
- Celulares: 4
- Accesorios para Computadores: 3
- Impresoras y Escáneres: 3
- Videojuegos: 2
- Audio y Video: 2
- Tecnología Portátil: 2
- Protección de Poder: 1
- Intrusión: 1
- Servidores: 1
- General: 1
