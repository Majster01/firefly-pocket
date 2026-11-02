import { createApp } from 'vue'
import App from './App.vue'
import { router } from './router.js'
import { applyTheme } from './lib/settings.js'
import { setUnauthorizedHandler } from './lib/api.js'
import { registerServiceWorker } from './lib/push.js'
import { warmUp } from './lib/cache.js'
import { watchForUpdate } from './lib/update.js'
import './styles.css'

warmUp()
watchForUpdate()
applyTheme()
matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applyTheme)
setUnauthorizedHandler(() => {
  // next: back to the same page once a new token is saved
  const current = router.currentRoute.value
  if (current.name !== 'setup') router.replace({ name: 'setup', query: { reason: 'unauthorized', next: current.fullPath } })
})

createApp(App).use(router).mount('#app')
registerServiceWorker()
