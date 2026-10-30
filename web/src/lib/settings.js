import { reactive, watch } from 'vue'

// Per device, like the Pocket profile on the old app: the Firefly token never leaves this browser except to Pocket's API
const KEY = 'pocket.settings'

const DEFAULTS = {
  token: '',
  theme: 'system', // system | light | night
  features: {
    bankConnections: true,
    ruleSuggestions: true,
    piggyBanks: true,
    recurring: true,
    templates: true,
    tags: true,
    budgets: true,
  },
  notifications: { enabled: false },
  dashboard: { hideBalances: false },
}

function load() {
  try {
    const saved = JSON.parse(localStorage.getItem(KEY) ?? '{}')
    return { ...DEFAULTS, ...saved, features: { ...DEFAULTS.features, ...saved.features }, notifications: { ...DEFAULTS.notifications, ...saved.notifications }, dashboard: { ...DEFAULTS.dashboard, ...saved.dashboard } }
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
