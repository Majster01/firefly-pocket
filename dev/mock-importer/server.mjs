// Mock of the FireflyX importer fork's API (/api/fireflyx/v1) for local development, with a fake bank consent page
// and the mock category suggester. Dummy data only: no bank, no LLM, no real Firefly.
//
// Contract (what Pocket's server/app/Services/BankConnectionService.php calls):
//   GET    banks?country=SI                  {data: [{name, country, logo, psu_types}]}
//   GET    connections                       {data: [connection]}
//   GET    connections/{name}                {data: connection}
//   POST   connections                       {country, bank, psu_type, return_path} -> {data: {name, auth_url}}
//   POST   connections/{name}/reauth         {return_path} -> {data: {auth_url}}
//   PUT    connections/{name}/accounts       {accounts: [{id, firefly_account_id}], import_since} -> {data: connection}
//   POST   connections/{name}/imports        {date_from, date_to} -> 202 {data: manual_import}
//   DELETE connections/{name}                204
//   GET    status?days=30                    {data: status}
//   GET    errors?date=2026-11-09&connection=nlb-d-d-si   {data: {date, count, connections, items}}
// Errors are {error: "..."} with a 4xx status.

import http from 'node:http'
import fs from 'node:fs'
import path from 'node:path'
import { Firefly } from './firefly.mjs'
import { suggest } from './suggester.mjs'
import { EATING_OUT, GROCERIES, OCCASIONAL, TRANSPORT, UNSORTED, random } from './merchants.mjs'

const PORT = Number(process.env.PORT ?? 8091)
const DATA_FILE = process.env.DATA_FILE ?? path.join(process.cwd(), 'state.json')
const API_TOKEN = process.env.FIREFLYX_API_TOKEN ?? 'dev-importer-token-dev-importer-token'
const PICO_URL = (process.env.PICO_URL ?? 'http://localhost:5173').replace(/\/$/, '')
const PUBLIC_URL = (process.env.PUBLIC_URL ?? `http://localhost:${PORT}`).replace(/\/$/, '')
const firefly = process.env.FIREFLY_TOKEN ? new Firefly(process.env.FIREFLY_URL ?? 'http://localhost:8081', process.env.FIREFLY_TOKEN) : null

const PREFIX = '/api/fireflyx/v1'
const CONSENT_DAYS = 180
const EXPIRING_DAYS = 7
const MAX_HISTORY_DAYS = 90
const DAY = 86400000

const iso = (date) => new Date(date).toISOString().slice(0, 10)
const addDays = (date, days) => new Date(new Date(date).getTime() + days * DAY)
const now = () => new Date().toISOString()

// Errors by day, made up from how long ago the day was; yesterday and nine days ago are the failed nights of the status report, today has a manual import
const errorsOn = (date, only) => {
  const daysAgo = Math.round((Date.parse(iso(Date.now())) - Date.parse(date)) / DAY)
  const nlb = { connection: 'nlb-d-d-si', bank: 'NLB d.d.' }
  const revolut = { connection: 'revolut-lt', bank: 'Revolut' }
  const at = (time) => `${date}T${time}Z`
  const items = []
  if (daysAgo === 0) {
    items.push({ at: at('09:41:12'), source: 'manual', ...nlb, messages: [{ message: 'Enable Banking API error: 401 (mock)', count: 1 }], more: 0 })
  }
  if (daysAgo === 1) {
    items.push({ at: at('03:12:05'), source: 'nightly', ...revolut, messages: [{ message: 'The bank answered 429 Too Many Requests (mock)', count: 1 }], more: 0 })
    items.push({ at: at('03:12:01'), source: 'nightly', ...nlb, messages: [{ message: 'Could not create the transaction: the description is too long (mock) ' + 'x'.repeat(220), count: 2 }, { message: 'Source account not found: SI56…5678 (mock)', count: 1 }], more: 0 })
  }
  if (daysAgo === 9) {
    items.push({ at: at('03:12:40'), source: 'nightly', ...revolut, messages: Array.from({ length: 50 }, (_, index) => ({ message: `Row ${index + 1} was refused: amount is zero (mock)`, count: 1 })), more: 10 })
  }
  const connections = [...new Map(items.map((item) => [item.connection, { name: item.connection, bank: item.bank }])).values()].sort((a, b) => a.name.localeCompare(b.name))
  const shown = items.filter((item) => !only || item.connection === only)
  const count = shown.reduce((sum, item) => sum + item.more + item.messages.reduce((total, message) => total + message.count, 0), 0)
  return { date, count, connections, items: shown }
}

