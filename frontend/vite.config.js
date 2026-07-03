import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'
import vueDevTools from 'vite-plugin-vue-devtools'
import { resolveBuildNumber } from './build-version.js'

// Im Entwicklungsbetrieb läuft Vite auf 5173 und der PHP-Server auf 8765.
// Der Proxy leitet alle /cms-api-Aufrufe an den PHP-Connector weiter und reicht
// dabei das Session-Cookie durch — damit ist im Dev-Betrieb kein CORS nötig.
export default defineConfig(({ command }) => {
  // Buildnummer als Versionsnummer. Hochgezählt wird nur beim Release-Build:
  // scripts/packaging.sh setzt HUGOCMS_RELEASE=1. 'command === build' allein
  // genügt nicht, da auch lokale Test-Builds 'build' sind. Dev-/Test-Läufe
  // lesen den Stand nur und zeigen ihn im Versionsdialog an.
  const isRelease = command === 'build' && process.env.HUGOCMS_RELEASE === '1'
  const buildNumber = resolveBuildNumber(isRelease)

  return {
    // Der Client wird unter dem Pfad /edit/ ausgeliefert. Dadurch referenziert
    // das gebaute index.html seine Assets als /edit/assets/…
    // Gilt für Build und Dev-Server (Dev: http://localhost:5173/edit/).
    base: '/edit/',
    define: {
      // Zur Bauzeit ersetzt; im Client als globale Konstante __APP_BUILD__
      // verfügbar (siehe src/util/version.js).
      __APP_BUILD__: JSON.stringify(buildNumber),
    },
    plugins: [
      // Vue DevTools nur im Entwicklungsbetrieb; beim Build ist das Plugin
      // ohnehin inaktiv. Blendet eine schwebende Schaltfläche ein (keine
      // Browser-Erweiterung nötig).
      vueDevTools(),
      vue(),
      vuetify({ autoImport: true }),
    ],
    resolve: {
      // filerobot-image-editor bringt React (18) über mehrere Pakete herein.
      // Ohne dedupe kann Vite mehrere React-Kopien laden → 'Invalid hook call'.
      // Eine einzige Instanz erzwingen.
      dedupe: ['react', 'react-dom'],
    },
    optimizeDeps: {
      // filerobot-image-editor wird nur per dynamischem import() geladen; Vites
      // Scanner findet es beim Start daher nicht und würde es erst beim ersten
      // Öffnen des Editors nachbündeln — der Import scheitert dann einmalig
      // (NS_ERROR_CORRUPTED_CONTENT). Vorab-Bündeln beim Serverstart erzwingen.
      include: ['filerobot-image-editor'],
    },
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
  }
})
