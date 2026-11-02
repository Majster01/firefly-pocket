import { post } from './api.js'
import { forgetAll } from './cache.js'
import { invalidateAll, load, store } from './data.js'
import { refreshBadges } from './badges.js'

/**
 * Settings → Sync everything. Throws away what is kept of Firefly's data, in Pocket (a new data version, which the
 * other devices notice too) and in this browser, so every page reads it again when it is opened. The lists this visit
 * has already loaded and the badges are read again right away, since they are on screen.
 *
 * Rejects when Pocket cannot be reached; the failed request has already been reported (lib/api.js).
 */
export async function syncEverything() {
  // Pocket first: if it cannot be reached, nothing here is thrown away
  await post('data-version')
  await forgetAll()
  const loaded = Object.keys(store.loaded).filter((name) => store.loaded[name])
  invalidateAll()
  await Promise.all([...loaded.map((name) => load(name)), refreshBadges()])
}