// ----------------------------------------------------------------------- banks

const BANKS = {
  SI: [
    { name: 'NLB d.d.', color: '#5b2c83', psu_types: ['personal', 'business'] },
    { name: 'Nova KBM', color: '#e2001a', psu_types: ['personal', 'business'] },
    { name: 'Intesa Sanpaolo Bank', color: '#006e3c', psu_types: ['personal'] },
    { name: 'Addiko Bank', color: '#003b5c', psu_types: ['personal'] },
    { name: 'Delavska hranilnica', color: '#d65a00', psu_types: ['personal'] },
    { name: 'Revolut', color: '#0666eb', psu_types: ['personal', 'business'] },
  ],
}
const banksFor = (country) =>
  (BANKS[country] ?? [
    { name: `Demo Bank ${country}`, color: '#4a6d8c', psu_types: ['personal'] },
    { name: 'Revolut', color: '#0666eb', psu_types: ['personal', 'business'] },
    { name: 'N26', color: '#36a18b', psu_types: ['personal'] },
  ]).map((bank) => ({ name: bank.name, country, logo: `${PUBLIC_URL}/logo/${slug(bank.name)}.svg?c=${encodeURIComponent(bank.color)}`, psu_types: bank.psu_types }))

const slug = (text) =>
  text
    .normalize('NFKD')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')

