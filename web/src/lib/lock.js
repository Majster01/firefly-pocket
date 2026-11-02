import { reactive, watch } from 'vue'
import { settings } from './settings.js'

// "Require passkey": the app asks for Face ID, Touch ID or the device's own screen lock (a WebAuthn platform passkey,
// user verification required) before it shows anything, and again after it has gone untouched for a while.
//
// This is a lock on what the app shows, for a phone or a Mac that is handed over while unlocked. It is per device: each
// one enrolls its own passkey and keeps its own timeout, like the rest of settings.js. Nothing is checked by Pocket's
// server (there are no accounts there), and the Firefly token and the cache stay in the browser as they were, so it
// does not stop someone with the browser's developer tools.
//
// The timeout counts from the last interaction (a touch, a key, a scroll): the timestamp is kept in localStorage, so
// it also decides whether a relaunch of the app, or its return from the background, needs the passkey again. The
// timestamp exists only after a successful unlock, so anything unreadable or missing means locked.

const ACTIVE_KEY = 'pocket.lock.active'
const PERSIST_EVERY_MS = 5000
const TICK_MS = 5000
// "Immediately" is about leaving the app. An app that is open and untouched for this long locks in any case.
const MIN_IDLE_MS = 60_000

export const TIMEOUTS = [
  { value: 0, label: 'Immediately' },
  { value: 1, label: '1 minute' },
  { value: 5, label: '5 minutes' },
  { value: 15, label: '15 minutes' },
  { value: 60, label: '1 hour' },
  { value: 240, label: '4 hours' },
]

// While locked the app is shown as usual with its amounts hidden (lib/format.js), and a bar over the navigation bar
// asks for the passkey (components/LockBar.vue). Nothing can be touched behind it.
export const lock = reactive({ locked: false, busy: false, error: '' })

const enabled = () => Boolean(settings.lock.enabled && settings.lock.credentialId)
const timeoutMs = () => Math.max(0, Number(settings.lock.timeout) || 0) * 60_000
const expired = (limit) => !lastActive || Date.now() - lastActive > limit

let lastActive = 0
let persistedAt = 0
// The prompt opens by itself once each time the lock screen comes up or the app comes back to the screen. If the
// browser refuses it (it may want a tap) or it is cancelled, the Unlock button is what is left: never a retry loop.
let autoTried = false
let autoTimer = null

function persist() {
  persistedAt = Date.now()
  try {
    localStorage.setItem(ACTIVE_KEY, String(lastActive))
  } catch {
    // Blocked storage: the next launch finds no timestamp and asks for the passkey
  }
}

function forget() {
  lastActive = 0
  try {
    localStorage.removeItem(ACTIVE_KEY)
  } catch {
    // Nothing kept
  }
}

function readStored() {
  try {
    return Number(localStorage.getItem(ACTIVE_KEY)) || 0
  } catch {
    return 0
  }
}

/** Locks now (the Lock now button, the timeout, leaving the app with "Immediately"). */
export function lockNow() {
  if (!enabled()) return
  forget()
  lock.error = ''
  autoTried = false
  lock.locked = true
  // A field that was being typed in must not keep the keyboard up behind the bar
  if (document.activeElement instanceof HTMLElement) document.activeElement.blur()
}

/** The lock screen's own start: opens the passkey prompt without a tap, once, if the setting is on and the app is on screen. */
export function autoUnlock() {
  clearTimeout(autoTimer)
  // A moment for the app to be the one in front (coming back from the background), then look again
  autoTimer = setTimeout(() => {
    if (!lock.locked || lock.busy || document.hidden || autoTried || !settings.lock.auto) return
    autoTried = true
    unlock({ quiet: true })
  }, 300)
}

function opened() {
  lastActive = Date.now()
  persist()
  lock.locked = false
  lock.error = ''
}

// ------------------------------------------------------------ activity

function onActivity() {
  if (lock.locked || !enabled()) return
  // A touch after the time ran out (a sleeping laptop, a timer that was held back) must not extend it
  if (expired(Math.max(timeoutMs(), MIN_IDLE_MS))) return lockNow()
  lastActive = Date.now()
  if (lastActive - persistedAt > PERSIST_EVERY_MS) persist()
}

function check() {
  if (!enabled() || lock.locked) return
  if (expired(Math.max(timeoutMs(), MIN_IDLE_MS))) lockNow()
}

function onHidden() {
  if (!enabled()) return
  // The app switcher takes its picture of the page about now: show nothing of it, whether or not it locks
  document.documentElement.classList.add('shielded')
  if (lock.locked) return
  if (timeoutMs() === 0) lockNow()
  else persist()
}

function onVisible() {
  document.documentElement.classList.remove('shielded')
  if (!enabled()) return
  if (!lock.locked && expired(timeoutMs())) lockNow()
  if (lock.locked) {
    autoTried = false
    autoUnlock()
  }
}

