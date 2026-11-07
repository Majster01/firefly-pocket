<template>
  <div class="setup">
    <div class="card setup-card">
      <img src="/favicon.svg" alt="" width="64" height="64" class="setup-logo" />
      <h1>Firefly Pocket</h1>
      <p v-if="!error" class="muted"><span class="spinner" /> Signing in with Firefly III…</p>
      <template v-else>
        <p class="small bad">{{ error }}</p>
        <RouterLink to="/setup" class="btn primary block">Back</RouterLink>
      </template>
    </div>
  </div>
</template>

<script setup>
// Where Firefly III sends the browser back after its login (lib/signin.js)
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { get } from '../lib/api.js'
import { settings } from '../lib/settings.js'
import { store } from '../lib/data.js'
import { finishSignIn } from '../lib/signin.js'
import './setup.css'

const route = useRoute()
const router = useRouter()
const error = ref('')

onMounted(async () => {
  try {
    const { token, next } = await finishSignIn(route.query)
    // As a pasted token: only a user proves it works
    const json = await get('user', undefined, { token, quiet: true })
    if (!json?.data) throw new Error('Firefly III refused the token it gave.')
    settings.token = token
    store.user = json.data
    Object.keys(store.loaded).forEach((key) => (store.loaded[key] = false))
    router.replace(next.startsWith('/') && !next.startsWith('//') ? next : '/')
  } catch (caught) {
    error.value = caught.message
  }
})
</script>
