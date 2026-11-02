import { reactive, ref, toRaw, watch } from 'vue'
import { get, put } from './api.js'
import { isoDate, monthRange } from './format.js'

// The Home page is a grid of tiles. A phone and a desktop get separate layouts: on the phone Home is a quick glance
// down one column (what came in and went out, where it went, anything to act on); on a desktop it is an overview
// across columns. Each tile keeps its order, size, visibility and options per layout.

export const DEVICES = {
  phone: { label: 'Phone', columns: 2, sizes: { half: 1, full: 2 } },
  desktop: { label: 'Desktop', columns: 6, sizes: { third: 2, half: 3, twothirds: 4, full: 6 } },
}
export const SIZE_LABELS = { half: '½', full: 'Full', third: '⅓', twothirds: '⅔' }

/**
 * Every tile Home knows. phone/desktop: the sizes it can take there (the first is the smallest).
 * options: what the tile's settings sheet offers; when(options) hides an option that does not apply. Choices are
 * [value, label] pairs in the order shown (an object would list its number keys first, whatever order they are written in).
 */
export const TILES = {
  networth: { title: 'Net worth', icon: 'wallet', phone: ['half', 'full'], desktop: ['third', 'half', 'twothirds', 'full'] },
  cashflow: {
    title: 'In · Out · Surplus',
    icon: 'transfer',
    phone: ['full'],
    desktop: ['third', 'half', 'twothirds', 'full'],
    options: {
      compare: { label: 'Compare with the period before', type: 'switch', default: true },
      kept: { label: 'Show the % kept / overspent under the surplus', type: 'switch', default: true },
    },
  },
  categories: {
    title: 'Spending by category',
    icon: 'pie',
    phone: ['full'],
    desktop: ['third', 'half', 'twothirds', 'full'],
    options: {
      chart: { label: 'Chart', type: 'choice', choices: [['bars', 'Bars'], ['donut', 'Donut']], default: 'bars' },
      limit: { label: 'Categories listed', type: 'choice', choices: [[5, '5'], [8, '8'], [12, '12'], [0, 'All']], default: 8, when: (options) => options.chart === 'bars' },
      uncategorized: { label: 'Include uncategorized', type: 'switch', default: true },
    },
  },
  trend: {
    title: 'Over time',
    icon: 'chart',
    phone: ['full'],
    desktop: ['third', 'half', 'twothirds', 'full'],
    options: { metric: { label: 'Show', type: 'choice', choices: [['spending', 'Spending'], ['income', 'Income']], default: 'spending' } },
  },
  // view: only offered in that period view (a calendar of days has no yearly form)
  calendar: { title: 'Calendar', icon: 'calendar', view: 'month', phone: ['full'], desktop: ['third', 'half', 'twothirds', 'full'] },
  budgets: { title: 'Budgets', icon: 'pie', view: 'month', phone: ['full'], desktop: ['third', 'half', 'twothirds', 'full'], feature: 'budgets' },
  recent: {
    title: 'Latest transactions',
    icon: 'list',
    phone: ['full'],
    desktop: ['third', 'half', 'twothirds', 'full'],
    options: { count: { label: 'Transactions', type: 'choice', choices: [[5, '5'], [8, '8'], [12, '12']], default: 5 } },
  },
  accounts: {
    title: 'Accounts',
    icon: 'bank',
    phone: ['full'],
    desktop: ['third', 'half', 'twothirds', 'full'],
    options: { include: { label: 'Accounts', type: 'choice', choices: [['assets', 'Cash & bank'], ['all', 'All, with investments and loans']], default: 'all' } },
  },
  uncategorized: { title: 'Uncategorized', icon: 'alert', phone: ['half', 'full'], desktop: ['third', 'half', 'twothirds', 'full'] },
  piggy: { title: 'Piggy banks', icon: 'piggy', phone: ['full'], desktop: ['third', 'half', 'twothirds', 'full'], feature: 'piggyBanks' },
  suggestions: { title: 'Suggestions notice', icon: 'sparkles', notice: true, phone: ['full'], desktop: ['third', 'half', 'twothirds', 'full'], feature: 'ruleSuggestions' },
  connections: { title: 'Bank connections notice', icon: 'bank', notice: true, phone: ['full'], desktop: ['third', 'half', 'twothirds', 'full'], feature: 'bankConnections' },
}

