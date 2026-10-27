import { createHash } from 'node:crypto'
import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

/** PWA : inscrit la liste des fichiers compilés dans le service worker (mis en cache dès l'installation). */
const precache = () => ({
  name: 'sw-precache',
  apply: 'build',
  closeBundle() {
    const files = readdirSync('dist/assets').sort().map((f) => `/assets/${f}`)
    const version = createHash('sha256').update(files.join()).digest('hex').slice(0, 10)
    const sw = readFileSync('dist/sw.js', 'utf8')
      .replace("'__VERSION__'", JSON.stringify(version)).replace('[/* __PRECACHE__ */]', JSON.stringify(files))
    writeFileSync('dist/sw.js', sw)
  },
})

// En dev, l'API Symfony tourne sur :8000 (symfony serve / php -S) ; Vite relaie /api.
export default defineConfig({
  plugins: [vue(), precache()],
  server: { proxy: { '/api': process.env.API_URL || 'http://127.0.0.1:8000' } },
  build: { outDir: 'dist', emptyOutDir: true },
})