const logoSvg = (name, color) => {
  const initials = name
    .split('-')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('')
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="16" fill="${color}"/><text x="32" y="41" font-family="Helvetica,Arial,sans-serif" font-size="24" font-weight="700" fill="#fff" text-anchor="middle">${initials}</text></svg>`
}

// ----------------------------------------------------------------------- state

let state = load()

function load() {
  try {
    return JSON.parse(fs.readFileSync(DATA_FILE, 'utf8'))
  } catch {
    return initialState()
  }
}

function save() {
  fs.mkdirSync(path.dirname(DATA_FILE), { recursive: true })
  fs.writeFileSync(DATA_FILE, JSON.stringify(state, null, 2))
}

// Two connections with some history, so the pages have something to show on a fresh stack
function initialState() {
  const rng = random(7)
  const trackingSince = iso(addDays(Date.now(), -45))
  const nightly = []
  const events = []
  const runs = []
  for (let offset = 45; offset >= 1; offset--) {
    const date = iso(addDays(Date.now(), -offset))
    if (offset === 17 || offset === 31) {
      continue // missed nights
    }
    const failed = offset === 1 ? 2 : offset === 9 ? 1 : 0
    nightly.push({ date, connections: 2, created: Math.floor(rng.next() * 7), failed, warnings: offset % 11 === 0 ? 1 : 0 })
    if (failed) {
      events.push({ at: `${date}T03:12:40Z`, level: 'error', step: 'import', flow: 'nightly', reason: 'rate_limited', message: 'The bank answered 429 Too Many Requests', bank: 'Revolut', connection: 'revolut-lt' })
    }
    const suggestions = Math.floor(rng.next() * 3)
    runs.push({
      id: `run-history-${offset}`,
      started: `${date}T03:20:00Z`,
      finished: `${date}T03:21:10Z`,
      source: 'nightly',
      status: offset === 23 ? 'failed' : 'done',
      error: offset === 23 ? 'The model provider timed out (mock)' : null,
      error_details: [],
      calls: suggestions + Math.floor(rng.next() * 2),
      searches: suggestions,
      suggestions,
      low_confidence: suggestions > 1 ? 1 : 0,
      input_tokens: 1200 + Math.floor(rng.next() * 4000),
      output_tokens: 150 + Math.floor(rng.next() * 500),
      group_errors: 0,
      write_errors: 0,
      min_confidence: 0.6,
    })
  }
  events.push({ at: `${iso(addDays(Date.now(), -40))}T19:02:11Z`, level: 'info', step: 'consent', flow: 'create', reason: null, message: 'Consent granted for 1 account', bank: 'NLB d.d.', connection: 'nlb-d-d-si' })
  events.push({ at: `${iso(addDays(Date.now(), -3))}T03:12:02Z`, level: 'warning', step: 'consent', flow: 'nightly', reason: 'expiring', message: 'The consent ends in 5 days', bank: 'Revolut', connection: 'revolut-lt' })

  const lastNight = `${iso(addDays(Date.now(), -1))}T03:12:00Z`
  return {
    tracking_since: trackingSince,
    connections: {
      'nlb-d-d-si': {
        name: 'nlb-d-d-si',
        bank: { name: 'NLB d.d.', country: 'SI', logo: banksFor('SI')[0].logo },
        psu_type: 'personal',
        status: 'active',
        mapped: true,
        valid_until: addDays(Date.now(), 138).toISOString(),
        created_at: addDays(Date.now(), -42).toISOString(),
        accounts: [{ id: 'nlb-1', name: 'NLB Osebni račun', iban_masked: 'SI56…5678', currency: 'EUR', identification_hash: 'h-nlb-1', firefly_account_id: null, match_name: 'NLB Current' }],
        last_import: { at: lastNight, ok: true, summary: { messages: 3, first_error: null } },
        last_error: null,
        manual_import: null,
      },
      'revolut-lt': {
        name: 'revolut-lt',
        bank: { name: 'Revolut', country: 'LT', logo: banksFor('LT')[1].logo },
        psu_type: 'personal',
        status: 'active',
        mapped: true,
        valid_until: addDays(Date.now(), 5).toISOString(),
        created_at: addDays(Date.now(), -175).toISOString(),
        accounts: [
          { id: 'rev-1', name: 'EUR Main', iban_masked: 'LT32…8901', currency: 'EUR', identification_hash: 'h-rev-1', firefly_account_id: null, match_name: 'Revolut' },
          { id: 'rev-2', name: 'USD Pocket', iban_masked: 'LT11…4402', currency: 'USD', identification_hash: 'h-rev-2', firefly_account_id: null },
        ],
        last_import: { at: lastNight, ok: false, summary: { messages: 0, first_error: 'The bank answered 429 Too Many Requests (mock)' } },
        last_error: null,
        manual_import: null,
      },
    },
    pending: {},
    nightly,
    events,
    manual: { total: 0, done: 0, failed: 0 },
    suggester: { tracking_since: trackingSince, runs, memory: { rejected: [], created: [], resolved: 4 } },
  }
}

// The demo connections name Firefly accounts by name; the ids are known once Firefly answers
async function resolveAccountNames() {
  const wanted = Object.values(state.connections).flatMap((connection) => connection.accounts.filter((account) => account.match_name && account.firefly_account_id === null))
  if (!wanted.length || !firefly) {
    return
  }
  try {
    const assets = await firefly.all('accounts', { type: 'asset' })
    for (const account of wanted) {
      const match = assets.find((item) => item.attributes.name === account.match_name)
      if (match) {
        account.firefly_account_id = Number(match.id)
        delete account.match_name
      }
    }
    save()
  } catch {
    // Firefly not up yet, or not seeded: try again on the next request
  }
}

// ---------------------------------------------------------------- presentation

function statusOf(connection) {
  if (connection.status === 'pending' || !connection.mapped) {
    return connection.status === 'pending' ? 'pending' : 'needs_mapping'
  }
  const daysLeft = daysLeftOf(connection)
  if (daysLeft !== null && daysLeft < 0) return 'expired'
  if (connection.last_error) return 'error'
  if (daysLeft !== null && daysLeft <= EXPIRING_DAYS) return 'expiring'
  return 'active'
}

const daysLeftOf = (connection) => (connection.valid_until ? Math.floor((new Date(connection.valid_until) - Date.now()) / DAY) : null)

function present(connection) {
  const status = statusOf(connection)
  return {
    name: connection.name,
    bank: connection.bank,
    psu_type: connection.psu_type,
    status,
    expires_at: connection.valid_until,
    days_left: daysLeftOf(connection),
    created_at: connection.created_at,
    accounts: connection.accounts.map(({ match_name, ...account }) => account),
    import_since: connection.import_since ?? null,
    last_import: connection.last_import,
    last_error: connection.last_error,
    manual_import: connection.manual_import,
  }
}

async function statusReport(days) {
  const since = iso(addDays(Date.now(), -(days - 1)))
  const byDate = new Map(state.nightly.map((day) => [day.date, day]))
  const dayList = []
  for (let offset = 0; offset < days; offset++) {
    const date = iso(addDays(Date.now(), -offset))
    const day = byDate.get(date)
    dayList.push(day ? { ...day, ran: true } : { date, ran: false, connections: 0, created: 0, failed: 0, warnings: 0 })
  }
  const inWindow = (at) => at.slice(0, 10) >= since
  const runs = state.suggester.runs.filter((run) => inWindow(run.started)).sort((a, b) => b.started.localeCompare(a.started))
  const sum = (key) => runs.reduce((total, run) => total + (run[key] ?? 0), 0)

  let pending = 0
  let available = true
  let error = null
  if (firefly) {
    try {
      const group = await firefly.ruleGroup('Suggestions')
      pending = group ? (await firefly.all(`rule-groups/${group.id}/rules`)).length : 0
    } catch (caught) {
      available = false
      error = caught.message.slice(0, 200)
    }
  } else {
    available = false
    error = 'The mock suggester has no Firefly token (MOCK_FIREFLY_TOKEN)'
  }

  const events = state.events.filter((event) => inWindow(event.at)).sort((a, b) => b.at.localeCompare(a.at))
  return {
    imports: {
      tracking_since: state.tracking_since,
      totals: { created: dayList.reduce((total, day) => total + day.created, 0), failed: dayList.reduce((total, day) => total + day.failed, 0) },
      days: dayList,
      recent_failures: events.filter((event) => event.level === 'error' && event.step === 'import').map((event) => ({ at: event.at, connection: event.bank ?? event.connection, error: event.message })),
    },
    manual: state.manual,
    connections: Object.values(state.connections)
      .filter((connection) => connection.status !== 'pending')
      .map((connection) => ({
        name: connection.name,
        bank: connection.bank.name,
        status: statusOf(connection),
        last_error: connection.last_error,
        last_import_at: connection.last_import?.at ?? null,
        last_import_ok: connection.last_import?.ok ?? null,
        days_left: daysLeftOf(connection),
      })),
    events: {
      totals: { error: events.filter((event) => event.level === 'error').length, warning: events.filter((event) => event.level === 'warning').length },
      recent: events,
    },
    suggester: {
      available,
      error,
      tracking_since: state.suggester.tracking_since,
      totals: {
        runs: runs.length,
        calls: sum('calls'),
        suggestions: sum('suggestions'),
        low_confidence: sum('low_confidence'),
        searches: sum('searches'),
        input_tokens: sum('input_tokens'),
        output_tokens: sum('output_tokens'),
        group_errors: sum('group_errors'),
        write_errors: sum('write_errors'),
        failed_runs: runs.filter((run) => run.status === 'failed').length,
      },
      last_run: runs[0] ?? null,
      suggestions: { pending, resolved: state.suggester.memory.resolved ?? 0, rejected: state.suggester.memory.rejected.length },
      recent: runs,
    },
  }
}

// --------------------------------------------------------------------- imports

const importFeed = (connection, account, date) => {
  // The same day of the same account always gives the same transactions: re-imports are duplicates, as with a real bank
  const rng = random([...`${connection.name}|${account.id}|${date}`].reduce((hash, char) => (hash * 31 + char.charCodeAt(0)) >>> 0, 7))
  const list = []
  if (rng.chance(0.35)) list.push(rng.pick(GROCERIES))
  if (rng.chance(0.3)) list.push(rng.pick(EATING_OUT))
  if (rng.chance(0.12)) list.push(rng.pick(TRANSPORT))
  if (rng.chance(0.06)) list.push(rng.pick(OCCASIONAL))
  if (rng.chance(0.05)) list.push(rng.pick(UNSORTED))
  return list.map((merchant, index) => ({
    type: 'withdrawal',
    date: `${date}T12:00:00+02:00`,
    amount: rng.amount(merchant.min, merchant.max).toFixed(2),
    description: merchant.description,
    source_id: String(account.firefly_account_id),
    destination_name: merchant.destination,
    external_id: `mock-${connection.name}-${account.id}-${date}-${index}`,
    notes: 'Imported by the mock importer (dummy data)',
  }))
}

let runningImport = null

async function runImport(connection, dateFrom, dateTo, source = 'manual') {
  const manual = connection.manual_import
  const update = (fields) => {
    Object.assign(manual, fields)
    save()
  }
  const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms))
  let created = 0
  let duplicates = 0
  try {
    await pause(1200)
    update({ status: 'importing' })
    if (!firefly) {
      throw new Error('The mock importer has no Firefly token (MOCK_FIREFLY_TOKEN)')
    }
    for (const account of connection.accounts) {
      if (account.firefly_account_id === null) continue
      if (account.firefly_account_id === 0) {
        const createdAccount = await firefly.post('accounts', { name: account.name ?? `${connection.bank.name} ${account.iban_masked}`, type: 'asset', account_role: 'defaultAsset', currency_code: account.currency ?? 'EUR' })
        account.firefly_account_id = Number(createdAccount.data.id)
      }
      for (let day = new Date(`${dateFrom}T12:00:00Z`); iso(day) <= dateTo; day = addDays(day, 1)) {
        for (const split of importFeed(connection, account, iso(day))) {
          try {
            await firefly.post('transactions', { error_if_duplicate_hash: true, apply_rules: true, fire_webhooks: false, transactions: [split] })
            created++
          } catch (error) {
            if (error.status === 422 && /duplicate/i.test(error.message)) duplicates++
            else throw error
          }
        }
      }
    }
    const summary = { messages: created, duplicates, first_error: null }
    connection.last_import = { at: now(), ok: true, summary }
    connection.last_error = null
    update({ status: 'suggesting', summary })
    state.events.push({ at: now(), level: 'info', step: 'import', flow: source, reason: null, message: `${created} new, ${duplicates} already there`, bank: connection.bank.name, connection: connection.name })

    await pause(800)
    const run = await suggest(firefly, { dateFrom, dateTo, source, memory: state.suggester.memory })
    state.suggester.runs.push(run)
    update({ status: 'done', finished_at: now(), suggest_error: run.status === 'failed' ? run.error : null, suggestions: { written: run.suggestions, low_confidence: run.low_confidence } })
    state.manual.done++
  } catch (error) {
    connection.last_import = { at: now(), ok: false, summary: { messages: created, first_error: error.message.slice(0, 300) } }
    state.events.push({ at: now(), level: 'error', step: 'import', flow: source, reason: null, message: error.message.slice(0, 300), bank: connection.bank.name, connection: connection.name })
    update({ status: 'failed', finished_at: now(), error: error.message.slice(0, 300) })
    state.manual.failed++
  } finally {
    runningImport = null
    save()
  }
}

function startImport(connection, dateFrom, dateTo) {
  if (runningImport) {
    throw new HttpError(409, `Another import is running (${runningImport})`)
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(dateFrom ?? '') || !/^\d{4}-\d{2}-\d{2}$/.test(dateTo ?? '') || dateFrom > dateTo) {
    throw new HttpError(422, 'date_from and date_to must be dates (YYYY-MM-DD), date_from first')
  }
  if (dateTo > iso(Date.now())) {
    throw new HttpError(422, 'date_to cannot be in the future')
  }
  if (dateFrom < iso(addDays(Date.now(), -MAX_HISTORY_DAYS))) {
    throw new HttpError(422, `The bank gives at most ${MAX_HISTORY_DAYS} days of history`)
  }
  runningImport = connection.name
  connection.manual_import = { id: `import-${Date.now()}`, status: 'queued', date_from: dateFrom, date_to: dateTo, started_at: now(), finished_at: null, summary: null, error: null, suggest_error: null, suggestions: null }
  state.manual.total++
  save()
  runImport(connection, dateFrom, dateTo)
  return connection.manual_import
}

// --------------------------------------------------------------- consent pages

function startConsent(connection, flow, returnPath) {
  const id = Math.random().toString(36).slice(2, 12)
  state.pending[id] = { name: connection.name, flow, return_path: returnPath ?? '/bank-connections/list' }
  save()
  return `${PUBLIC_URL}/consent/${id}`
}

const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char])

function consentPage(id, pending, connection) {
  const accounts = demoAccountsFor(connection)
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>${escapeHtml(connection.bank.name)} · consent (mock)</title>
<style>
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#eef1f5;color:#1d2433;min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}
  .card{background:#fff;border-radius:20px;max-width:420px;width:100%;padding:28px 24px;box-shadow:0 10px 40px rgba(20,30,60,.12)}
  .bank{display:flex;align-items:center;gap:12px;margin-bottom:18px}.bank img{width:44px;height:44px}
  h1{font-size:20px;margin:0}.mock{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.06em;color:#b45309;background:#fef3c7;border-radius:6px;padding:2px 6px;margin-left:6px}
  p{color:#5b6475;line-height:1.5;font-size:15px} ul{padding:0;list-style:none} li{padding:10px 12px;border:1px solid #e4e7ee;border-radius:12px;margin-bottom:8px;font-size:15px}
  li small{display:block;color:#8a93a5} .actions{display:flex;gap:10px;margin-top:20px}
  a.button{flex:1;text-align:center;text-decoration:none;padding:13px;border-radius:12px;font-weight:600;font-size:16px}
  .approve{background:#1d4ed8;color:#fff}.deny{background:#eef1f5;color:#1d2433}
</style></head><body><div class="card">
<div class="bank"><img src="${escapeHtml(connection.bank.logo)}" alt=""><div><h1>${escapeHtml(connection.bank.name)}<span class="mock">MOCK BANK</span></h1><small>${pending.flow === 'reauth' ? 'Renew access' : 'Connect accounts'}</small></div></div>
<p>Firefly Pocket (dev) asks to read the balances and transactions of these accounts for ${CONSENT_DAYS} days. Nothing here is real.</p>
<ul>${accounts.map((account) => `<li>${escapeHtml(account.name)}<small>${escapeHtml(account.iban_masked)} · ${escapeHtml(account.currency)}</small></li>`).join('')}</ul>
<div class="actions"><a class="button deny" href="/consent/${id}/deny">Deny</a><a class="button approve" href="/consent/${id}/approve">Allow</a></div>
</div></body></html>`
}

