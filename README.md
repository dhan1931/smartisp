# TechStore - Tienda de Electrónica e Infraestructura TI

Una plataforma de comercio electrónico moderna y responsive diseñada para la venta de productos electrónicos e infraestructura TI, con estética limpia y colores corporativos (azul, blanco y celeste).

## 🎨 Características

### Interfaz de Usuario
- **Diseño Responsive**: Funciona perfectamente en desktop, tablet y móvil
- **Navegación Intuitiva**: Header sticky con búsqueda y carrito de compras
- **Paleta de Colores**:
  - Azul Primario: `#003366`
  - Azul Celeste: `#00A8E8`
  - Blanco: `#FFFFFF`

### Funcionalidades Principales

#### 🔍 Búsqueda y Filtros
- Búsqueda en tiempo real por nombre, descripción, especificaciones y marca
- Filtros por categoría:
  - Laptops & Computadoras
  - Componentes
  - Networking
  - Almacenamiento
  - Servidores
  - Periféricos
- Filtros por marca (Dell, HP, Lenovo, Cisco, NVIDIA, Intel, etc.)
- Rango de precio ajustable (hasta $50,000)

#### 📊 Catálogo de Productos
- 24 productos de electrónica e infraestructura TI
- Información completa: nombre, descripción, especificaciones técnicas
- Precios con descuentos visibles
- Calificaciones en estrellas
- Iconos emoji para identificación visual rápida

#### 🛒 Carrito de Compras
- Añadir/eliminar productos
- Ajustar cantidades
- Resumen de compra con subtotal, envío y total
- Envío gratis para compras mayores a $1,000
- Carrito persistente durante la sesión

#### 📝 Ordenamiento
- Por Relevancia
- Precio: Mayor a Menor
- Precio: Menor a Mayor
- Nombre: A - Z
- Nombre: Z - A

### 📱 Categorías de Productos

1. **Laptops & Computadoras**: Dell XPS, HP Pavilion, Lenovo ThinkPad, MacBook Pro
2. **Componentes**: GPUs NVIDIA, CPUs Intel, RAM Kingston, SSDs Samsung
3. **Networking**: Switches Cisco, APs Ubiquiti, Routers Netgear, Firewalls Fortinet
4. **Almacenamiento**: HDD WD, Seagate, NetApp AFF, NAS Synology
5. **Servidores**: Dell PowerEdge, HP ProLiant, Lenovo ThinkSystem, Supermicro
6. **Periféricos**: Ratones, Teclados, Monitores profesionales

## 🚀 Cómo Usar

### Opción 1: Abrir directamente el archivo HTML

1. **Ubicación**: El proyecto está en `e:\ale\gamedev\amazon 2\`
2. **Archivos principales**:
   - `index.html` - Estructura HTML
   - `styles.css` - Estilos y diseño
   - `app.js` - Lógica de la aplicación

3. **Ejecutar**:
   - Simplemente abre `index.html` en tu navegador web preferido (Chrome, Firefox, Edge, Safari)
   - Haz doble clic en el archivo o arrastralo al navegador

### Opción 2: Usar un servidor local (Recomendado)

Si quieres evitar problemas CORS (Cross-Origin):

#### Con Python (si está instalado):
```bash
cd "e:\ale\gamedev\amazon 2"
python -m http.server 8000
```
Luego abre: `http://localhost:8000`

#### Con Node.js (si está instalado):
```bash
cd "e:\ale\gamedev\amazon 2"
npx http-server -p 8000
```
Luego abre: `http://localhost:8000`

#### Con Live Server en VS Code:
1. Instala la extensión "Live Server" de Ritwick Dey
2. Click derecho en `index.html` → "Open with Live Server"

## 📂 Estructura de Carpetas

```
e:\ale\gamedev\amazon 2\
├── index.html          # Estructura HTML
├── styles.css          # Estilos CSS
├── app.js              # Lógica JavaScript
└── README.md           # Este archivo
```

## 🎯 Funcionalidades Detalladas

