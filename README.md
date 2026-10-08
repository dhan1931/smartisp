# SmartISP

Tienda en línea de SmartISP Ecuador para productos de conectividad, cómputo, redes, energía, seguridad e infraestructura TI. Incluye catálogo público, páginas comerciales, checkout y un panel para administrar productos, categorías, pedidos y contenido.

## De TechStore a SmartISP

El proyecto comenzó como **TechStore**, un prototipo de tienda de electrónica e infraestructura TI. Su propuesta visual usaba azul marino (`#003366`), celeste (`#00A8E8`) y blanco, con una interfaz adaptable; planteaba búsqueda, filtros de categoría y marca, rango de precios, ordenamiento y carrito. Los 24 productos, calificaciones y parte de la experiencia de pago descritos entonces eran datos o interacciones de demostración, no funcionalidades conectadas a una operación comercial real.

Desde entonces, el producto y su tecnología han avanzado. Hoy es **SmartISP**, una tienda ecuatoriana que vende y acerca soluciones para empresas y hogares en conectividad, redes, cómputo, energía, seguridad, impresión y otras áreas de tecnología. La tienda actual consulta el catálogo desde la base de datos y suma checkout, cuentas, páginas comerciales y herramientas administrativas; no es la demo genérica original de TechStore.

## Identidad visual

La paleta TechStore de arriba es histórica, no una especificación para cambiar la marca actual. La tienda SmartISP usa principalmente azul marino `#102A43`, azul `#1177C9`, fondo claro `#F5FBFF`, texto `#17324D` y blanco. Su tipografía actual combina **DM Sans** para texto e interfaz con **Space Grotesk** en títulos y elementos de marca. El tema nocturno está disponible en la experiencia de tienda. Estos valores se observan en los estilos actuales de `tienda.html`; algunas páginas administrativas y comerciales conservan estilos propios y conviene unificarlas gradualmente.

## Funciones actuales

- Catálogo de base de datos con búsqueda, navegación por categorías, filtros y ordenamiento.
- Carrito, gestión de cantidades, cuenta de cliente, wishlist y flujo de checkout.
- Páginas comerciales para categorías y productos destacados, servicios y presentación de SmartISP.
- Panel para administrar catálogo, categorías, pedidos, ajustes y contenido visual de landing.
- Autenticación y permisos por rol aplicados por la API; las operaciones privadas deben validarse también en el servidor.
- Rutas de producto/categoría, metadatos y sitemaps para indexación pública.
- Diseño adaptable para escritorio y móvil.

El alcance de pagos, inventario, notificaciones y otras integraciones depende de la configuración activa del proyecto; no se deben asumir como habilitados solo porque aparecían en la lista de ideas del prototipo.

## Estructura del proyecto

```text
smartisp/
├─ *.html                    Páginas multipágina actuales
├─ assets/{css,js,img}/      Estilos, scripts compartidos e imágenes
├─ api/                      API PHP, configuración y handlers
├─ migrations/               Cambios de esquema explícitos
├─ tests/                    Validación de esquema MariaDB
├─ scripts/                  Desarrollo, build, migraciones y utilidades
├─ docs/                     Despliegue, arquitectura y planes
├─ ops/                      Backlog, riesgos y reportes operativos
├─ vite.config.js            Build multipágina de producción
└─ dist/                     Artefacto generado; no editar manualmente
```

La estructura refleja el estado de hoy y no el prototipo antiguo de un único `index.html`, `styles.css` y `app.js`.

## Stack

- Frontend multipágina: HTML, CSS y JavaScript; Vite organiza la compilación de entradas HTML y assets.
- API de producción: PHP con PDO y MySQL/MariaDB.
- Compilación: Vite (`npm run build`), con salida en `dist/` y páginas PHP necesarias para Hostinger incluidas en el artefacto.
- Hosting actual: Hostinger; el despliegue desde `main` compila y sincroniza `dist/` con `public_html`.
- CI en GitHub Actions: lint de JavaScript/PHP, build multipágina y pruebas de migración contra MariaDB efímera; no despliega desde GitHub.
- `server.js` conserva un backend paralelo/legacy; no es el backend canónico de producción. Evita agregar rutas nuevas allí sin decidir primero retirar o consolidar ese backend.
- Versión de paquete: `1.0.0` (`package.json`); es la versión npm del proyecto, no necesariamente una versión comercial publicada.

