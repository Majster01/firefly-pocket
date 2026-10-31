import { reactive } from 'vue'
import { get, getAll } from './api.js'

// Lists most pages need, fetched once and refreshed after a change
export const store = reactive({
  accounts: [],
  categories: [],
  budgets: [],
  tags: [],
  currencies: [],
  rates: null,
  user: null,
  loaded: {},
})

const loaders = {
  accounts: () => getAll('accounts'),
  categories: () => getAll('categories'),
  budgets: () => getAll('budgets'),
  tags: () => getAll('tags'),
  // Firefly ignores ?enabled=true and sends all 42: keep the ones switched on (VWCE is one)
  currencies: async () => (await getAll('currencies')).filter((currency) => currency.attributes.enabled),
  rates: () => get('currencies/exchange', undefined, { quiet: true }).catch(() => null),
  user: async () => (await get('user'))?.data ?? null,
}

const inflight = {}

export async function load(name, { force = false } = {}) {
  if (store.loaded[name] && !force) return store[name]
  inflight[name] ??= loaders[name]()
    .then((value) => {
      store[name] = value
      store.loaded[name] = true
      return value
    })
    .finally(() => delete inflight[name])
  return inflight[name]
}

export const loadAll = (...names) => Promise.all(names.map((name) => load(name)))

export function invalidate(...names) {
  for (const name of names) store.loaded[name] = false
}

// ------------------------------------------------------------- currencies

export const PRIMARY = 'EUR'

/** Rates are units per 1 EUR (VWCE: shares per euro), so EUR = amount / rate. Null when the rate is unknown. */
export function toEur(amount, code) {
  const value = Number(amount ?? 0)
  if (!code || code === PRIMARY) return value
  const rate = store.rates?.rates?.[code]
  return rate ? value / rate : null
}

export const vwcePrice = () => {
  const rate = store.rates?.rates?.VWCE
  return rate ? 1 / rate : null
}

// --------------------------------------------------------------- accounts

export const ACCOUNT_TYPES = {
  asset: 'Assets',
  liabilities: 'Liabilities',
  expense: 'Expense accounts',
  revenue: 'Revenue accounts',
}

export const ROLES = { defaultAsset: 'Current account', savingAsset: 'Savings', sharedAsset: 'Shared', ccAsset: 'Credit card', cashWalletAsset: 'Cash' }
export const LIABILITY_KINDS = { loan: 'Loan', debt: 'Debt', mortgage: 'Mortgage' }
export const OWN_TYPES = ['asset', 'liabilities', 'liability']
/** What kind of own account it is: "Savings", "Loan" */
export const accountKind = (account) => ROLES[account?.attributes.account_role] ?? LIABILITY_KINDS[account?.attributes.liability_type] ?? null

export const accountById = (id) => store.accounts.find((account) => String(account.id) === String(id))
export const assetAccounts = () => store.accounts.filter((account) => account.attributes.type === 'asset' && account.attributes.active)
export const ownAccounts = () => store.accounts.filter((account) => OWN_TYPES.includes(account.attributes.type) && account.attributes.active)
export const accountsOfType = (type) => store.accounts.filter((account) => account.attributes.type === type)
export const balanceOf = (account) => Number(account.attributes.current_balance ?? 0)

export function netWorthEur() {
  let total = 0
  let missing = false
  for (const account of store.accounts) {
    const { type, include_net_worth: included, active } = account.attributes
    if (!active || included === false || !['asset', 'liabilities', 'liability'].includes(type)) continue
    const eur = toEur(balanceOf(account), account.attributes.currency_code)
    if (eur === null) missing = true
    else total += eur
  }
  return { total, missing }
}

// ----------------------------------------------------------- transactions

/** Firefly transaction groups to one row per split, carrying the group id. */
export const flatten = (groups) =>
  groups.flatMap((group) =>
    (group.attributes.transactions ?? []).map((split, index) => ({
      ...split,
      group_id: group.id,
      group_title: group.attributes.group_title,
      split_index: index,
      split_count: group.attributes.transactions.length,
    })),
  )

