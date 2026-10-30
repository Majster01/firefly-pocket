<template>
  <div>
    <PageHeader title="Settings" />

    <h2 class="section-title">Appearance</h2>
    <div class="card">
      <div class="theme-picker">
        <button v-for="option in THEMES" :key="option.value" class="theme-option" :class="[option.value, { on: settings.theme === option.value }]" :aria-pressed="settings.theme === option.value" @click="settings.theme = option.value">
          <span class="theme-swatch"><Icon :name="option.icon" :size="20" /></span>
          <span>{{ option.label }}</span>
        </button>
      </div>
      <label class="row setting-row">
        <span class="grow">Hide amounts on Home</span>
        <input v-model="settings.dashboard.hideBalances" type="checkbox" class="switch" />
      </label>
    </div>

    <h2 class="section-title">Features</h2>
    <div class="card">
      <label v-for="feature in FEATURES" :key="feature.key" class="row setting-row">
        <span class="grow">
          {{ feature.label }}
          <span class="tiny muted" style="display: block">{{ feature.hint }}</span>
        </span>
        <input v-model="settings.features[feature.key]" type="checkbox" class="switch" />
      </label>
    </div>

    <h2 class="section-title">Notifications</h2>
    <div class="card">
      <div v-if="!pushSupported()" class="small muted">This browser has no Web Push.<template v-if="isIos"> On iPhone, add Pocket to the Home Screen (Share → Add to Home Screen) and open it from there.</template></div>
      <template v-else>
        <label class="row setting-row">
          <span class="grow">
            Push notifications
            <span class="tiny muted" style="display: block">{{ pushHint }}</span>
          </span>
          <input type="checkbox" class="switch" :checked="push?.subscribed" :disabled="!push?.configured || pushBusy" @change="togglePush" />
        </label>
      </template>
    </div>

    <h2 class="section-title">Firefly III</h2>
    <div class="card">
      <div class="row setting-row">
        <Icon name="key" />
        <span class="grow">
          {{ store.user?.attributes.email ?? 'Connected' }}
          <span class="tiny muted" style="display: block">Token …{{ settings.token.slice(-8) }}<template v-if="about"> · Firefly III {{ about.version }} · API {{ about.api_version }}</template></span>
        </span>
      </div>
      <div class="row wrap settings-actions">
        <RouterLink to="/setup" class="btn small">Change token</RouterLink>
        <button class="btn small danger" @click="signOut"><Icon name="logout" :size="17" /> Sign out</button>
      </div>
    </div>
    <p class="tiny muted about">Firefly Pocket · design language from firefly-money (MIT) · {{ pocket?.version ? `server ${pocket.version}` : '' }}</p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import { get } from '../lib/api.js'
import { load, store } from '../lib/data.js'
import { settings } from '../lib/settings.js'
import { isStandalone, pushStatus, pushSupported, subscribePush, unsubscribePush } from '../lib/push.js'
import { toast, toastError } from '../lib/toast.js'

const THEMES = [
  { value: 'system', label: 'System', icon: 'device' },
  { value: 'light', label: 'Light', icon: 'sun' },
  { value: 'night', label: 'Night', icon: 'moon' },
]
const FEATURES = [
  { key: 'bankConnections', label: 'Bank connections', hint: 'Consents, imports and the system status (FireflyX importer)' },
  { key: 'ruleSuggestions', label: 'Category suggestions', hint: 'Review what the suggester proposes' },
  { key: 'budgets', label: 'Budgets', hint: 'Monthly limits on Home and in the composer' },
  { key: 'tags', label: 'Tags', hint: '' },
  { key: 'piggyBanks', label: 'Piggy banks', hint: '' },
  { key: 'recurring', label: 'Recurring transactions', hint: '' },
  { key: 'templates', label: 'Templates', hint: 'Quick picks when adding a transaction' },
]

const router = useRouter()
const isIos = /iPhone|iPad/.test(navigator.userAgent)
const push = ref(null)
const pushBusy = ref(false)
const about = ref(null)
const pocket = ref(null)

const pushHint = computed(() => {
  if (!push.value) return 'Checking…'
  if (!push.value.configured) return 'Not set up on the server (VAPID keys)'
  if (push.value.permission === 'denied') return 'Blocked in the browser settings'
  if (isIos && !isStandalone()) return 'Open Pocket from the Home Screen to turn this on'
  return 'Imports, failed nights and new suggestions'
})

async function togglePush(event) {
  pushBusy.value = true
  try {
    if (event.target.checked) await subscribePush(push.value.publicKey)
    else await unsubscribePush()
    toast(event.target.checked ? 'Notifications on' : 'Notifications off')
  } catch (error) {
    toastError(error.message)
  } finally {
    push.value = await pushStatus().catch(() => push.value)
    pushBusy.value = false
  }
}

function signOut() {
  settings.token = ''
  Object.keys(store.loaded).forEach((key) => (store.loaded[key] = false))
  router.replace('/setup')
}

onMounted(async () => {
  load('user')
  if (pushSupported()) push.value = await pushStatus().catch(() => ({ configured: false }))
  about.value = (await get('about', undefined, { quiet: true }).catch(() => null))?.data ?? null
  pocket.value = await get('info', undefined, { quiet: true }).catch(() => null)
})
</script>

<style>
.theme-picker {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  margin-bottom: 8px;
}
.theme-option {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 12px 8px;
  border-radius: 18px;
  border: 1px solid var(--field-border);
  background: var(--field);
  font-weight: 600;
  font-size: 14px;
}
.theme-option.on {
  border-color: var(--ink);
  box-shadow: 0 0 0 2px var(--ink);
}
.theme-swatch {
  display: grid;
  place-items: center;
  width: 100%;
  height: 54px;
  border-radius: 12px;
  background: linear-gradient(135deg, #f1e4d3, #b98d6b);
  color: #17130f;
}
.theme-option.night .theme-swatch {
  background: linear-gradient(135deg, #2b3a55, #8a4b25);
  color: #f3ebe3;
}
.theme-option.system .theme-swatch {
  background: linear-gradient(135deg, #f1e4d3 50%, #2b3a55 50%);
}
.setting-row {
  padding: 12px 0;
  border-top: 1px solid var(--line);
  gap: 12px;
}
.card > .setting-row:first-child {
  border-top: 0;
  padding-top: 0;
}
.settings-actions {
  gap: 8px;
  margin-top: 4px;
}
.about {
  margin: 16px 6px;
}
</style>