## Modernización gradual del stack

No hace falta reemplazar HTML ni cambiar de hosting para mejorar la estructura. La ruta recomendada es conservar PHP + MySQL/MariaDB como backend productivo y Vite como build, mientras se extraen estilos y scripts de las páginas grandes a módulos compartidos, se consolidan los clientes de API y se agregan pruebas de contrato para login, catálogo, pedidos y administración. Después se puede dividir cada pantalla en componentes pequeños sin alterar sus URLs ni comportamiento.

Introducir React/Vue o migrar la API a Node/Laravel sería una decisión posterior, no un requisito para ordenar el proyecto. Añadiría compilación, dependencias y trabajo de migración; solo conviene si el equipo necesita explícitamente ese modelo y hay pruebas que protejan los flujos actuales. El plan incremental está desarrollado en [docs/ARQUITECTURA-Y-DEUDA.md](docs/ARQUITECTURA-Y-DEUDA.md).

Hostinger admite PHP y MySQL en el hosting web; sus aplicaciones Node.js dependen del tipo y plan contratado. Como el despliegue actual genera archivos estáticos y PHP para `public_html`, el stack presente encaja con ese modelo. Antes de mover el backend a Node, confirma en hPanel que el plan concreto permite una app Node.js persistente, su versión, variables de entorno, logs/runtime y conexión a MySQL. La disponibilidad y los límites varían por plan; consulta [límites de hosting de Hostinger](https://www.hostinger.com/support/6976044-parameters-and-limits-of-hosting-plans/) y su [guía de aplicaciones Node.js](https://www.hostinger.com/support/hostinger-dashboard/node-js/).

## Accesibilidad, compatibilidad y seguridad

- Mantener HTML semántico, etiquetas accesibles, controles de teclado y estados visibles de foco al extraer componentes.
- El diseño responsive es un requisito de regresión: comprobar tienda, carrito, cuenta, checkout y panel en móvil, tablet y escritorio.
- Probar las versiones actuales de Chrome, Edge, Firefox y Safari; el README antiguo mencionaba versiones mínimas de 2021, que ya no son un objetivo vigente.
- Secretos fuera del repositorio y del directorio público; usar entorno PHP o `.env` fuera de `public_html`. Nunca incluir credenciales en el bundle Vite.
- Validar autorización, datos y permisos en el servidor; no confiar en ocultar botones en el navegador.
- Forzar HTTPS, usar cookies de sesión seguras, contraseñas con hash moderno, consultas preparadas, protección CSRF acorde al mecanismo de sesión, límites de intentos y CORS limitado al origen necesario.
- Restringir cargas y proxies de imágenes, bloquear archivos internos y establecer cabeceras HTTP de seguridad.
- Respaldar la base antes de migraciones; el build y el deploy no deben aplicar cambios de esquema automáticamente.

La lista es una base de ingeniería, no una certificación de que todas las medidas estén activas en producción. Para hallazgos y estado verificado, consulta [ops/SECURITY.md](ops/SECURITY.md) y [docs/DEPLOY.md](docs/DEPLOY.md); los reportes disponibles incluyen análisis estático y pueden requerir actualización tras cambios recientes.

### Roles administrativos

`users_rows.role` es la fuente de autorización. El backend normaliza `admin`, `administrator` y `administrador` a `admin`; los roles desconocidos se tratan como `customer` (mínimo privilegio). El navegador no concede permisos por correo, `localStorage` ni etiquetas visibles. Cada petición protegida vuelve a leer el rol vigente de la base, por lo que los cambios y revocaciones se aplican también a sesiones ya abiertas. No hay migración que convierta usuarios existentes en administradores; antes de diagnosticar un 403, verifica de forma read-only el rol de la cuenta en la base de producción.

La CI cubre administrador, cliente, petición anónima, normalización de roles, revocación en una sesión existente y métricas sobre pedidos de una MariaDB temporal. Esa cobertura no confirma el rol asignado en Hostinger ni la conectividad de producción.

## Páginas principales

- `/` y `/tienda.html`: tienda y catálogo.
- `/categorias-destacadas` y `/productos-destacados`: páginas comerciales.
- `/servicios` y `/nosotros`: información de SmartISP.
- `/login.html`: acceso de clientes y administradores.
- `/admin.html`, `/pedidos.html`, `/categorias.html`, `/configuracion.html`, `/editor-catalogo.html` y `/editor-landing.html`: administración. El acceso depende del rol que devuelve el backend.

## Desarrollo local en Windows

1. Instala Node.js 22, PHP con PDO MySQL y acceso a una base MySQL/MariaDB de desarrollo.
2. Instala dependencias: `npm ci`.
3. Copia `scripts/dev-db.env.example` como `scripts/dev-db.local.env` y completa **solo las credenciales de la base local**. Ese archivo está ignorado por Git.
4. Inicia el sitio y la API PHP desde PowerShell:

   ```powershell
   .\scripts\dev-php.ps1 -Target local
   ```

   Abre `http://localhost:8080`. Si PHP no está en la ruta predeterminada del script, indica su ubicación con `-Php`.

Para validar la compilación de producción sin iniciar el servidor:

```powershell
npm ci
npm run build
npm run lint
npm test
```

La prueba de esquema `npm run test:db` requiere una MariaDB desechable cuyo nombre termine en `_ci`; el fixture se niega a ejecutarse contra otra base. GitHub Actions crea y destruye su propia base por ejecución.

Vite escribe las páginas y assets compilados en `dist/`. No edites `dist/` a mano.

## Configuración

En Hostinger, configura las variables en el entorno disponible para PHP o usa un archivo `.env` fuera de `public_html`. La API también admite `SMARTISP_ENV_FILE` para indicar una ruta absoluta. La configuración MySQL requiere:

```env
MYSQL_HOST=
MYSQL_PORT=3306
MYSQL_DATABASE=
MYSQL_USER=
MYSQL_PASSWORD=
SESSION_SECRET=
```

El correo puede configurarse con `ADMIN_EMAIL`, `SMTP_PROVIDER`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`, `RESEND_API_KEY` y `EMAIL_FROM`, según el proveedor. Usa `.env.example` como referencia, reemplaza los valores de ejemplo y **no subas `.env`, credenciales ni volcados SQL al repositorio**.

## Base de datos y despliegues

Las migraciones están en `migrations/` y no se ejecutan al compilar ni al publicar el sitio. Revisa el estado, respalda la base y aplica cambios de esquema de forma deliberada siguiendo [docs/DEPLOY.md](docs/DEPLOY.md). La propuesta de tablas futuras y el resultado de su Fase 0 son documentos de planificación; no significan que esas tablas estén instaladas en producción.

Un push a `main` puede disparar el despliegue configurado en Hostinger. Después de publicar, valida páginas públicas, catálogo, login, endpoints de API y disponibilidad de datos. Los logs de compilación no bastan para confirmar que PHP se conectó a MySQL.

## Documentación

- [Guía de despliegue](docs/DEPLOY.md)
- [Arquitectura y deuda técnica](docs/ARQUITECTURA-Y-DEUDA.md)
- [Plan de tablas futuras y Fase 0](tablasnuevas.md)
- [Historial de cambios](CHANGELOG.md)

## Seguridad

- `.env`, archivos de secretos, cachés locales y volcados de base de datos están excluidos de Git.
- No copies credenciales de producción a `scripts/dev-db.local.env` ni a ejemplos versionados.
- Las migraciones de producción requieren respaldo y revisión previa.
- No se realizan cambios de base de datos como parte de `npm run build`.
