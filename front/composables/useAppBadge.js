import { computed, watch } from 'vue'
import { useEventListener } from '@vueuse/core'

/**
 * Shows on the app icon how many things wait for attention (rule suggestions and bank connections), where the browser has the Badging API.
 * The count is refreshed whenever the app comes back to the foreground.
 */
export function useAppBadge() {
  if (!('setAppBadge' in navigator)) return { refresh: () => {} }

  const appStore = useAppStore()
  const ruleSuggestionStore = useRuleSuggestionStore()
  const bankConnectionStore = useBankConnectionStore()

  const count = computed(() => ruleSuggestionStore.ruleSuggestionList.length + bankConnectionStore.attentionCount)

  const applyBadge = async () => {
    try {
      await (count.value > 0 ? navigator.setAppBadge(count.value) : navigator.clearAppBadge())
    } catch {
      // No permission for it (iOS needs the notification permission)
    }
  }

  const refresh = async () => {
    if (!appStore.hasAuthToken) return
    await Promise.all([ruleSuggestionStore.fetchRuleSuggestions(), bankConnectionStore.fetchBankConnections({ showLoading: false, showErrorToast: false }).catch(() => {})])
  }

  watch(count, applyBadge, { immediate: true })
  useEventListener(document, 'visibilitychange', () => document.visibilityState === 'visible' && refresh())

  return { refresh }
}
