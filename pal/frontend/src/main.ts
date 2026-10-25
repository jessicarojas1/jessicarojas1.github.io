import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { Quasar, Notify, Dialog, Dark } from 'quasar'
import '@quasar/extras/roboto-font/roboto-font.css'
import '@quasar/extras/material-icons/material-icons.css'
import 'quasar/src/css/index.sass'
import './css/app.scss'

import App from './App.vue'
import { router } from './router'
import { readThemePreference } from './theme'

const app = createApp(App)
app.use(createPinia())
app.use(Quasar, {
  plugins: { Notify, Dialog, Dark },
  config: {
    dark: readThemePreference(),
    notify: { position: 'top-right', timeout: 4000 },
  },
})
app.use(router)
app.mount('#app')
