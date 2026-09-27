import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Builds straight into the mu-plugin so PHP can enqueue the hashed files via manifest.json.
export default defineConfig({
  plugins: [react()],
  base: './',
  build: {
    outDir: '../assets/app',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        app: 'src/main.tsx',
      },
    },
  },
  server: {
    port: 5174,
  },
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts'],
  },
});
