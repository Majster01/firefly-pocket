// Sign in with Firefly III (firefly-pocket-stack, Setup UI step 11): an authorization code with PKCE for the public
// client the stack registers for Pocket. The browser signs in on Firefly III's own login page and comes back to
// /oauth/callback; Pocket's server swaps the code for a token (SignInController), which is then kept like a pasted one.
//
// The verifier and the state wait in localStorage, not sessionStorage, because a Home Screen app may come back from
// Firefly III in a fresh window; they are removed on the way back and are good for ten minutes at most.

const KEY = 'pocket.signin'
const LIFETIME = 10 * 60 * 1000

const base64url = (bytes) => btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
const random = (size) => base64url(crypto.getRandomValues(new Uint8Array(size)))

/** What this server offers: null when it does not offer signing in with Firefly III. */
export async function signInOffer() {
  try {
    const response = await fetch(new URL('/api/signin', location.origin), { headers: { Accept: 'application/json' } })
    const json = response.ok ? await response.json() : {}
    return json.available ? json : null
  } catch {
    return null
  }
}

/** Off to Firefly III's login. next: where to go once signed in. */
export async function startSignIn(offer, next = '') {
  const verifier = random(48)
  const challenge = base64url(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier))))
  const state = random(24)
  localStorage.setItem(KEY, JSON.stringify({ verifier, state, next, until: Date.now() + LIFETIME }))
  const url = new URL(offer.authorize_url)
  url.search = new URLSearchParams({
    client_id: offer.client_id,
    redirect_uri: offer.redirect_uri,
    response_type: 'code',
    scope: '',
    state,
    code_challenge: challenge,
    code_challenge_method: 'S256',
  })
  location.assign(url)
}

/** Back from Firefly III with the query it sent: the token, and where to go. Throws what to tell the user. */
export async function finishSignIn(query) {
  let saved = null
  try {
    saved = JSON.parse(localStorage.getItem(KEY) ?? 'null')
  } catch {
    saved = null
  }
  localStorage.removeItem(KEY)
  if (typeof query.error === 'string') {
    throw new Error(query.error === 'access_denied' ? 'You did not let Firefly Pocket in.' : `Firefly III said: ${query.error_description || query.error}`)
  }
  if (!saved || saved.until < Date.now() || typeof query.state !== 'string' || query.state !== saved.state || typeof query.code !== 'string') {
    throw new Error('This sign-in did not start on this device, or took longer than ten minutes. Sign in again.')
  }
  const response = await fetch(new URL('/api/signin/token', location.origin), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ code: query.code, code_verifier: saved.verifier }),
  })
  const json = await response.json().catch(() => ({}))
  if (!response.ok || typeof json.token !== 'string' || !json.token) {
    throw new Error(json.message || `Firefly III did not take that sign-in (${response.status}). Sign in again.`)
  }
  return { token: json.token, next: typeof saved.next === 'string' ? saved.next : '' }
}
