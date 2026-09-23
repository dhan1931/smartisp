import { defineConfig } from 'vite';
import fs from 'node:fs';
import path from 'node:path';

export default defineConfig({
  build: {
    outDir: 'dist',
    rollupOptions: {
      input: {
        main: 'index.html',
        tienda: 'tienda.html',
        checkout: 'checkout.html',
        admin: 'admin.html',
        editorCatalogo: 'editor-catalogo.html',
        editorLanding: 'editor-landing.html',
        resetPassword: 'reset-password.html'
      }
    }
  },
  plugins: [
    {
      name: 'copy-extra-files',
      closeBundle() {
        const filesToCopy = [
          '.htaccess', 'db.js', 'package.json', 'robots.txt', 'sitemap.xml',
          'favicon.ico', 'favicon.png', 'favicon-16x16.png', 'favicon-32x32.png',
          'favicon-48x48.png', 'favicon-96x96.png', 'favicon-144x144.png',
          'favicon-192x192.png', 'favicon-512x512.png',
          'apple-touch-icon.png', 'apple-touch-icon-precomposed.png',
          'site.webmanifest', 'manifest.json'
        ];
        for (const file of filesToCopy) {
          if (fs.existsSync(file)) {
            fs.copyFileSync(file, path.resolve('dist', file));
          }
        }
        if (fs.existsSync('api')) {
          fs.cpSync('api', path.resolve('dist', 'api'), { recursive: true });
        }
      }
    }
  ]
});
