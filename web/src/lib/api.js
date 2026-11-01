import { settings } from './settings.js'
import { toastError } from './toast.js'
import { changed, isKept, keep, kept } from './cache.js'

export class ApiError extends Error {
  constructor(status, message, body) {
    super(message)
    this.status = status
    this.body = body
  }
}

let onUnauthorized = () => {}
export const setUnauthorizedHandler = (handler) => (onUnauthorized = handler)

/**
 * Calls Pocket's API (server/), which forwards the Firefly token to Firefly III or answers itself.
 * Firefly resources keep Firefly's JSON:API shape: {data: {id, type, attributes}}.
 *
 * Reads of Firefly data come from what this browser kept while Pocket's data version is the same (lib/cache.js);
 * fresh: true always asks. Anything saved makes the app ask Pocket for the version again.
 */
export async function api(method, path, { query, body, quiet = false, token, fresh = false } = {}) {
  const url = new URL(`/api/${path.replace(/^\//, '')}`, location.origin)
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, value)
  }
  if (method === 'GET' && !token && !fresh && isKept(path)) return keptRead(url, quiet)
  if (method === 'GET' || token) return (await send(method, url, { body, quiet, token })).json
  try {
    return (await send(method, url, { body, quiet, token })).json
  } finally {
    // Even a save that failed halfway may have changed something
    changed()
  }
}

// The same read asked by several tiles at once is one request
const inflight = new Map()

async function keptRead(url, quiet) {
  const key = url.pathname + url.search
  const { version, data } = await kept(key)
  if (data !== undefined) return data
  const shareKey = `${version}|${key}`
  if (!inflight.has(shareKey)) {
    const reading = send('GET', url, { quiet })
      .then(({ json, size }) => {
        keep(key, version, json, size)
        return json
      })
      .finally(() => inflight.delete(shareKey))
    inflight.set(shareKey, reading)
  }
  return inflight.get(shareKey)
}

async function send(method, url, { body, quiet, token }) {
  let response
  try {
    response = await fetch(url, {
      method,
      headers: {
        Authorization: `Bearer ${token ?? settings.token}`,
        Accept: 'application/json',
        ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    })
  } catch {
    if (!quiet) toastError('Pocket is not reachable. Check your connection.')
    throw new ApiError(0, 'Network error')
  }
  const text = await response.text()
  let json = null
  try {
    json = text ? JSON.parse(text) : null
  } catch {
    json = null
  }
  if (!response.ok) {
    const message = errorMessage(json) ?? `Request failed (${response.status})`
    // Firefly answers 401 for an id that does not exist (deleted on another device, a stale link), not only for a bad
    // token: ask whether the token still works before signing anyone out
    if (response.status === 401 && !token) {
      if (await tokenStillWorks()) {
        const gone = 'This no longer exists. It may have been deleted elsewhere.'
        if (!quiet) toastError(gone)
        throw new ApiError(404, gone, json)
      }
      onUnauthorized()
    } else if (!quiet) toastError(message)
    throw new ApiError(response.status, message, json)
  }
  return { json, size: text.length }
}

async function tokenStillWorks() {
  try {
    const response = await fetch(new URL('/api/user', location.origin), { headers: { Authorization: `Bearer ${settings.token}`, Accept: 'application/json' } })
    const json = response.ok ? await response.json().catch(() => null) : null
    return Boolean(json?.data)
  } catch {
    return false
  }
}

function errorMessage(json) {
  if (!json) return null
  if (json.errors && typeof json.errors === 'object') {
    const first = Object.values(json.errors).flat()[0]
    if (first) return String(first)
  }
  return json.message ?? json.error ?? null
}

export const get = (path, query, options) => api('GET', path, { query, ...options })
export const post = (path, body, options) => api('POST', path, { body, ...options })
export const put = (path, body, options) => api('PUT', path, { body, ...options })
export const del = (path, options) => api('DELETE', path, options)

// Every page of a Firefly list
export async function getAll(path, query = {}) {
  const items = []
  let page = 1
  let totalPages = 1
  do {
    const json = await get(path, { limit: 100, ...query, page })
    items.push(...(json?.data ?? []))
    totalPages = json?.meta?.pagination?.total_pages ?? 1
    page++
  } while (page <= totalPages && page <= 50)
  return items
}