const tile = (id, size, visible = true, options = {}) => ({ id, size, visible, options })

const DEFAULTS = {
  phone: {
    view: 'month',
    tiles: [
      tile('suggestions', 'full'),
      tile('connections', 'full'),
      tile('cashflow', 'full'),
      tile('calendar', 'full'),
      tile('categories', 'full', true, { chart: 'bars', limit: 5 }),
      tile('networth', 'full'),
      tile('budgets', 'full'),
      tile('recent', 'full', true, { count: 5 }),
      tile('trend', 'full'),
      tile('uncategorized', 'half', false),
      tile('accounts', 'full', false),
      tile('piggy', 'full', false),
    ],
  },
  desktop: {
    view: 'month',
    tiles: [
      tile('networth', 'half'),
      tile('cashflow', 'half'),
      tile('suggestions', 'half'),
      tile('connections', 'half'),
      tile('categories', 'half', true, { chart: 'donut' }),
      tile('trend', 'half'),
      tile('calendar', 'half'),
      tile('budgets', 'third'),
      tile('recent', 'third', true, { count: 8 }),
      tile('accounts', 'third'),
      tile('uncategorized', 'third', false),
      tile('piggy', 'third', false),
    ],
  },
}

// structuredClone throws on Vue's reactive proxies: copy plain JSON instead
const clone = (value) => JSON.parse(JSON.stringify(toRaw(value)))

export const defaultLayout = (device) => clone(DEFAULTS[device])

export function optionsOf(entry) {
  const schema = TILES[entry.id].options ?? {}
  return Object.fromEntries(Object.entries(schema).map(([key, option]) => [key, entry.options?.[key] ?? option.default]))
}

/** Keeps a saved layout valid: unknown tiles out, tiles added in a later version in (hidden), sizes allowed. */
function normalize(device, layout) {
  const base = defaultLayout(device)
  if (!layout || !Array.isArray(layout.tiles)) return base
  const seen = new Set()
  const tiles = []
  for (const entry of layout.tiles) {
    if (!TILES[entry?.id] || seen.has(entry.id)) continue
    seen.add(entry.id)
    const sizes = TILES[entry.id][device]
    tiles.push({ id: entry.id, visible: entry.visible !== false, size: sizes.includes(entry.size) ? entry.size : sizes.at(-1), options: { ...entry.options } })
  }
  for (const entry of base.tiles) {
    if (!seen.has(entry.id)) tiles.push({ ...entry, visible: false })
  }
  return { view: ['month', 'year'].includes(layout.view) ? layout.view : base.view, tiles }
}

// ----------------------------------------------------------------- state

const KEY = 'pocket.home'

function loadLocal() {
  try {
    const saved = JSON.parse(localStorage.getItem(KEY) ?? 'null')
    return { phone: normalize('phone', saved?.phone), desktop: normalize('desktop', saved?.desktop), updated: Number(saved?.updated ?? 0) }
  } catch {
    return { phone: defaultLayout('phone'), desktop: defaultLayout('desktop'), updated: 0 }
  }
}

export const home = reactive(loadLocal())

const wide = matchMedia('(min-width: 900px)')
export const device = ref(wide.matches ? 'desktop' : 'phone')
wide.addEventListener('change', (event) => (device.value = event.matches ? 'desktop' : 'phone'))

/** Call after any change a person made, so it is saved here and on the server. */
export function touch() {
  home.updated = Date.now()
}

let syncing = false
watch(
  () => home.updated,
  () => {
    try {
      localStorage.setItem(KEY, JSON.stringify(home))
    } catch {
      // Not saved on this device; the server copy still is
    }
    if (!syncing) pushSoon()
  },
)

export function resetLayout(which) {
  home[which] = defaultLayout(which)
  touch()
}

