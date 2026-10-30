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
  currencies: () => getAll('currencies', { enabled: true }),
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

export const accountById = (id) => store.accounts.find((account) => String(account.id) === String(id))
export const assetAccounts = () => store.accounts.filter((account) => account.attributes.type === 'asset' && account.attributes.active)
export const ownAccounts = () => store.accounts.filter((account) => ['asset', 'liabilities', 'liability'].includes(account.attributes.type) && account.attributes.active)
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

/** Signed from the user's side: money out of an own account is negative. */
export function signedAmount(split) {
  const amount = Number(split.amount)
  if (split.type === 'withdrawal') return -amount
  if (split.type === 'deposit') return amount
  return 0
}

export const counterparty = (split) => (split.type === 'deposit' ? split.source_name : split.type === 'transfer' ? `${split.source_name} → ${split.destination_name}` : split.destination_name)

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
