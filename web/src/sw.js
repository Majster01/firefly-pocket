/* global self, clients */
import { precacheAndRoute, createHandlerBoundToURL } from 'workbox-precaching'
import { NavigationRoute, registerRoute } from 'workbox-routing'

// The app shell works offline; /api never comes from the cache
precacheAndRoute(self.__WB_MANIFEST)
registerRoute(new NavigationRoute(createHandlerBoundToURL('/index.html'), { denylist: [/^\/api\//] }))

self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(clients.claim()))

// Payload from Pocket's PushNotificationService: {title, body, path, badge, tag}
self.addEventListener('push', (event) => {
  let payload = {}
  try {
    payload = event.data?.json() ?? {}
  } catch {
    payload = { title: 'Firefly Pocket', body: event.data?.text() ?? '' }
  }
  const tasks = [
    self.registration.showNotification(payload.title ?? 'Firefly Pocket', {
      body: payload.body ?? '',
      tag: payload.tag,
      icon: '/icon-192.png',
      badge: '/icon-192.png',
      data: { path: payload.path ?? '/' },
    }),
  ]
  if (typeof payload.badge === 'number' && self.navigator.setAppBadge) {
    tasks.push(payload.badge > 0 ? self.navigator.setAppBadge(payload.badge) : self.navigator.clearAppBadge())
  }
  event.waitUntil(Promise.all(tasks))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const path = event.notification.data?.path ?? '/'
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      const client = list.find((item) => 'focus' in item)
      if (client) {
        client.navigate(path)
        return client.focus()
      }
      return clients.openWindow(path)
    }),
  )
})
