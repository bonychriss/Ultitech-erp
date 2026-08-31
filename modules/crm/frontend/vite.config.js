import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  base: './',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        entryFileNames: 'assets/crm-ui.js',
        chunkFileNames: 'assets/crm-ui-[name].js',
        assetFileNames: 'assets/crm-ui[extname]',
      },
    },
  },
});
