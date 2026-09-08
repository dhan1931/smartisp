import { defineConfig } from 'vite';

export default defineConfig({
  build: {
    outDir: 'dist/public',
    rollupOptions: {
      input: {
        main: 'index.html',
        tienda: 'tienda.html',
        checkout: 'checkout.html',
        admin: 'admin.html',
        resetPassword: 'reset-password.html'
      }
    }
  }
});
