import { reactive, watch } from 'vue'

// Per device, like the Pocket profile on the old app: the Firefly token never leaves this browser except to Pocket's API
const KEY = 'pocket.settings'

const DEFAULTS = {
  token: '',
  theme: 'system', // system | light | night
  features: {
    bankConnections: true,
    ruleSuggestions: true,
    rules: true,
    piggyBanks: true,
    recurring: true,
    templates: true,
    tags: true,
    budgets: true,
  },
  notifications: { enabled: false },
  dashboard: { hideBalances: false },
  // Require a passkey (Face ID, Touch ID) to open the app on this device; timeout: minutes without a touch before it locks again, 0 = as soon as the app is left (lib/lock.js)
  lock: { enabled: false, credentialId: '', timeout: 5 },
  // Starting values for a new transaction (empty = no preference). Accounts are kept by Firefly id, payee/payer by name
  defaults: {
    type: 'withdrawal',
    expense: { account: '', payee: '', category: '' },
    income: { account: '', payer: '', category: '' },
    transfer: { source: '', destination: '' },
  },
}

function mergeDefaults(saved = {}) {
  const base = structuredClone(DEFAULTS.defaults)
  return { type: saved.type ?? base.type, expense: { ...base.expense, ...saved.expense }, income: { ...base.income, ...saved.income }, transfer: { ...base.transfer, ...saved.transfer } }
}

function load() {
  try {
    const saved = JSON.parse(localStorage.getItem(KEY) ?? '{}')
    return { ...DEFAULTS, ...saved, features: { ...DEFAULTS.features, ...saved.features }, notifications: { ...DEFAULTS.notifications, ...saved.notifications }, dashboard: { ...DEFAULTS.dashboard, ...saved.dashboard }, lock: { ...DEFAULTS.lock, ...saved.lock }, defaults: mergeDefaults(saved.defaults) }
  } catch {
    return structuredClone(DEFAULTS)
  }
}

export const settings = reactive(load())

watch(
  settings,
  (value) => {
    try {
      localStorage.setItem(KEY, JSON.stringify(value))
    } catch {
      // Private mode or blocked storage: the settings last for this visit
    }
  },
  { deep: true },
)

export function applyTheme() {
  const root = document.documentElement
  if (settings.theme === 'system') delete root.dataset.theme
  else root.dataset.theme = settings.theme
  const night = settings.theme === 'night' || (settings.theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches)
  document.querySelectorAll('meta[name="theme-color"]').forEach((meta) => meta.setAttribute('content', night ? '#1a1512' : '#d9c7b3'))
}
watch(() => settings.theme, applyTheme)
