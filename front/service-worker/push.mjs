// Pure helpers of the service worker's push handling (kept apart from sw.js so node can test them)

const DEFAULT_TITLE = 'Pico'
const ICON = '/android-chrome-192x192.png'

// The payload is JSON ({ title, body, path, tag, badge }); anything else is shown as plain text
export function parsePayload(text) {
  try {
    const payload = JSON.parse(text)
    if (payload && typeof payload === 'object') return payload
  } catch {
    // not JSON
  }
  return { body: text || '' }
}

// iOS requires every push to show a notification, so there are always a title and a body
export function buildNotification(payload) {
  return {
    title: payload.title || DEFAULT_TITLE,
    options: {
      body: payload.body || '',
      icon: ICON,
      ...(payload.tag && { tag: payload.tag }),
      data: { path: payload.path || '/' },
    },
  }
}

// The app badge count to set, or null when the push says nothing about it. 0 clears it
export function getBadgeCount(payload) {
  const badge = Number(payload.badge)
  return payload.badge != null && Number.isInteger(badge) && badge >= 0 ? badge : null
}

// Where a click on the notification goes. Only a path of this app: anything else opens the start page
export function getTargetUrl(path, origin) {
  try {
    const url = new URL(path || '/', origin)
    return url.origin === origin ? url.href : `${origin}/`
  } catch {
    return `${origin}/`
  }
}
