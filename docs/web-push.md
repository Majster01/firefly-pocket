# Web Push and notification settings

Pocket receives notifications from the FireflyX importer, suggester and deploy scripts at `POST /api/internal/notify`, and shows them as native notifications in the installed app. The reverse proxy must not expose `/api/internal/*`.

## Contract

Header `Authorization: Bearer <NOTIFY_API_TOKEN>` (32+ characters). JSON body:

| Field | |
| --- | --- |
| `title` | required, at most 100 characters |
| `body` | required, at most 300 |
| `path` | optional, the app page a tap opens (default `/`) |
| `badge` | accepted and ignored: the app badge is the user's unread notifications, which Pocket works out itself (see below). Older senders may keep sending it |
| `tag` | optional, at most 64; a new notification with the same tag replaces the previous one |
| `event` | optional, the kind of message: `import.success`, `import.failure`, `suggestions.digest`, `upgrade.success`. Decides which devices get it and is not sent to the device. **No event is an alert** (backup, consent or deploy failure): every device gets it |
| `count` | optional integer, new transactions of an `import.success` |

Answers: `200 {"sent":n,"failed":n,"removed":n,"skipped":n}` (`skipped`: devices that turned this event off), `401` bad token, `422` invalid body, `503` push not configured (`{"message":…,"stored":id}`: the notification is kept in the center all the same).

## The notifications center

Every valid notification is stored (tables `inbox_notifications`, `inbox_notification_reads`) before it is pushed, whatever a device's settings are: they only decide who is interrupted. A failing store is logged and never keeps the push from going out.

- **Replacing:** a notification with the same `tag` replaces the stored one (a new row, unread again), unless title and body are the same: then the row and who read it stay.
- **Unread:** everything except `import.success` (listed, never counted). Read state is per user (`POST /api/notifications/{id}/read`, `POST /api/notifications/read-all` with `up_to` = the newest id shown).
- **Badge:** each push carries `badge` = that user's unread count and `id` = the stored notification. A tap on the notification opens its `path` with `?n=<id>`, which the app turns into "read". The app also sets or clears the icon badge itself when it opens and whenever something is read. A device that skipped a push gets no new number until the app opens: iOS cannot update a badge without a visible notification.
- **Retention:** older than 90 days, or beyond the newest 500, is deleted whenever a notification is stored.
- **API** (signed in): `GET /api/notifications?before=<id>&limit=<1-50>` → `{data, next, unread}`, `GET /api/notifications/unread-count` → `{count}`. These are never kept by the response cache and do not start a new data version.

## Settings per device

Settings → Notifications, once the device has switched push on. Stored on the device's subscription (`push_subscriptions.preferences`), defaults for a device that never changed them:

| Setting | Default |
| --- | --- |
| Import succeeded | on |
| …only when something new came in (`count` 0 is skipped) | on |
| Import problems | on |
| Category suggestions waiting: Off / Daily / Weekly + weekday | Weekly, Monday |
| Upgrades | on |

The suggestions digest is sent by FireflyX every day; each device receives it according to its own frequency. The weekday is read in `NOTIFY_TIMEZONE` (default: `TZ`, else UTC). `GET/PUT /api/push/preferences` (signed in, `endpoint` of the subscription) read and write them.

## Local development

```sh
dev/scripts/bootstrap.sh          # also runs dev/scripts/push-keys.sh: VAPID keypair + NOTIFY_API_TOKEN into server/.env
npm --prefix web run build && npm --prefix web run preview   # the service worker does not run under `vite dev`
dev/scripts/notify-test.sh        # one of each notification; try the settings in between
```

Push needs a secure context: `localhost` works, `http://<LAN ip>` on an iPhone does not (use an HTTPS tunnel). On iPhone it also needs the app on the Home Screen (iOS 16.4+). Rotating the VAPID keys invalidates every subscription: switch notifications off and on again.
