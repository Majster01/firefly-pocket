# Web Push and app badge

Pico can show notifications and an app icon badge while the app is closed, e.g. when the nightly import finishes or a bank consent is about to expire. The sender is the FireflyX importer/suggester, which calls Pico's internal notify endpoint; Pico stores the devices that opted in and delivers the notification through the browsers' push services.

Push is off until the VAPID keys are set. Without them **Settings → Notifications** says push is not set up on this server and offers no toggle.

## Setup

1. Generate a VAPID key pair once and keep it (new keys invalidate every existing subscription):

   ```bash
   npx web-push generate-vapid-keys
   ```

   Without Node, with OpenSSL (prints the public key, then the private key, both URL-safe base64):

   ```bash
   openssl ecparam -name prime256v1 -genkey -noout -out vapid.pem
   openssl ec -in vapid.pem -pubout -outform DER 2>/dev/null | tail -c 65 | base64 | tr '+/' '-_' | tr -d '=\n'; echo
   openssl ec -in vapid.pem -outform DER 2>/dev/null | tail -c +8 | head -c 32 | base64 | tr '+/' '-_' | tr -d '=\n'; echo
   ```

2. Generate the shared token for the notify endpoint (32+ characters):

   ```bash
   openssl rand -hex 32
   ```

3. Set the environment variables of the Pico container (the importer/suggester gets the same `NOTIFY_API_TOKEN`):

   | Variable | |
   | --- | --- |
   | `VAPID_PUBLIC_KEY` | Public key from step 1. Served to the browser |
   | `VAPID_PRIVATE_KEY` | Private key from step 1. Keep secret |
   | `VAPID_SUBJECT` | `mailto:you@example.com` or an `https://` URL the push services can contact. Defaults to `APP_URL` |
   | `NOTIFY_API_TOKEN` | Bearer token of `POST /api/internal/notify`. The endpoint answers 503 while it is unset or shorter than 32 characters |

4. On each device open **Settings → Notifications** and switch **Enable notifications** on.

## Notify endpoint

`POST /api/internal/notify` is not behind the user login. It authenticates with `Authorization: Bearer <NOTIFY_API_TOKEN>` (compared in constant time) and sends to every stored subscription. Call it over the internal network and have the reverse proxy hide `/api/internal/*` from the public hostname.

| Field | |
| --- | --- |
| `title` | required, string, up to 100 characters |
| `body` | required, string, up to 300 characters |
| `path` | optional, the page opened on click: starts with `/`, not `//`. Default `/` |
| `badge` | optional, integer ≥ 0, the app icon badge count. `0` clears it |
| `tag` | optional, string up to 64 characters. A notification with the same tag replaces the previous one |

```bash
curl -X POST http://pico:80/api/internal/notify \
  -H "Authorization: Bearer $NOTIFY_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"Import finished","body":"12 new transactions","path":"/transactions/list","badge":3,"tag":"import"}'
```

| Status | Meaning |
| --- | --- |
| `200` | `{"sent":n,"failed":n,"removed":n}`: delivered / failed this time / subscriptions the push service reported gone (404/410), which were deleted. `sent`, `failed` and `removed` do not overlap. All zeros when nobody subscribed |
| `401` | Token missing or wrong |
| `422` | Invalid body |
| `503` | `NOTIFY_API_TOKEN` unset or shorter than 32 characters, or the VAPID keys are missing |

A failing delivery to one subscription never stops the others.

## App badge

Whenever the app opens or comes back to the foreground it sets the badge to the number of pending rule suggestions plus the bank connections that need attention (the same numbers as in Extras), and clears it at 0. Pushes that carry `badge` set it while the app is closed. Browsers without the Badging API are left alone.

## iOS and iPadOS

- Needs **iOS/iPadOS 16.4 or newer**.
- Pico must be **added to the Home Screen** (Share → Add to Home Screen) and opened from there. There is no push, and no `PushManager`, in a Safari tab.
- The permission can only be asked from a tap, which is why it is a toggle in the settings and not a popup at startup.
- The app badge needs the notification permission.
- iOS requires every push to show a notification, so each push does (even when it only updates the badge).
- Tapping a notification opens or focuses the installed app on the `path` of the push.

## Troubleshooting

- *"Push notifications are not set up on this server"*: the VAPID keys are not set in the container, or the container was not restarted.
- *Notifications blocked*: allow them for Pico in the browser's site settings (or iOS Settings → Notifications → Pico), then reload.
- Changing the VAPID keys makes every stored subscription invalid: switch notifications off and on again on each device.