### Búsqueda
- Introduce términos en la barra de búsqueda
- Búsqueda instantánea a través de nombre, descripción, specs y marca
- Presiona Enter o haz clic en el botón de búsqueda

### Filtros
- Selecciona múltiples categorías y marcas
- Ajusta el rango de precio con el slider
- Los filtros se aplican automáticamente
- Botón "Limpiar Filtros" para resetear todo

### Ordenamiento
- Selecciona el tipo de ordenamiento desde el dropdown
- Instantáneamente reordena los productos mostrados

### Carrito
- Haz clic en "Añadir" en cualquier producto
- El botón mostrará una confirmación ✓
- Visualiza tu carrito haciendo clic en el botón 🛒
- Ajusta cantidades o elimina productos
- Procede al pago (funcionalidad visual)

## 🎨 Paleta de Colores

```
Azul Primario:     #003366 (RGB: 0, 51, 102)
Azul Celeste:      #00A8E8 (RGB: 0, 168, 232)
Blanco:            #FFFFFF (RGB: 255, 255, 255)
Gris Claro:        #F5F5F5 (RGB: 245, 245, 245)
Gris Oscuro:       #333333 (RGB: 51, 51, 51)
Éxito:             #28A745 (Verde)
Advertencia:       #FFC107 (Amarillo)
Peligro:           #DC3545 (Rojo)
```

## 💾 Características Técnicas

- **Sin dependencias externas**: HTML5 + CSS3 + JavaScript vanilla
- **Totalmente responsive**: Funciona en cualquier dispositivo
- **Rendimiento optimizado**: Carga rápida y ejecución suave
- **Accesibilidad**: Etiquetas aria-label y estructura HTML semántica
- **Animaciones suaves**: Transiciones CSS para mejor UX

## 📊 Datos de Ejemplo

El proyecto incluye 24 productos de ejemplo con:
- Precios realistas (desde $99 hasta $12,999)
- Especificaciones técnicas auténticas
- Descripciones detalladas
- Calificaciones en estrellas
- Iconos para visualización rápida

## 🔐 Notas sobre la Seguridad

Esta es una demostración/prototipo de frontend. Para una tienda real:
- Implementar backend seguro
- Validar datos en servidor
- Usar HTTPS
- Implementar autenticación
- Integrar pasarela de pagos real
- Proteger datos sensibles

## 🗄️ Persistencia de Usuarios

El backend usa PostgreSQL cuando existe la variable `DATABASE_URL`. Al iniciar crea automáticamente la tabla `users` y guarda los registros de forma permanente. Copia `.env.example` como `.env` y completa una conexión de PostgreSQL, por ejemplo de Neon o Supabase:

```env
DATABASE_URL=postgresql://usuario:contraseña@host:5432/base_de_datos
SESSION_SECRET=una-clave-segura
NODE_ENV=production
```

En Vercel, configura estas variables en **Project Settings → Environment Variables**. No subas `.env` a GitHub. Sin `DATABASE_URL`, el proyecto usa memoria temporal únicamente para desarrollo local.

## 📝 Próximas Mejoras Posibles

- [ ] Backend con base de datos
- [ ] Sistema de usuarios y login
- [ ] Integración de pagos (Stripe, PayPal)
- [ ] Historial de órdenes
- [ ] Reseñas y comentarios de productos
- [ ] Comparador de productos
- [ ] Wishlist/Favoritos
- [ ] Carrito persistente (localStorage)
- [ ] Notificaciones en tiempo real
- [ ] Panel de administración

## 🌐 Navegadores Soportados

- Chrome/Chromium 90+
- Firefox 88+
- Safari 14+
- Edge 90+
- Opera 76+

## 📧 Contacto y Soporte

Para preguntas o sugerencias sobre esta tienda de ejemplo, puedes:
- Revisar la sección de FAQ en el footer
- Contactar a través del formulario de contacto
- Revisar la documentación técnica

---

**Versión**: 1.0.0  
**Última actualización**: 2026-08-18  
**Licencia**: Uso educativo y comercial permitido