function demoAccountsFor(connection) {
  if (connection.accounts.length) {
    return connection.accounts
  }
  const code = slug(connection.bank.name).slice(0, 3)
  const country = connection.bank.country
  return [
    { id: `${code}-${connection.name.length}-1`, name: `${connection.bank.name} current account`, iban_masked: `${country}56…${String(1000 + connection.name.length * 37).slice(-4)}`, currency: 'EUR', identification_hash: `h-${connection.name}-1`, firefly_account_id: null },
    ...(connection.psu_type === 'business' ? [] : [{ id: `${code}-${connection.name.length}-2`, name: 'Savings', iban_masked: `${country}12…${String(2000 + connection.name.length * 53).slice(-4)}`, currency: 'EUR', identification_hash: `h-${connection.name}-2`, firefly_account_id: null }]),
  ]
}

function finishConsent(id, approved) {
  const pending = state.pending[id]
  if (!pending) {
    return `${PICO_URL}/bank-connections/list?result=failed&reason=unknown`
  }
  delete state.pending[id]
  const connection = state.connections[pending.name]
  const back = (query) => `${PICO_URL}${pending.return_path}?${new URLSearchParams({ connection: pending.name, ...query })}`
  if (!connection) {
    save()
    return back({ result: 'failed', reason: 'unknown' })
  }
  if (!approved) {
    if (pending.flow === 'create') {
      delete state.connections[pending.name]
    }
    state.events.push({ at: now(), level: 'warning', step: 'consent', flow: pending.flow, reason: 'cancelled', message: 'The user denied the consent at the bank', bank: connection.bank.name, connection: connection.name })
    save()
    return back({ result: 'failed', reason: 'cancelled' })
  }
  connection.valid_until = addDays(Date.now(), CONSENT_DAYS).toISOString()
  connection.last_error = null
  if (pending.flow === 'create') {
    connection.accounts = demoAccountsFor(connection)
    connection.status = 'needs_mapping'
  }
  state.events.push({ at: now(), level: 'info', step: 'consent', flow: pending.flow, reason: null, message: `Consent granted for ${connection.accounts.length} account(s)`, bank: connection.bank.name, connection: connection.name })
  save()
  return back({ result: 'ok' })
}

