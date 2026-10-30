import { createApp } from 'vue'
import App from './App.vue'
import { router } from './router.js'
import { applyTheme } from './lib/settings.js'
import { setUnauthorizedHandler } from './lib/api.js'
import { registerServiceWorker } from './lib/push.js'
import './styles.css'

applyTheme()
matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applyTheme)
setUnauthorizedHandler(() => {
  if (router.currentRoute.value.name !== 'setup') router.replace({ name: 'setup', query: { reason: 'unauthorized' } })
})

createApp(App).use(router).mount('#app')
registerServiceWorker()
