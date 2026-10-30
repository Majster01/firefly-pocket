<template>
  <div class="setup">
    <form class="card setup-card" @submit.prevent="connect">
      <img src="/favicon.svg" alt="" width="64" height="64" class="setup-logo" />
      <h1>Firefly Pocket</h1>
      <p class="muted">Paste a Personal Access Token from Firefly III (Options → Profile → OAuth → Personal access tokens). It stays on this device.</p>
      <div v-if="route.query.reason === 'unauthorized'" class="banner warn"><Icon name="alert" :size="18" /> The saved token was refused. Paste a new one.</div>
      <label class="field">
        <span>Personal access token</span>
        <textarea v-model.trim="token" rows="4" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="eyJ0eXAiOiJKV1QiLCJhbGciOi…" />
      </label>
      <button class="btn primary block" :disabled="!token || checking">
        <span v-if="checking" class="spinner" />
        <span v-else>Connect</span>
      </button>
      <p v-if="error" class="small bad">{{ error }}</p>
      <p v-if="isDev" class="tiny muted">Local dev stack: the token is in <code>dev/.web-token.env</code> (written by <code>dev/scripts/bootstrap.sh</code>).</p>
    </form>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import { get } from '../lib/api.js'
import { settings } from '../lib/settings.js'
import { store } from '../lib/data.js'

const route = useRoute()
const router = useRouter()
const token = ref('')
const checking = ref(false)
const error = ref('')
const isDev = import.meta.env.DEV

async function connect() {
  checking.value = true
  error.value = ''
  try {
    const json = await get('user', undefined, { token: token.value.replace(/^Bearer\s+/i, ''), quiet: true })
    settings.token = token.value.replace(/^Bearer\s+/i, '')
    store.user = json.data
    Object.keys(store.loaded).forEach((key) => (store.loaded[key] = false))
    router.replace(typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : '/')
  } catch (caught) {
    error.value = caught.status === 401 ? 'Firefly refused this token.' : caught.message
  } finally {
    checking.value = false
  }
}
</script>

<style>
.setup {
  min-height: 100dvh;
  display: grid;
  place-items: center;
  padding: calc(var(--safe-top) + 24px) 16px calc(var(--safe-bottom) + 24px);
}
.setup-card {
  width: 100%;
  max-width: 440px;
  padding: 28px 22px;
  text-align: center;
}
.setup-card .field {
  text-align: left;
}
.setup-logo {
  border-radius: 18px;
  box-shadow: var(--card-shadow);
}
.setup-card h1 {
  margin: 12px 0 6px;
  font-size: 26px;
}
.setup-card .banner {
  text-align: left;
  margin-bottom: 12px;
}
.setup-card textarea {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace !important;
  font-size: 16px !important;
  word-break: break-all;
}
</style>
