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
        const filesToCopy = ['.htaccess', 'db.js', 'package.json'];
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
