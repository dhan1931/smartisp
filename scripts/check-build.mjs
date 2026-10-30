import { existsSync, readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';

const required = [
  'index.html', 'tienda.html', 'nosotros.html', 'servicios.html', 'checkout.html',
  'terminos.html', 'devoluciones.html', 'privacidad.html', 'garantias.html', 'envios.html', 'assets/css/store/legal-page.css', 'mi-cuenta.html', 'assets/js/store/mi-cuenta-page.js', 'assets/css/store/mi-cuenta-page.css',
  'admin.html', 'configuracion.html', 'campanas.html', 'pedidos.html', 'cotizaciones.html', 'clientes.html', 'categorias.html',
  'editor-catalogo.html', 'editor-landing.html', 'login.html', 'reset-password.html',
  'inventario.html', 'estadisticas.html', 'reportes.html', 'assets/js/admin/admin-page.js', 'assets/css/admin/admin-page.css', 'api/lib/shop.php',
  'assets/vendor/lucide-icons.min.js', 'assets/vendor/lucide.min.js', 'assets/js/admin/admin-charts.js', 'assets/js/admin/admin-dashboard.js', 'assets/css/admin/admin-dashboard.css',
  '.htaccess', 'robots.txt', 'sitemap.xml', 'sitemap-main.xml',
  'producto.php', 'categoria.php', 'productos-destacados.php', 'categorias-destacadas.php',
  'sitemap-products.php', 'sitemap-categories.php', 'llms.php', 'api/router.php', 'api/config.php',
  'assets/js/admin/admin-nav.js', 'assets/js/store/public-brand.js', 'assets/js/store/public-account.js', 'assets/js/store/public-admin-link.js',
  'assets/js/store/storefront-discovery.js', 'assets/css/store/storefront-discovery.css',
  'includes/storefront-discovery-nav.php', 'includes/storefront-product-card.php',
  'assets/js/admin/categories-admin.js', 'api/lib/category-admin.php'
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
