import { settings } from './settings.js'
import { toastError } from './toast.js'

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
 */
export async function api(method, path, { query, body, quiet = false, token } = {}) {
  const url = new URL(`/api/${path.replace(/^\//, '')}`, location.origin)
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, value)
  }
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
    if (response.status === 401 && !token) onUnauthorized()
    else if (!quiet) toastError(message)
    throw new ApiError(response.status, message, json)
  }
  return json
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
