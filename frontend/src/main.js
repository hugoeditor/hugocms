import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import vuetify from './plugins/vuetify'
import { i18n } from './i18n'

import '@mdi/font/css/materialdesignicons.css'
import './styles/nemo.css'

createApp(App)
  .use(createPinia())
  .use(vuetify)
  .use(i18n)
  .mount('#app')
