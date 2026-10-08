import { existsSync, readdirSync } from 'node:fs';
import path from 'node:path';

const required = [
  'index.html', 'tienda.html', 'nosotros.html', 'servicios.html', 'checkout.html',
  'admin.html', 'configuracion.html', 'pedidos.html', 'categorias.html',
  'editor-catalogo.html', 'editor-landing.html', 'login.html', 'reset-password.html',
  '.htaccess', 'robots.txt', 'sitemap.xml', 'sitemap-main.xml',
  'producto.php', 'categoria.php', 'productos-destacados.php', 'categorias-destacadas.php',
  'sitemap-products.php', 'sitemap-categories.php', 'api/router.php', 'api/config.php',
  'assets/js/admin-nav.js', 'assets/js/public-brand.js', 'assets/js/public-account.js'
];

const missing = required.filter(file => !existsSync(path.join('dist', file)));
if (missing.length) throw new Error(`Faltan archivos del despliegue en dist/: ${missing.join(', ')}`);

const forbidden = [];
const walk = directory => {
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) walk(file);
    else if (/^\.env(?:\.|$)/i.test(entry.name) || /\.sql(?:\.gz)?$/i.test(entry.name)) forbidden.push(file);
  }
};
walk('dist');

if (forbidden.length) throw new Error(`El artefacto contiene archivos privados/no desplegables: ${forbidden.join(', ')}`);
console.log(`Artefacto válido: ${required.length} rutas presentes y sin .env ni dumps SQL.`);