// ---------------------------------------------------------------------- routes

class HttpError extends Error {
  constructor(status, message) {
    super(message)
    this.status = status
  }
}

const findConnection = (name) => {
  const connection = state.connections[name]
  if (!connection) throw new HttpError(404, `Connection ${name} not found`)
  return connection
}

async function api(method, route, query, body) {
  if (method === 'GET' && route === 'banks') {
    return { data: banksFor((query.get('country') ?? 'SI').toUpperCase()) }
  }
  if (method === 'GET' && route === 'connections') {
    await resolveAccountNames()
    return { data: Object.values(state.connections).filter((connection) => connection.status !== 'pending').map(present) }
  }
  if (method === 'POST' && route === 'connections') {
    const bank = banksFor((body.country ?? '').toUpperCase()).find((item) => item.name === body.bank)
    if (!bank) throw new HttpError(422, `Unknown bank ${body.bank} in ${body.country}`)
    let name = slug(`${bank.name}-${bank.country}`)
    for (let index = 2; state.connections[name]; index++) name = slug(`${bank.name}-${bank.country}-${index}`)
    state.connections[name] = { name, bank, psu_type: body.psu_type ?? 'personal', status: 'pending', mapped: false, valid_until: null, created_at: now(), accounts: [], last_import: null, last_error: null, manual_import: null }
    return { data: { name, auth_url: startConsent(state.connections[name], 'create', body.return_path) } }
  }
  if (method === 'GET' && route === 'status') {
    const days = Math.min(365, Math.max(1, Number(query.get('days') ?? 30) || 30))
    return { data: await statusReport(days) }
  }

  if (method === 'GET' && route === 'errors') {
    const date = query.get('date') ?? iso(Date.now())
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || Number.isNaN(Date.parse(date))) throw new HttpError(422, 'date must be a day, as 2026-11-09')
    return { data: errorsOn(date, query.get('connection')) }
  }

  const match = route.match(/^connections\/([a-z0-9-]+)(?:\/(accounts|reauth|imports))?$/)
  if (!match) throw new HttpError(404, `No route ${method} ${route}`)
  const [, name, action] = match

  if (!action && method === 'GET') {
    await resolveAccountNames()
    return { data: present(findConnection(name)) }
  }
  if (!action && method === 'DELETE') {
    findConnection(name)
    delete state.connections[name]
    save()
    return { status: 204 }
  }
  if (action === 'reauth' && method === 'POST') {
    return { data: { auth_url: startConsent(findConnection(name), 'reauth', body.return_path) } }
  }
  if (action === 'accounts' && method === 'PUT') {
    const connection = findConnection(name)
    const choices = new Map((body.accounts ?? []).map((item) => [String(item.id), item.firefly_account_id]))
    if (!connection.accounts.every((account) => choices.has(String(account.id)))) throw new HttpError(422, 'Every account of the connection needs a choice')
    if (![...choices.values()].some((value) => value !== null)) throw new HttpError(422, 'Choose at least one account to import')
    for (const account of connection.accounts) account.firefly_account_id = choices.get(String(account.id)) === null ? null : Number(choices.get(String(account.id)))
    const firstSetup = !connection.mapped
    connection.mapped = true
    connection.status = 'active'
    connection.import_since = body.import_since ?? null
    save()
    if (firstSetup && connection.import_since && !runningImport) {
      startImport(connection, connection.import_since, iso(Date.now()))
    }
    return { data: present(connection) }
  }
  if (action === 'imports' && method === 'POST') {
    const connection = findConnection(name)
    if (!['active', 'expiring', 'error'].includes(statusOf(connection))) throw new HttpError(422, `The connection is ${statusOf(connection)}`)
    return { status: 202, data: startImport(connection, body.date_from, body.date_to) }
  }
  throw new HttpError(404, `No route ${method} ${route}`)
}

