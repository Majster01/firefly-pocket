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
        <span class="grow">
          Hide amounts
          <span class="tiny muted" style="display: block">Everywhere in Pocket: the digits turn to dots. Pocket does this by itself while it is locked.</span>
        </span>
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
      <RouterLink to="/notifications" class="row setting-row">
        <span class="grow">
          Notifications center
          <span class="tiny muted" style="display: block">Everything Pocket has told you, also what a device chose not to be pushed</span>
        </span>
        <Icon name="chevron" class="chev" :size="18" />
      </RouterLink>
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

    <h2 class="section-title">Security</h2>
    <div class="card">
      <label class="row setting-row">
        <span class="grow">
          Require passkey
          <span class="tiny muted" style="display: block">{{ lockHint }}</span>
        </span>
        <input type="checkbox" class="switch" :checked="lockOn" :disabled="(!lockCanUse && !lockOn) || lock.busy" @change="toggleLock" />
      </label>
      <template v-if="lockOn">
        <label class="setting-row field">
          <span>Lock after</span>
          <select v-model.number="settings.lock.timeout" class="input">
            <option v-for="option in TIMEOUTS" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
          <span class="tiny muted" style="font-weight: 400">Counted from the last time you touched the app. “Immediately” locks as soon as you leave it, and after a minute without a touch while it is open.</span>
        </label>
        <label class="row setting-row">
          <span class="grow">
            Ask automatically
            <span class="tiny muted" style="display: block">Opens the Face ID or Touch ID prompt by itself when the lock screen appears. If this browser wants a tap first, tap Unlock.</span>
          </span>
          <input v-model="settings.lock.auto" type="checkbox" class="switch" />
        </label>
        <div class="row setting-row">
          <span class="grow">Lock now</span>
          <button class="btn small" @click="lockNow"><Icon name="lock" :size="17" /> Lock</button>
        </div>
      </template>
      <p class="tiny muted" style="margin: 4px 0 0">This device only. It hides the app; the Firefly token and what the browser kept stay on the device as before. Notifications can still show their text on the lock screen.</p>
    </div>

    <h2 class="section-title">Data</h2>
    <div class="card">
      <div class="row setting-row">
        <span class="grow">
          Sync everything
          <span class="tiny muted" style="display: block">Throws away what Pocket and this device kept of Firefly III, so every page reads it again when you open it, and looks for a newer Pocket. For when something looks out of date.</span>
        </span>
        <button class="btn small" :disabled="syncing" @click="syncAll">
          <span v-if="syncing" class="spinner" />
          <Icon v-else name="refresh" :size="17" />
          Sync
        </button>
      </div>
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
    <p class="tiny muted about">
      Firefly Pocket{{ pocket?.version ? ` · release ${pocket.version}` : '' }}<br />
      Built for <a href="https://www.firefly-iii.org" target="_blank" rel="noopener">Firefly III</a>, the free and open source personal finance manager. With thanks to its authors and community.
    </p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import { ApiError, get } from '../lib/api.js'
import { load, store } from '../lib/data.js'
import { settings } from '../lib/settings.js'
import { syncEverything } from '../lib/sync.js'
import { isStandalone, loadPreferences, pushStatus, pushSupported, savePreferences, subscribePush, unsubscribePush } from '../lib/push.js'
import { toast, toastError } from '../lib/toast.js'
import { TIMEOUTS, confirmPasskey, disable as disableLock, enroll, lock, lockNow, passkeyAvailable, passkeyError, passkeySupported } from '../lib/lock.js'


const THEMES = [
  { value: 'system', label: 'System', icon: 'device' },
  { value: 'light', label: 'Light', icon: 'sun' },
  { value: 'night', label: 'Night', icon: 'moon' },
]
const FEATURES = [
  { key: 'bankConnections', label: 'Bank connections', hint: 'Consents, imports and the system status (FireflyX importer)' },
  { key: 'ruleSuggestions', label: 'Category suggestions', hint: 'Review what the suggester proposes' },
  { key: 'rules', label: 'Rules', hint: "Firefly's own rules: conditions and actions run on new transactions" },
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

const syncing = ref(false)

async function syncAll() {
  syncing.value = true
  let reloading = false
  try {
    const update = await syncEverything()
    if (update === 'newer') {
      // Loading the page again is what runs it. Settings are saved as they change, so nothing is lost
      reloading = true
      toast('Synced. A newer Pocket is ready, reloading…')
      setTimeout(() => location.reload(), 1200)
    } else {
      toast(update === 'current' ? 'Synced. Pocket is up to date.' : 'Synced. Pages read Firefly III again as you open them.')
    }
  } catch (error) {
    // A failed request has already said why
    if (!(error instanceof ApiError)) toastError(error.message)
  } finally {
    // Stays pressed until the page reloads
    if (!reloading) syncing.value = false
  }
}

// ---- require passkey (lib/lock.js)
const lockOn = computed(() => Boolean(settings.lock.enabled && settings.lock.credentialId))
const lockCanUse = ref(false)
const lockHint = computed(() => {
  if (lockOn.value) return 'Face ID, Touch ID or the device passcode opens Pocket on this device.'
  if (!passkeySupported()) return 'This browser cannot make a passkey here. It needs HTTPS.'
  if (!lockCanUse.value) return 'This device has no Face ID, Touch ID or screen lock to use.'
  return 'Ask for Face ID or Touch ID to open Pocket, and again after it has been left alone for a while.'
})
async function toggleLock(event) {
  const input = event.target
  const turnOn = input.checked
  try {
    if (turnOn) {
      await enroll()
      toast('Passkey required')
    } else {
      // Whoever has the unlocked app must not be able to switch the lock off
      await confirmPasskey()
      disableLock()
      toast('Passkey not required')
    }
  } catch (error) {
    input.checked = !turnOn
    toastError(passkeyError(error))
  }
}

function signOut() {
  settings.token = ''
  Object.keys(store.loaded).forEach((key) => (store.loaded[key] = false))
  router.replace('/setup')
}

onMounted(async () => {
  passkeyAvailable().then((ok) => (lockCanUse.value = ok))
  load('user').catch(() => null)
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