/** As the app starts: decide whether it opens locked, and watch for touches and for leaving the app. */
export function initLock() {
  if (enabled()) {
    lastActive = readStored()
    lock.locked = timeoutMs() === 0 || expired(timeoutMs())
  }
  for (const type of ['pointerdown', 'keydown', 'touchstart', 'wheel', 'scroll']) {
    window.addEventListener(type, onActivity, { capture: true, passive: true })
  }
  document.addEventListener('visibilitychange', () => (document.hidden ? onHidden() : onVisible()))
  window.addEventListener('pagehide', onHidden)
  // On iOS the app switcher gesture, Control Center, Slide Over and split view take the picture while the page is still
  // "visible": only the window losing focus is reported, and it is reported early. Shield on that too (nothing locks
  // from it: leaving the app for real still arrives as visibilitychange), and lift the shield when focus comes back.
  window.addEventListener('blur', () => enabled() && document.documentElement.classList.add('shielded'))
  window.addEventListener('focus', () => !document.hidden && document.documentElement.classList.remove('shielded'))
  window.addEventListener('pageshow', (event) => event.persisted && onVisible())
  setInterval(check, TICK_MS)
  // Sheets are hidden while locked (styles.css), and come back as they were
  watch(() => lock.locked, (on) => document.documentElement.classList.toggle('app-locked', on), { immediate: true })
  watch(
    () => settings.lock.enabled && settings.lock.credentialId,
    (on) => {
      if (!on) {
        forget()
        lock.locked = false
        document.documentElement.classList.remove('shielded')
      }
    },
  )
}

// ------------------------------------------------------------ passkey

const toBase64Url = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
const fromBase64Url = (text) => Uint8Array.from(atob(text.replace(/-/g, '+').replace(/_/g, '/')), (char) => char.charCodeAt(0))
const random = (length) => crypto.getRandomValues(new Uint8Array(length))
// authenticatorData: 32 bytes of RP id hash, then a flags byte; 0x04 is "user verified" (Face ID, Touch ID or the passcode)
const verified = (authenticatorData) => Boolean(authenticatorData && new Uint8Array(authenticatorData)[32] & 0x04)

/** Passkeys need a secure context (HTTPS, or localhost) and a browser that has them. */
export const passkeySupported = () => window.isSecureContext && typeof PublicKeyCredential !== 'undefined' && Boolean(navigator.credentials)

/** Whether this device has a Face ID, Touch ID or screen-lock passkey to offer. */
export async function passkeyAvailable() {
  if (!passkeySupported()) return false
  try {
    return await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()
  } catch {
    return false
  }
}

export function passkeyError(error) {
  if (error?.name === 'NotAllowedError' || error?.name === 'AbortError') return 'Not confirmed. Try again.'
  if (error?.name === 'InvalidStateError') return 'This device already has a passkey for Pocket. Remove it in Passwords and try again.'
  return error?.message || 'The passkey could not be checked.'
}

async function busy(work) {
  if (lock.busy) return false
  lock.busy = true
  lock.error = ''
  try {
    return await work()
  } finally {
    lock.busy = false
  }
}

// Asks the device to verify the user with the passkey made here. Only the owner of that passkey can answer.
async function assertPasskey() {
  const credential = await navigator.credentials.get({
    publicKey: {
      challenge: random(32),
      allowCredentials: [{ type: 'public-key', id: fromBase64Url(settings.lock.credentialId) }],
      userVerification: 'required',
      timeout: 60000,
    },
  })
  if (!credential || !verified(credential.response.authenticatorData)) throw new Error('The device did not verify you.')
}

/** The lock screen's button. Resolves true when the app is open. */
export function unlock({ quiet = false } = {}) {
  return busy(async () => {
    try {
      await assertPasskey()
      opened()
      return true
    } catch (error) {
      // An automatic try that the browser refused or the user cancelled says nothing: the Unlock button is there
      if (!(quiet && (error?.name === 'NotAllowedError' || error?.name === 'AbortError'))) lock.error = passkeyError(error)
      return false
    }
  })
}

/** For turning the lock off: the passkey must confirm it first. Throws when it does not. */
export function confirmPasskey() {
  return busy(async () => {
    await assertPasskey()
    return true
  })
}

/** Makes this device's passkey and turns the lock on. Throws when the device or the user refuses. */
export function enroll() {
  return busy(async () => {
    const credential = await navigator.credentials.create({
      publicKey: {
        rp: { name: 'Firefly Pocket' },
        user: { id: random(16), name: 'pocket', displayName: 'Firefly Pocket' },
        challenge: random(32),
        pubKeyCredParams: [
          { type: 'public-key', alg: -7 },
          { type: 'public-key', alg: -257 },
        ],
        authenticatorSelection: { authenticatorAttachment: 'platform', userVerification: 'required', residentKey: 'preferred' },
        attestation: 'none',
        timeout: 60000,
      },
    })
    if (!credential) throw new Error('No passkey was made.')
    // getAuthenticatorData() is newer than create(); without it the browser's own "required" is what we have
    const data = credential.response.getAuthenticatorData?.()
    if (data && !verified(data)) throw new Error('The device did not verify you.')
    settings.lock.credentialId = toBase64Url(credential.rawId)
    settings.lock.enabled = true
    opened()
    return true
  })
}

/** Turns the lock off and forgets the passkey's id (the passkey itself stays in the device's Passwords until removed there). */
export function disable() {
  settings.lock.enabled = false
  settings.lock.credentialId = ''
}
