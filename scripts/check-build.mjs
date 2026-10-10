import { existsSync, readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';

const required = [
  'index.html', 'tienda.html', 'nosotros.html', 'servicios.html', 'checkout.html',
  'admin.html', 'configuracion.html', 'campanas.html', 'pedidos.html', 'clientes.html', 'categorias.html',
  'editor-catalogo.html', 'editor-landing.html', 'login.html', 'reset-password.html',
  '.htaccess', 'robots.txt', 'sitemap.xml', 'sitemap-main.xml',
  'producto.php', 'categoria.php', 'productos-destacados.php', 'categorias-destacadas.php',
  'sitemap-products.php', 'sitemap-categories.php', 'api/router.php', 'api/config.php',
  'assets/js/admin-nav.js', 'assets/js/public-brand.js', 'assets/js/public-account.js', 'assets/js/public-admin-link.js',
  'assets/js/storefront-discovery.js', 'assets/css/storefront-discovery.css',
  'includes/storefront-discovery-nav.php', 'includes/storefront-product-card.php'
];

const missing = required.filter(file => !existsSync(path.join('dist', file)));
if (missing.length) throw new Error(`Faltan archivos del despliegue en dist/: ${missing.join(', ')}`);

const forbidden = [];
for (const file of ['package.json', 'server.js', 'vite.config.js']) {
  if (existsSync(path.join('dist', file))) forbidden.push(path.join('dist', file));
}
const htaccess = readFileSync(path.join('dist', '.htaccess'), 'utf8');
for (const protection of [
  /RewriteRule\s+\^api\/\(\?\!router\\\.php\$\)\.\+\\\.php\$/,
  /RewriteRule\s+\^\(\?:package\\\.json\|server\\\.js\|vite\\\.config\\\.js/
]) {
  if (!protection.test(htaccess)) forbidden.push('dist/.htaccess: falta regla de protección interna');
}
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
