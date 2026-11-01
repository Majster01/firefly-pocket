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
    const reading = (isBatched(url) ? viaBatch(url, quiet) : send('GET', url, { quiet }))
      .then(({ json, size }) => {
        keep(key, version, json, size)
        return json
      })
      .finally(() => inflight.delete(shareKey))
    inflight.set(shareKey, reading)
  }
  return inflight.get(shareKey)
}

// ------------------------------------------------------------------ batch

// Reads asked within BATCH_WAIT_MS of each other (a page's tiles, all at once) go to Pocket as one POST /api/batch,
// which asks Firefly for them side by side. Reads Pocket works out itself one call after another are left out: the
// batch answers when its slowest read is done.
const BATCH_WAIT_MS = 12
const BATCH_MAX = 40
const NOT_BATCHED = ['rule-suggestions', 'search/transactions/total']
const batchQueue = []
let batchTimer = null

const apiPath = (url) => url.pathname.replace(/^\/api\//, '') + url.search
const isBatched = (url) => !NOT_BATCHED.some((prefix) => apiPath(url).split('?')[0] === prefix)

function viaBatch(url, quiet) {
  return new Promise((resolve, reject) => {
    batchQueue.push({ url, quiet, resolve, reject })
    if (batchQueue.length >= BATCH_MAX) flushBatch()
    else batchTimer ??= setTimeout(flushBatch, BATCH_WAIT_MS)
  })
}

async function flushBatch() {
  clearTimeout(batchTimer)
  batchTimer = null
  const items = batchQueue.splice(0)
  if (!items.length) return
  let answers = {}
  if (items.length > 1) {
    try {
      // The page is what the server remembers Home by, to fetch it again after the next import
      const batchUrl = new URL('/api/batch', location.origin)
      answers = (await send('POST', batchUrl, { body: { paths: items.map((item) => apiPath(item.url)), page: location.pathname }, quiet: true })).json?.data ?? {}
    } catch {
      // An older Pocket, or the batch failed: each read asks on its own
    }
  }
  for (const item of items) {
    const answer = answers[apiPath(item.url)]
    // Anything but a 200 is asked again on its own, so it fails (and is reported) the usual way
    if (answer?.status === 200) item.resolve({ json: answer.body, size: answer.size })
    else send('GET', item.url, { quiet: item.quiet }).then(item.resolve, item.reject)
  }
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