const send = (response, status, payload, type = 'application/json') => {
  response.writeHead(status, { 'Content-Type': type, 'Cache-Control': 'no-store' })
  response.end(type === 'application/json' ? JSON.stringify(payload) : payload)
}

const readBody = (request) =>
  new Promise((resolve) => {
    let data = ''
    request.on('data', (chunk) => (data += chunk))
    request.on('end', () => {
      try {
        resolve(data ? JSON.parse(data) : {})
      } catch {
        resolve({})
      }
    })
  })

const server = http.createServer(async (request, response) => {
  const url = new URL(request.url, PUBLIC_URL)
  try {
    if (url.pathname === '/' || url.pathname === '/health') {
      return send(response, 200, { ok: true, service: 'mock-fireflyx-importer', firefly: Boolean(firefly) })
    }
    const logo = url.pathname.match(/^\/logo\/([a-z0-9-]+)\.svg$/)
    if (logo) {
      return send(response, 200, logoSvg(logo[1], /^#[0-9a-f]{6}$/i.test(url.searchParams.get('c') ?? '') ? url.searchParams.get('c') : '#4a6d8c'), 'image/svg+xml')
    }
    const consent = url.pathname.match(/^\/consent\/([a-z0-9]+)(?:\/(approve|deny))?$/)
    if (consent) {
      const [, id, decision] = consent
      if (decision) {
        response.writeHead(302, { Location: finishConsent(id, decision === 'approve') })
        return response.end()
      }
      const pending = state.pending[id]
      const connection = pending && state.connections[pending.name]
      return connection ? send(response, 200, consentPage(id, pending, connection), 'text/html; charset=utf-8') : send(response, 404, 'This consent link has expired.', 'text/plain')
    }
    if (!url.pathname.startsWith(`${PREFIX}/`)) {
      throw new HttpError(404, 'Not found')
    }
    if (request.headers.authorization !== `Bearer ${API_TOKEN}`) {
      throw new HttpError(401, 'Unauthenticated')
    }
    const body = ['POST', 'PUT'].includes(request.method) ? await readBody(request) : {}
    const result = await api(request.method, url.pathname.slice(PREFIX.length + 1).replace(/\/$/, ''), url.searchParams, body)
    if (result.status === 204) {
      response.writeHead(204)
      return response.end()
    }
    return send(response, result.status ?? 200, { data: result.data })
  } catch (error) {
    const status = error instanceof HttpError ? error.status : 500
    if (status === 500) console.error(error)
    return send(response, status, { error: error.message })
  }
})

server.listen(PORT, () => console.log(`Mock FireflyX importer on :${PORT} (Firefly ${firefly ? 'connected' : 'not configured'})`))
