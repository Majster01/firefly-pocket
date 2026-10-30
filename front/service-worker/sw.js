import { clientsClaim } from 'workbox-core'
import { cleanupOutdatedCaches, createHandlerBoundToURL, getCacheKeyForURL, precacheAndRoute } from 'workbox-precaching'
import { NavigationRoute, registerRoute } from 'workbox-routing'
import { StaleWhileRevalidate } from 'workbox-strategies'
import { CacheableResponsePlugin } from 'workbox-cacheable-response'
import { ExpirationPlugin } from 'workbox-expiration'
import { buildNotification, getBadgeCount, getTargetUrl, parsePayload } from './push.mjs'

// registerType: 'autoUpdate'
self.skipWaiting()
clientsClaim()

precacheAndRoute(self.__WB_MANIFEST)
cleanupOutdatedCaches()
// The app shell '/' is only precached when the build writes it as a file. Without the guard the missing entry throws and the worker never starts
if (getCacheKeyForURL('/')) registerRoute(new NavigationRoute(createHandlerBoundToURL('/')))

registerRoute(
  // Admin custom icons. The backend may be on another origin => also cache opaque responses (status 0)
  ({ url }) => url.pathname.includes('/api/custom-icons/'),
  new StaleWhileRevalidate({
    cacheName: 'custom-icons',
    plugins: [new CacheableResponsePlugin({ statuses: [0, 200] }), new ExpirationPlugin({ maxEntries: 500 })],
  }),
)

// Browsers without the badge API (or without the permission for it) just do not show the count
const updateBadge = async (count) => {
  try {
    await (count > 0 ? self.navigator.setAppBadge(count) : self.navigator.clearAppBadge())
  } catch {
    // not supported
  }
}

// The importer/suggester push at night, while the app is closed
self.addEventListener('push', (event) => {
  const payload = parsePayload(event.data?.text())
  const { title, options } = buildNotification(payload)
  const badge = getBadgeCount(payload)

  event.waitUntil(Promise.all([self.registration.showNotification(title, options), badge === null ? null : updateBadge(badge)]))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = getTargetUrl(event.notification.data?.path, self.location.origin)

  event.waitUntil(
    (async () => {
      const [client] = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      if (client) {
        try {
          await client.focus()
          await client.navigate(url)
          return
        } catch {
          // The window cannot be navigated (e.g. on iOS): open it again
        }
      }
      await self.clients.openWindow(url)
    })(),
  )
})
