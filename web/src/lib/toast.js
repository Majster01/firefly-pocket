import { reactive } from 'vue'

export const toasts = reactive([])
let next = 1

export function toast(message, { type = 'info', timeout = 2600, action } = {}) {
  const id = next++
  toasts.push({ id, message, type, action })
  setTimeout(() => dismiss(id), timeout)
  return id
}
export const toastError = (message) => toast(message, { type: 'error', timeout: 5000 })

export function dismiss(id) {
  const index = toasts.findIndex((item) => item.id === id)
  if (index >= 0) toasts.splice(index, 1)
}
