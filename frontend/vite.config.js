import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// En dev, l'API Symfony tourne sur :8000 (symfony serve / php -S) ; Vite relaie /api.
export default defineConfig({
  plugins: [vue()],
  server: { proxy: { '/api': process.env.API_URL || 'http://127.0.0.1:8000' } },
  build: { outDir: 'dist', emptyOutDir: true },
})
