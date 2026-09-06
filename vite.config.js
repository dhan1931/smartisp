import { defineConfig } from 'vite';

export default defineConfig({
  build: {
    outDir: 'dist/public',
    rollupOptions: {
      input: {
        main: 'index.html',
        admin: 'admin.html',
        resetPassword: 'reset-password.html'
      }
    }
  }
});
