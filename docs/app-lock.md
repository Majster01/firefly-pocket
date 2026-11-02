# Require passkey (app lock)

Settings → Security → **Require passkey**. The app asks for Face ID, Touch ID or the device's own screen lock (a WebAuthn platform passkey, `userVerification: required`) before it shows anything. It is for a phone or a Mac that is handed to someone while unlocked.

- **Per device.** Each device makes its own passkey and keeps its own timeout, in `localStorage` with the other settings (`settings.lock`). Nothing goes to Pocket's server: there are no accounts there, so there is nothing to check against, and no migration or deploy order to think of.
- **Lock after** counts from the last interaction (touch, key, scroll): Immediately, 1, 5, 15 minutes, 1 or 4 hours. The time of the last interaction is kept in `localStorage` (`pocket.lock.active`) every few seconds and when the app leaves the screen, so the same rule decides a relaunch and a return from the background. "Immediately" locks as soon as the app leaves the screen; an open app untouched for a minute locks in any case. A touch after the time has run out locks; it never extends it.
- **Locked** means a lock screen instead of the app. The app itself stays mounted but hidden (`display: none`), so a half-written transaction and the scroll position survive.
- **App switcher.** With the lock on, the page is hidden (`html.shielded`) the moment the app leaves the screen, so iOS's picture of it shows the background, not balances.
- **Unlock** needs a tap: browsers open the passkey prompt only from a gesture.
- **Turning it off** needs the passkey too.
- **Lost passkey** (new phone, iCloud Keychain reset): remove the app from the Home Screen, or clear the site's data, and open it again. That clears the Firefly token too, so paste it again.

What it does not do:
- It is a lock on what the app shows. The Firefly token and the cache stay in the browser (`localStorage`, IndexedDB), so it does not stop someone with the browser's developer tools. Encrypting the token with the WebAuthn PRF extension would, but PRF support is uneven across Safari and Chrome.
- Face ID or Touch ID falls back to the device passcode or password.
- Push notifications show their text on the lock screen regardless.
