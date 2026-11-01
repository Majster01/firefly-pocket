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
      <RouterLink to="/?customize=1" class="row setting-row">
        <span class="grow">
          Customize Home
          <span class="tiny muted" style="display: block">Tiles, their order and size, month or year. Phone and desktop have their own layouts.</span>
        </span>
        <Icon name="chevron" class="chev" :size="18" />
      </RouterLink>
      <label class="row setting-row">
        <span class="grow">Hide amounts on Home</span>
        <input v-model="settings.dashboard.hideBalances" type="checkbox" class="switch" />
      </label>
    </div>

    <h2 class="section-title">New transaction</h2>
    <div class="card">
      <p class="tiny muted" style="margin: 0 0 8px">Starting values when you add a transaction by hand. Templates and edits are not affected. These choices are for this device.</p>
      <label class="setting-row field">
        <span>Starts as</span>
        <select v-model="settings.defaults.type">
          <option v-for="option in DEFAULT_TYPES" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
      </label>
      <template v-for="group in DEFAULT_GROUPS" :key="group.key">
        <h3 class="tiny muted" style="margin: 12px 0 4px">{{ group.label }}</h3>
        <label v-for="field in group.fields" :key="field.key" class="setting-row field">
          <span>{{ field.label }}</span>
          <select v-if="field.kind === 'account'" v-model="settings.defaults[group.key][field.key]">
            <option value="">No default</option>
            <option v-for="account in ownAccounts()" :key="account.id" :value="String(account.id)">{{ account.attributes.name }}</option>
          </select>
          <select v-else-if="field.kind === 'category'" v-model="settings.defaults[group.key][field.key]">
            <option value="">No default</option>
            <option v-for="category in store.categories" :key="category.id" :value="category.attributes.name">{{ category.attributes.name }}</option>
          </select>
          <SuggestInput v-else v-model="settings.defaults[group.key][field.key]" :options="namesOf(field.list)" :placeholder="field.placeholder" />
        </label>
      </template>
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
        <template v-if="push?.subscribed && prefs">
          <label class="row setting-row">
            <span class="grow">
              Import succeeded
              <span class="tiny muted" style="display: block">“NLB import succeeded” and how many transactions it brought</span>
            </span>
            <input v-model="prefs.import_success" type="checkbox" class="switch" @change="savePrefs" />
          </label>
          <label v-if="prefs.import_success" class="row setting-row sub-row">
            <span class="grow">
              Only when something new came in
              <span class="tiny muted" style="display: block">Off: also tell me about nights with nothing new</span>
            </span>
            <input v-model="prefs.import_success_only_new" type="checkbox" class="switch" @change="savePrefs" />
          </label>
          <label class="row setting-row">
            <span class="grow">
              Import problems
              <span class="tiny muted" style="display: block">What went wrong and what to do; the exact error is in the logs</span>
            </span>
            <input v-model="prefs.import_failure" type="checkbox" class="switch" @change="savePrefs" />
          </label>
          <div class="setting-row">
            <div class="row">
              <span class="grow">
                Category suggestions waiting
                <span class="tiny muted" style="display: block">How many suggestions are waiting for your review</span>
              </span>
            </div>
            <div class="row wrap digest-row">
              <div class="seg">
                <button v-for="option in DIGEST" :key="option.value" :class="{ on: prefs.suggestions.frequency === option.value }" @click="setDigest({ frequency: option.value })">{{ option.label }}</button>
              </div>
              <select v-if="prefs.suggestions.frequency === 'weekly'" class="input digest-day" :value="prefs.suggestions.weekday" aria-label="Weekday" @change="setDigest({ weekday: Number($event.target.value) })">
                <option v-for="(name, index) in WEEKDAYS" :key="name" :value="index + 1">{{ name }}</option>
              </select>
            </div>
          </div>
          <label class="row setting-row">
            <span class="grow">
              Upgrades
              <span class="tiny muted" style="display: block">“Upgrade successful” and which package changed</span>
            </span>
            <input v-model="prefs.upgrade_success" type="checkbox" class="switch" @change="savePrefs" />
          </label>
          <p class="tiny muted" style="margin: 4px 0 0">Warnings about backups, expiring bank consents and failed deploys always come through. These choices are for this device.</p>
        </template>
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
    <p class="tiny muted about">Firefly Pocket · design language from firefly-money (MIT){{ pocket?.version ? ` · server ${pocket.version}` : '' }}</p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import SuggestInput from '../components/SuggestInput.vue'
