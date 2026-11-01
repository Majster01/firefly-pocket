import { watch } from 'vue'
import { settings } from './settings.js'

// Firefly answers kept in this browser, also between visits (IndexedDB), for as long as Pocket's data version stays
// the same. Pocket moves the version on whenever Firefly's data may have changed: a save in this app or on another
// device, the nightly import, Firefly's webhook (README: Caching). So an answer kept under the current version is
// still right and needs no request at all; under an older one it is thrown away.
//
// The version is asked again before a kept answer is used when it was last asked more than RECHECK_MS ago, when the
// app comes back to the screen, and after anything this app saved. While that check runs, reads wait for it: a kept
// answer is never shown and then swapped for a newer one.

const DB_NAME = 'pocket-cache'
const STORE = 'responses'
const OWNER_KEY = '#owner'
const RECHECK_MS = 30000
// Bigger answers are fetched every time rather than kept
const MAX_KEPT_CHARS = 1_000_000

// Pocket's own data, never kept: the same list as NOT_CACHED in server/app/Services/ResponseCache.php
const NOT_KEPT = [
  'assistant', 'bank-connections', 'currencies/exchange', 'custom-icons', 'data-version', 'info', 'internal', 'profiles', 'push',
  'system-status', 'test', 'transaction-templates', 'user', 'accounts/groups',
]

export function isKept(path) {
  const clean = path.replace(/^\/+/, '').split('?')[0]
  return !NOT_KEPT.some((prefix) => clean === prefix || clean.startsWith(`${prefix}/`))
}

const entries = new Map() // key → { key, version, at, data }
let version = null // as Pocket last said; null: unknown, or Pocket keeps nothing
let ttl = 0
let checkedAt = 0
let checking = null
let db = null
let loaded = null

// ---------------------------------------------------------------- version

async function askVersion() {
  try {
    const response = await fetch(new URL('/api/data-version', location.origin), {
      headers: { Authorization: `Bearer ${settings.token}`, Accept: 'application/json' },
      cache: 'no-store',
    })
    if (!response.ok) return undefined
    return (await response.json())?.data ?? undefined
  } catch {
    return undefined
  }
}

/** Asks Pocket for the data version now. Reads that start meanwhile wait for the answer. */
export function recheck() {
  if (!settings.token) return Promise.resolve(null)
  const asked = askVersion().then(async (answer) => {
    // A newer check (after a save) started meanwhile: its answer counts, not this one
    if (checking !== asked) return checking
    checking = null
    checkedAt = Date.now()
    if (answer === undefined) {
      // Pocket did not answer: nothing kept is used until it does, but nothing is thrown away either
      version = null
      checkedAt = 0
      return null
    }
    ttl = Number(answer.ttl ?? 0) * 1000
    const next = answer.version ?? null
    if (next !== version) {
      await ensureLoaded()
      // A version this browser has not seen: everything kept under another one is out of date
      if ([...entries.values()].some((entry) => entry.version !== next)) clearKept({ keepOwner: true })
      version = next
    }
    return version
  })
  checking = asked
  return asked
}

function currentVersion() {
  if (!settings.token) return Promise.resolve(null)
  if (!checking && Date.now() - checkedAt > RECHECK_MS) recheck()
  return checking ?? Promise.resolve(version)
}

/** After this app changed something: Pocket has most likely moved the version on. */
export const changed = () => recheck()

// ------------------------------------------------------------------ reads

/** The kept answer for key if it is still right: { version, data }, data undefined when there is none. */
export async function kept(key) {
  const current = await currentVersion()
  if (current === null) return { version: null }
  await ensureLoaded()
  const entry = entries.get(key)
  // Not past midnight either: some answers move with the date alone (a recurring transaction's next date)
  const fresh = entry && entry.version === current && Date.now() - entry.at < ttl && new Date(entry.at).toDateString() === new Date().toDateString()
  return { version: current, data: fresh ? structuredClone(entry.data) : undefined }
}

/** Keeps an answer under the version that was current before it was asked for. */
export function keep(key, keptVersion, data, size) {
  if (keptVersion === null || keptVersion !== version || size > MAX_KEPT_CHARS) return
  const entry = { key, version: keptVersion, at: Date.now(), data: structuredClone(data) }
  entries.set(key, entry)
  write((store) => store.put(entry))
}

// -------------------------------------------------------------- IndexedDB

// Not a secret, only tells this browser's tokens apart, so another token never sees what one kept
function owner() {
  let hash = 0x811c9dc5
  for (const char of settings.token ?? '') hash = Math.imul(hash ^ char.charCodeAt(0), 0x01000193) >>> 0
  return `${hash.toString(16)}:${(settings.token ?? '').length}`
}

function request(idbRequest) {
  return new Promise((resolve, reject) => {
    idbRequest.onsuccess = () => resolve(idbRequest.result)
    idbRequest.onerror = () => reject(idbRequest.error)
  })
}

function ensureLoaded() {
  loaded ??= (async () => {
    try {
      const opening = indexedDB.open(DB_NAME, 1)
      opening.onupgradeneeded = () => opening.result.createObjectStore(STORE, { keyPath: 'key' })
      db = await request(opening)
      const rows = await request(db.transaction(STORE).objectStore(STORE).getAll())
      const ownerRow = rows.find((row) => row.key === OWNER_KEY)
      if (ownerRow?.owner !== owner()) {
        clearKept()
        return
      }
      for (const row of rows) if (row.key !== OWNER_KEY && !entries.has(row.key)) entries.set(row.key, row)
    } catch {
      // Private browsing or blocked storage: kept for this visit only
      db = null
    }
  })()
  return loaded
}

function write(change) {
  if (!db) return
  try {
    change(db.transaction(STORE, 'readwrite').objectStore(STORE))
  } catch {
    // Full or closed: answers are fetched again, that is all
  }
}

function clearKept({ keepOwner = false } = {}) {
  entries.clear()
  write((store) => {
    store.clear()
    store.put({ key: OWNER_KEY, owner: owner() })
  })
  if (!keepOwner) version = null
}

// ------------------------------------------------------------------ hooks

/** As the app starts: read what was kept and ask the version, side by side, before the first page asks for data. */
export function warmUp() {
  if (!settings.token) return
  ensureLoaded()
  recheck()
}

// Another token, or signed out: nothing kept for the old one is shown
watch(
  () => settings.token,
  () => {
    clearKept()
    checkedAt = 0
    checking = null
  },
)

// Back on the screen (the Home Screen app is reopened from the background): the night's import may have run
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && settings.token) recheck()
})
