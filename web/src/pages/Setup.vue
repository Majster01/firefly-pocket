<template>
  <div class="setup">
    <form class="card setup-card" @submit.prevent="connect">
      <img src="/favicon.svg" alt="" width="64" height="64" class="setup-logo" />
      <h1>Firefly Pocket</h1>
      <div v-if="route.query.reason === 'unauthorized'" class="banner warn"><Icon name="alert" :size="18" /> The saved token was refused. {{ offer ? 'Sign in again, or paste a new one.' : 'Paste a new one.' }}</div>
      <template v-if="offer">
        <button type="button" class="btn primary block" :disabled="signingIn" @click="signIn">
          <span v-if="signingIn" class="spinner" />
          <span v-else>Sign in with Firefly III</span>
        </button>
        <p class="small muted">Firefly III asks for your password, its two-factor code if you turned that on, and the first time whether to let Firefly Pocket in.</p>
        <p class="small muted setup-or">Or paste a Personal Access Token from Firefly III (Options → Profile → OAuth → Personal access tokens). Either stays on this device.</p>
      </template>
      <p v-else class="muted">Paste a Personal Access Token from Firefly III (Options → Profile → OAuth → Personal access tokens). It stays on this device.</p>
      <label class="field">
        <span>Personal access token</span>
        <textarea v-model.trim="token" rows="4" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="eyJ0eXAiOiJKV1QiLCJhbGciOi…" />
      </label>
      <button class="btn block" :class="{ primary: !offer }" :disabled="!token || checking">
        <span v-if="checking" class="spinner" />
        <span v-else>Connect</span>
      </button>
      <p v-if="error" class="small bad">{{ error }}</p>
      <!-- Changing the token from Settings: the Home Screen app has no browser back button -->
      <p v-if="settings.token && route.query.reason !== 'unauthorized'"><RouterLink :to="next || '/settings'" class="small setup-cancel">Cancel</RouterLink></p>
      <p v-if="isDev" class="tiny muted">Local dev stack: the token is in <code>dev/.web-token.env</code> (written by <code>dev/scripts/bootstrap.sh</code>).</p>
    </form>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import { get } from '../lib/api.js'
import { settings } from '../lib/settings.js'
import { store } from '../lib/data.js'
import { signInOffer, startSignIn } from '../lib/signin.js'
import './setup.css'

const route = useRoute()
const router = useRouter()
const token = ref('')
const checking = ref(false)
const error = ref('')
const isDev = import.meta.env.DEV

const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') && !route.query.next.startsWith('//') ? route.query.next : ''
const REFUSED = 'Firefly refused this token.'
// Sign in with Firefly III, where the server offers it (lib/signin.js)
const offer = ref(null)
const signingIn = ref(false)
onMounted(async () => (offer.value = await signInOffer()))

async function signIn() {
  signingIn.value = true
  error.value = ''
  try {
    await startSignIn(offer.value, next)
  } catch (caught) {
    error.value = caught.message
    signingIn.value = false
  }
}

async function connect() {
  checking.value = true
  error.value = ''
  try {
    const json = await get('user', undefined, { token: token.value.replace(/^Bearer\s+/i, ''), quiet: true })
    // Only a user proves the token works
    if (!json?.data) {
      error.value = REFUSED
      return
    }
    settings.token = token.value.replace(/^Bearer\s+/i, '')
    store.user = json.data
    Object.keys(store.loaded).forEach((key) => (store.loaded[key] = false))
    router.replace(next || '/')
  } catch (caught) {
    error.value = caught.status === 401 ? REFUSED : caught.message
  } finally {
    checking.value = false
  }
}
</script>
