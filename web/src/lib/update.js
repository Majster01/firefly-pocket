// A new version of the app reaches the phone by itself: the service worker (sw.js) installs it, skips waiting and takes
// over at once. The page that is open keeps running the code it was loaded with until it is loaded again, though, so a
// version can be installed and still not running. That is "newer" here.

import { toast } from './toast.js'

const supported = () => 'serviceWorker' in navigator && !import.meta.env.DEV

let newer = false

/** As the app starts: remember when a worker takes over from another one, which means this page is out of date. */
export function watchForUpdate() {
  if (!supported()) return
  // The first install claims a page that had no worker; that is no update. Every worker after it is one.
  let hasWorker = Boolean(navigator.serviceWorker.controller)
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (hasWorker) newer = true
    hasWorker = true
  })
}

// Resolves when the worker has taken over (or been dropped), or after ms
function settled(worker, ms) {
  return new Promise((resolve) => {
    const done = () => {
      clearTimeout(timer)
      worker.removeEventListener('statechange', check)
      resolve()
    }
    const check = () => {
      if (worker.state === 'activated' || worker.state === 'redundant') done()
    }
    const timer = setTimeout(done, ms)
    worker.addEventListener('statechange', check)
    check()
  })
}

/**
 * Asks for the newest app and waits for it to be installed.
 * 'newer': a newer version is installed, and loading the page again runs it.
 * 'current': this is the newest.
 * 'unknown': there is no service worker here (a development build, an old browser) or it could not be asked.
 */
export async function checkForUpdate({ wait = 20000 } = {}) {
  if (!supported()) return 'unknown'
  const registration = await navigator.serviceWorker.getRegistration().catch(() => null)
  if (!registration) return 'unknown'
  try {
    // Fetches sw.js again, which is never kept (nginx: no-cache); a changed file installs a new version
    await registration.update()
  } catch {
    // Offline, or Pocket is being deployed: a version installed earlier still counts
    return newer ? 'newer' : 'unknown'
  }
  const installing = registration.installing ?? registration.waiting
  if (installing) await settled(installing, wait)
  if (newer) return 'newer'
  return installing && installing.state !== 'activated' ? 'unknown' : 'current'
}

// The page the user is typing in is not reloaded under them
const editing = () => {
  const element = document.activeElement
  return Boolean(element && (element.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName)))
}

let reloadPending = false

// Right away when nobody is typing; otherwise the next time the app leaves the screen, so it comes back as the newest
function reloadIntoNewer() {
  if (reloadPending) return
  reloadPending = true
  if (document.hidden || !editing()) {
    if (!document.hidden) toast('Pocket was updated, reloading…')
    setTimeout(() => location.reload(), document.hidden ? 0 : 1200)
    return
  }
  document.addEventListener('visibilitychange', () => document.hidden && location.reload())
}

/**
 * As the app starts: an upgrade push (sw.js, check_update) asks the open app to look for a newer version of itself. If
 * one was installed, the app reloads into it. A Firefly III upgrade finds this is the newest and changes nothing.
 */
export function reloadOnUpgradePush() {
  if (!supported()) return
  navigator.serviceWorker.addEventListener('message', async (event) => {
    if (event.data?.type !== 'check-update' || reloadPending) return
    if ((await checkForUpdate()) === 'newer') reloadIntoNewer()
  })
  // Messages wait in a queue until this; a push that came while the page was loading is delivered now
  navigator.serviceWorker.startMessages()
}
