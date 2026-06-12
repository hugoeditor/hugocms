import 'vuetify/styles'
import { createVuetify } from 'vuetify'
import { aliases, mdi } from 'vuetify/iconsets/mdi'

// Theme nach Linux-Mint (Cinnamon/Nemo, Mint-Y): helle Grau-Flächen, weißer
// Inhaltsbereich, grüner Mint-Akzent. Die Feinheiten des GTK-Looks stehen in
// styles/nemo.css (als CSS-Variablen, von den Komponenten genutzt).
export default createVuetify({
  theme: {
    defaultTheme: 'mint',
    themes: {
      mint: {
        dark: false,
        colors: {
          background: '#ffffff',
          surface: '#ffffff',
          'surface-variant': '#f4f4f3',
          primary: '#3c8527', // Mint-Grün (Akzent)
          'primary-darken-1': '#2f6b1e',
          secondary: '#5a5a58',
          success: '#3c8527',
          info: '#2f6fae',
          warning: '#c97a17',
          error: '#c0392b',
        },
      },
    },
  },
  defaults: {
    // Ruhiger, GTK-näher: keine erhöhten Schatten als Standard.
    VBtn: { rounded: 'md' },
  },
  icons: {
    defaultSet: 'mdi',
    aliases,
    sets: { mdi },
  },
})
