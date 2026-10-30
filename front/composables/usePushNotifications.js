import { ref } from 'vue'
import { get } from 'lodash-es'
import PushRepository from '~/repository/PushRepository'
import ResponseUtils from '~/utils/ResponseUtils'
import UIUtils from '~/utils/UIUtils'

// The VAPID public key comes as URL safe base64, PushManager wants the bytes
const urlBase64ToUint8Array = (value) => {
  const base64 = (value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0))
}

const isIos = () => /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
const isInstalled = () => window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true
// iOS has no PushManager until the app is on the Home Screen (16.4+); never touch the API where it is missing
const canPush = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

/**
 * state: loading | unavailable (server has no VAPID keys) | not_installed (iOS, not on the Home Screen) | unsupported | denied | off | on
 */
export function usePushNotifications() {
  const { t } = useI18n()
  const state = ref('loading')
  const isBusy = ref(false)
  let publicKey = null

  async function getRegistration() {
    return canPush() ? await navigator.serviceWorker.getRegistration() : null
  }

  async function refresh() {
    const registration = await getRegistration()
    if (!registration) {
      state.value = isIos() && !isInstalled() ? 'not_installed' : 'unsupported'
      return
    }
    const config = await new PushRepository().getPublicKey()
    publicKey = config.public_key
    if (!config.configured) {
      state.value = 'unavailable'
    } else if (Notification.permission === 'denied') {
      state.value = 'denied'
    } else {
      state.value = Notification.permission === 'granted' && (await registration.pushManager.getSubscription()) ? 'on' : 'off'
    }
  }

  // Must run straight from the tap: iOS only shows the permission prompt for a user gesture
  async function enable() {
    isBusy.value = true
    try {
      if ((await Notification.requestPermission()) === 'granted') {
        const { pushManager } = await navigator.serviceWorker.ready
        const subscription = (await pushManager.getSubscription()) ?? (await pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(publicKey) }))
        const response = await new PushRepository().subscribe(subscription.toJSON())
        if (!ResponseUtils.isSuccess(response)) await subscription.unsubscribe()
      }
    } catch (error) {
      // The browser refused (e.g. no connection to its push service)
      UIUtils.showToastError(`${t('settings.notifications.error')} ${get(error, 'message', '')}`, 4000)
    }
    await refresh()
    isBusy.value = false
  }

  async function disable() {
    isBusy.value = true
    const subscription = await (await getRegistration())?.pushManager.getSubscription()
    if (subscription) {
      await new PushRepository().unsubscribe(subscription.endpoint)
      await subscription.unsubscribe()
    }
    await refresh()
    isBusy.value = false
  }

  return { state, isBusy, refresh, enable, disable }
}