// A full-width phone tile is half a desktop row; a half-width one a third. Back the other way, small tiles stay half.
const SIZE_MAP = { desktop: { full: 'half', half: 'third' }, phone: { third: 'half', half: 'full', twothirds: 'full', full: 'full' } }

export function copyLayout(from, to) {
  const copy = clone(home[from])
  copy.tiles = copy.tiles.map((entry) => ({ ...entry, size: SIZE_MAP[to][entry.size] ?? entry.size }))
  home[to] = normalize(to, copy)
  touch()
}

// -------------------------------------------- sync through Pocket's profile

// Pocket keeps a profile per Firefly token with free-form settings: the layouts live in settings.home, so the
// phone layout follows you to a reinstalled Home Screen app and the desktop one to another browser.
//
// Device clocks disagree, so they only settle a real conflict. This device remembers the server's updated_at and its
// own layout stamp from the last time the two matched: a server copy that changed since then while this one did not
// wins, and the other way round.
let profile = null
let pushTimer = null
const SYNCED_KEY = 'pocket.home.synced'
let synced = (() => {
  try {
    return JSON.parse(localStorage.getItem(SYNCED_KEY) ?? 'null') ?? {}
  } catch {
    return {}
  }
})()

function markSynced(updated = home.updated) {
  synced = { at: profile?.updated_at ?? null, updated }
  try {
    localStorage.setItem(SYNCED_KEY, JSON.stringify(synced))
  } catch {
    // Without it the device clocks decide, as before
  }
}

export async function pullHome() {
  try {
    const json = await get('profiles', undefined, { quiet: true })
    profile = json?.data?.[0] ?? null
    const remote = profile?.settings?.home
    const remoteUpdated = Number(remote?.updated ?? 0)
    const remoteChanged = Boolean(synced.at) && profile?.updated_at !== synced.at
    const localChanged = home.updated !== synced.updated
    const takeRemote = remote && remoteUpdated !== home.updated && (synced.at && remoteChanged !== localChanged ? remoteChanged : remoteUpdated > home.updated)
    if (takeRemote) {
      syncing = true
      Object.assign(home, { phone: normalize('phone', remote.phone), desktop: normalize('desktop', remote.desktop), updated: remoteUpdated })
      await Promise.resolve()
      syncing = false
      markSynced()
    } else if (home.updated && remoteUpdated !== home.updated) {
      pushSoon(0)
    } else if (profile) {
      markSynced()
    }
  } catch {
    // Offline or an older server: the local layout is enough
  }
}

function pushSoon(delay = 800) {
  clearTimeout(pushTimer)
  pushTimer = setTimeout(async () => {
    if (!profile) return
    const settings = { ...(profile.settings ?? {}), home: clone(home) }
    try {
      profile = (await put(`profiles/${profile.id}`, { name: profile.name, settings }, { quiet: true }))?.data ?? profile
      markSynced(settings.home.updated)
    } catch {
      // Tried again on the next change
    }
  }, delay)
}

// ------------------------------------------------------------------ period

/** offset 0 = this month or year, -1 the one before, … */
export function periodRange(view, offset) {
  if (view === 'year') {
    const year = new Date().getFullYear() + offset
    return { start: `${year}-01-01`, end: `${year}-12-31` }
  }
  return monthRange(offset)
}

const monthName = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' })
const monthCompact = new Intl.DateTimeFormat(undefined, { month: 'short', year: 'numeric' })
const monthOnly =new Intl.DateTimeFormat(undefined, { month: 'long' })

export function periodLabel(view, offset, { short = false, compact = false } = {}) {
  const range = periodRange(view, offset)
  if (view === 'year') return range.start.slice(0, 4)
  const date = new Date(`${range.start}T12:00:00`)
  if (compact) return monthCompact.format(date)
  return short && date.getFullYear() === new Date().getFullYear() ? monthOnly.format(date) : monthName.format(date)
}

/** The part of the period that has happened: a running month or year ends today. */
export function elapsedRange(range) {
  const today = isoDate()
  return { start: range.start, end: range.end > today ? today : range.end }
}

/** Firefly search terms for a period, for links to the transaction list. */
export const periodQuery = (range) => `date_after:${range.start} date_before:${range.end}`