import { get } from '../lib/api.js'
import { accountsOfType, load, loadAll, ownAccounts, store } from '../lib/data.js'
import { settings } from '../lib/settings.js'
import { isStandalone, loadPreferences, pushStatus, pushSupported, savePreferences, subscribePush, unsubscribePush } from '../lib/push.js'
import { toast, toastError } from '../lib/toast.js'

const namesOf = (type) => [...new Set(accountsOfType(type).map((account) => account.attributes.name))]

const THEMES = [
  { value: 'system', label: 'System', icon: 'device' },
  { value: 'light', label: 'Light', icon: 'sun' },
  { value: 'night', label: 'Night', icon: 'moon' },
]
const DEFAULT_TYPES = [
  { value: 'withdrawal', label: 'Expense' },
  { value: 'deposit', label: 'Income' },
  { value: 'transfer', label: 'Transfer' },
]
const DEFAULT_GROUPS = [
  {
    key: 'expense',
    label: 'Expense',
    fields: [
      { key: 'account', label: 'Paid from', kind: 'account' },
      { key: 'payee', label: 'Paid to', kind: 'text', list: 'expense', placeholder: 'Payee' },
      { key: 'category', label: 'Category', kind: 'category' },
    ],
  },
  {
    key: 'income',
    label: 'Income',
    fields: [
      { key: 'payer', label: 'From', kind: 'text', list: 'revenue', placeholder: 'Payer' },
      { key: 'account', label: 'Paid into', kind: 'account' },
      { key: 'category', label: 'Category', kind: 'category' },
    ],
  },
  {
    key: 'transfer',
    label: 'Transfer',
    fields: [
      { key: 'source', label: 'From', kind: 'account' },
      { key: 'destination', label: 'To', kind: 'account' },
    ],
  },
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
const prefs = ref(null)

const DIGEST = [
  { value: 'off', label: 'Off' },
  { value: 'daily', label: 'Daily' },
  { value: 'weekly', label: 'Weekly' },
]
const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
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
    await refreshPrefs()
    pushBusy.value = false
  }
}

async function refreshPrefs() {
  prefs.value = push.value?.subscribed ? await loadPreferences().catch(() => null) : null
}

// Saved as soon as it changes; what the server answers (defaults filled in) is what stays on screen
async function savePrefs() {
  try {
    prefs.value = await savePreferences(prefs.value)
  } catch (error) {
    toastError(error.message)
    await refreshPrefs()
  }
}

function setDigest(change) {
  prefs.value.suggestions = { ...prefs.value.suggestions, ...change }
  return savePrefs()
}

function signOut() {
  settings.token = ''
  Object.keys(store.loaded).forEach((key) => (store.loaded[key] = false))
  router.replace('/setup')
}

onMounted(async () => {
  load('user').catch(() => null)
  loadAll('accounts', 'categories').catch(() => null)
  if (pushSupported()) push.value = await pushStatus().catch(() => ({ configured: false }))
  await refreshPrefs()
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
.sub-row {
  padding-left: 14px;
}
.digest-row {
  gap: 8px;
  margin-top: 8px;
}
.digest-day {
  width: auto;
  min-width: 130px;
}
.settings-actions {
  gap: 8px;
  margin-top: 4px;
}
.about {
  margin: 16px 6px;
}
</style>
