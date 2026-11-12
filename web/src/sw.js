/* global self, clients */
import { precacheAndRoute, createHandlerBoundToURL } from 'workbox-precaching'
import { NavigationRoute, registerRoute } from 'workbox-routing'
import { applyAppBadge, tappedUrl } from './lib/notifications.js'

// The app shell works offline; /api never comes from the cache
precacheAndRoute(self.__WB_MANIFEST)

// The app always opens with its amounts hidden: the shell is served with <html data-amounts-pending class="amounts-hidden">,
// and the app lifts it (lib/lock.js) once it has decided the lock is open or not needed. Nothing readable can flash
// when the Home Screen app comes back, whatever the cache has to show. Without this worker the page just opens as before.
const shell = createHandlerBoundToURL('/index.html')
async function hiddenShell(options) {
  const response = await shell(options)
  if (!response?.ok || !response.headers.get('content-type')?.includes('text/html')) return response
  const html = (await response.text()).replace(/<html(?=[\s>])/i, '<html data-amounts-pending class="amounts-hidden"')
  const headers = new Headers(response.headers)
  headers.delete('content-length')
  return new Response(html, { status: response.status, statusText: response.statusText, headers })
}
// Every navigation gets the shell, except the paths that are not the app's: its API, and two that Caddy routes elsewhere
// on the app's host (firefly-pocket-stack, Hostnames and routing). The bank sends the browser back to /eb-callback for
// the importer, and /legal/* are Caddy's static pages. With the app installed, the shell would answer both, and a bank
// link would never finish. Workbox matches these against the path and the query.
const notTheApp = [/^\/api\//, /^\/eb-callback(?:[/?]|$)/, /^\/legal(?:[/?]|$)/]
registerRoute(new NavigationRoute(hiddenShell, { denylist: notTheApp }))

self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(clients.claim()))

// Payload from Pocket's PushNotificationService: {title, body, path, id, badge, tag}. id: the notification in the center;
// badge: how many of them this user has unread, which is the number on the app icon
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
      data: { path: payload.path ?? '/', id: payload.id },
    }),
  ]
  if (typeof payload.badge === 'number') tasks.push(applyAppBadge(payload.badge, self.navigator))
  event.waitUntil(Promise.all(tasks))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const path = tappedUrl(event.notification.data?.path ?? '/', event.notification.data?.id)
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
