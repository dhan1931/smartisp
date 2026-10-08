import { defineConfig } from 'vite';
import fs from 'node:fs';
import path from 'node:path';

export default defineConfig({
  // DEV-20261005-032: la raíz del repo es la única fuente de archivos estáticos (es lo que
  // Hostinger sirve directamente); no existe carpeta public/ y no se depende del publicDir
  // implícito de Vite, que antes duplicaba favicons/sitemaps/uploads entre raíz y public/
  // sin que nada supiera cuál copia era la real.
  publicDir: false,
  build: {
    outDir: 'dist',
    rollupOptions: {
      input: {
        main: 'index.html',
        tienda: 'tienda.html',
        checkout: 'checkout.html',
        admin: 'admin.html',
        configuracion: 'configuracion.html',
        pedidos: 'pedidos.html',
        categorias: 'categorias.html',
        editorCatalogo: 'editor-catalogo.html',
        editorLanding: 'editor-landing.html',
        resetPassword: 'reset-password.html',
        login: 'login.html'
      }
    }
  },
  plugins: [
    {
      name: 'copy-extra-files',
      closeBundle() {
        // Favicons, logos y manifests viven en assets/ (DEV-20261005-035): se copia la carpeta
        // completa en vez de listar cada archivo suelto, como antes.
        const filesToCopy = ['.htaccess', 'package.json', 'robots.txt', 'sitemap.xml', 'sitemap-main.xml', 'producto.php', 'sitemap-products.php'];
        for (const file of filesToCopy) {
          if (fs.existsSync(file)) {
            fs.copyFileSync(file, path.resolve('dist', file));
          }
        }
        if (fs.existsSync('assets')) {
          fs.cpSync('assets', path.resolve('dist', 'assets'), { recursive: true });
        }
        if (fs.existsSync('api')) {
          fs.cpSync('api', path.resolve('dist', 'api'), { recursive: true });
        }
      }
    }
  ]
});