// Firefly's side of an opening balance or reconciliation; the other side is the user's account
const BOOKKEEPING = { 'opening balance': 'Initial balance account', reconciliation: 'Reconciliation account' }
export const isBookkeeping = (split) => Boolean(BOOKKEEPING[split.type])

/** Signed from the user's side: money out of an own account is negative. Transfers stay 0. */
export function signedAmount(split) {
  const amount = Number(split.amount)
  if (split.type === 'withdrawal') return -amount
  if (split.type === 'deposit') return amount
  // Into an asset from the initial balance account is +; a loan's opening balance goes the other way (the debt), so −
  if (isBookkeeping(split)) return split.source_type === BOOKKEEPING[split.type] ? amount : -amount
  return 0
}

/**
 * Signed from one account's page, in that account's currency: + into it, − out of it. A loan instalment is + on the
 * loan (the debt goes down); "Buy VWCE" is the shares on the VWCE account, not the euros. Payee pages keep signedAmount.
 */
export function signedFor(split, accountId) {
  const id = String(accountId)
  const into = String(split.destination_id) === id
  const side = into ? split.destination_type : String(split.source_id) === id ? split.source_type : null
  if (!side || side === 'Expense account' || side === 'Revenue account') return { amount: signedAmount(split), code: split.currency_code }
  // Firefly keeps a transfer in the source's currency; the destination's own amount is the foreign one
  const code = accountById(id)?.attributes.currency_code
  const foreign = into && split.foreign_amount && code && code === split.foreign_currency_code
  const amount = Number(foreign ? split.foreign_amount : split.amount)
  return { amount: into ? amount : -amount, code: foreign ? split.foreign_currency_code : split.currency_code, foreign }
}

export function counterparty(split) {
  if (split.type === 'deposit') return split.source_name
  if (split.type === 'transfer') return `${split.source_name} → ${split.destination_name}`
  // An opening balance names the user's own account, not Firefly's "Initial balance for …" side
  if (isBookkeeping(split)) return split.source_type === BOOKKEEPING[split.type] ? split.destination_name : split.source_name
  return split.destination_name
}

// ---------------------------------------------------------------- budgets

/** Each budget with its limit and spending in [start, end], in EUR. */
export async function budgetMonth({ start, end }) {
  const [budgets, limits] = await Promise.all([getAll('budgets', { start, end }), getAll('budget-limits', { start, end })])
  const sum = (list) => (list ?? []).filter((item) => item.currency_code === PRIMARY).reduce((total, item) => total + Number(item.sum), 0)
  return budgets
    .filter((budget) => budget.attributes.active !== false)
    .map((budget) => {
      const own = limits.filter((limit) => String(limit.attributes.budget_id) === String(budget.id))
      const limit = own.reduce((total, item) => total + Number(item.attributes.amount), 0)
      const spent = -sum(budget.attributes.spent)
      return { id: budget.id, name: budget.attributes.name, limit, spent, left: limit - spent, ratio: limit > 0 ? spent / limit : 0, limits: own }
    })
}

export const meterClass = (ratio) => (ratio > 1 ? 'bad' : ratio > 0.85 ? 'warn' : '')

// ------------------------------------------------------------ shared reads

// Home's tiles ask for overlapping data (the month's totals, its categories): one request each, kept for a minute
const cache = new Map()
const CACHE_MS = 60000

export function cachedGet(path, query = {}) {
  const key = `${path}?${new URLSearchParams(query)}`
  const hit = cache.get(key)
  if (hit && Date.now() - hit.at < CACHE_MS) return hit.promise
  const promise = get(path, query).catch((error) => {
    cache.delete(key)
    throw error
  })
  cache.set(key, { at: Date.now(), promise })
  return promise
}
export const clearCache = () => cache.clear()

/** Firefly insight rows to a EUR total, spending positive. */
export const insightTotal = (rows) => Math.abs((rows ?? []).filter((row) => row.currency_code === PRIMARY).reduce((total, row) => total + Number(row.difference_float), 0))
