# SmartISP

Tienda en línea de SmartISP Ecuador para productos de conectividad, cómputo, redes, energía, seguridad e infraestructura TI. Incluye catálogo público, páginas comerciales, checkout y un panel para administrar productos, categorías, pedidos y contenido.

## Stack

- Frontend multipágina: HTML, CSS y JavaScript.
- API de producción: PHP con MySQL/MariaDB.
- Compilación de páginas y assets: Vite (`npm run build`). El resultado se genera en `dist/`.
- Hosting actual: Hostinger; el despliegue desde `main` compila y sincroniza `dist/` con `public_html`.
- `server.js` y sus dependencias son un backend paralelo/legacy; no sustituyen la API PHP que usa la tienda en producción.

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
```

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
