import { get, post, del } from './api.js'

export async function registerServiceWorker() {
  if (!('serviceWorker' in navigator) || import.meta.env.DEV) return null
  try {
    return await navigator.serviceWorker.register('/sw.js', { type: 'module' })
  } catch {
    return null
  }
}

export const pushSupported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

// On iPhone, Web Push only works in the Home Screen app (iOS 16.4+)
export const isStandalone = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true

const toKey = (base64) => {
  const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0))
}

export async function pushStatus() {
  const { data } = await get('push/public-key', undefined, { quiet: true })
  const registration = pushSupported() ? await navigator.serviceWorker.getRegistration() : null
  const subscription = registration ? await registration.pushManager.getSubscription() : null
  return { configured: data.configured, publicKey: data.public_key, subscribed: Boolean(subscription), permission: pushSupported() ? Notification.permission : 'unsupported' }
}

export async function subscribePush(publicKey) {
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') throw new Error('Notifications are not allowed for Pocket')
  const registration = (await navigator.serviceWorker.getRegistration()) ?? (await registerServiceWorker())
  if (!registration) throw new Error('The service worker is not running (it only runs in a production build)')
  const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: toKey(publicKey) })
  await post('push/subscriptions', subscription.toJSON())
}

export async function unsubscribePush() {
  const registration = await navigator.serviceWorker.getRegistration()
  const subscription = await registration?.pushManager.getSubscription()
  if (subscription) {
    await del('push/subscriptions', { body: { endpoint: subscription.endpoint } }).catch(() => null)
    await subscription.unsubscribe()
  }
}
