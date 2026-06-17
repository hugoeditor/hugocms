import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'
import vueDevTools from 'vite-plugin-vue-devtools'

// Im Entwicklungsbetrieb läuft Vite auf 5173 und der PHP-Server auf 8765.
// Der Proxy leitet alle /cms-api-Aufrufe an den PHP-Connector weiter und reicht
// dabei das Session-Cookie durch — damit ist im Dev-Betrieb kein CORS nötig.
export default defineConfig({
  // Der Client wird unter dem Pfad /edit/ ausgeliefert. Dadurch referenziert
  // das gebaute index.html seine Assets als /edit/assets/…
  // Gilt für Build und Dev-Server (Dev: http://localhost:5173/edit/).
  base: '/edit/',
  plugins: [
    // Vue DevTools nur im Entwicklungsbetrieb; beim Build ist das Plugin
    // ohnehin inaktiv. Blendet eine schwebende Schaltfläche ein (keine
    // Browser-Erweiterung nötig).
    vueDevTools(),
    vue(),
    vuetify({ autoImport: true }),
  ],
  server: {
    port: 5173,
    proxy: {
      '/cms-api': {
        target: 'http://127.0.0.1:8765',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/cms-api\/?/, '/index.php'),
      },
    },
  },
  build: {
    outDir: 'dist',
  },
})
