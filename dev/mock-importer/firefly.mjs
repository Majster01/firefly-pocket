// A small Firefly III API v1 client, shared by the seed script and the mock importer.

export class Firefly {
  constructor(url, token) {
    this.url = url.replace(/\/$/, '')
    this.token = token
  }

  async request(method, path, body, query) {
    const url = new URL(`${this.url}/api/v1/${path}`)
    for (const [key, value] of Object.entries(query ?? {})) {
      url.searchParams.set(key, value)
    }
    const response = await fetch(url, {
      method,
      headers: {
        Authorization: `Bearer ${this.token}`,
        Accept: 'application/vnd.api+json',
        'Content-Type': 'application/json',
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    })
    const text = await response.text()
    if (!response.ok) {
      const error = new Error(`Firefly ${method} ${path} answered ${response.status}: ${text.slice(0, 500)}`)
      error.status = response.status
      throw error
    }
    return text ? JSON.parse(text) : null
  }

  get(path, query) {
    return this.request('GET', path, undefined, query)
  }

  post(path, body) {
    return this.request('POST', path, body)
  }

  put(path, body) {
    return this.request('PUT', path, body)
  }

  delete(path) {
    return this.request('DELETE', path)
  }

  async all(path, query = {}) {
    const list = []
    let page = 1
    let totalPages = 1
    do {
      const body = await this.get(path, { ...query, limit: 100, page })
      list.push(...(body.data ?? []))
      totalPages = body.meta?.pagination?.total_pages ?? 1
      page++
    } while (page <= totalPages)
    return list
  }

  async ruleGroup(title, { create = false, active = true, description = '' } = {}) {
    const groups = await this.all('rule-groups')
    const group = groups.find((item) => item.attributes.title === title)
    if (group || !create) {
      return group ?? null
    }
    return (await this.post('rule-groups', { title, description, active })).data
  }

  async storeTransaction(split, { applyRules = false } = {}) {
    const body = await this.post('transactions', {
      error_if_duplicate_hash: false,
      apply_rules: applyRules,
      fire_webhooks: false,
      transactions: [split],
    })
    return body.data
  }
}
