#!/usr/bin/env node
// Fills the local dev Firefly III with ~6 months of dummy data. Never point this at a real Firefly.
//
//   FIREFLY_URL=http://localhost:8081 FIREFLY_TOKEN=... node dev/scripts/seed.mjs
//
// It refuses to run on a Firefly that already has asset accounts, so it cannot pile duplicates on a seeded instance.

import { Firefly } from '../mock-importer/firefly.mjs'
import { suggest } from '../mock-importer/suggester.mjs'
import { EATING_OUT, GROCERIES, OCCASIONAL, TRANSPORT, UNSORTED, VWCE_PRICES, iban, random } from '../mock-importer/merchants.mjs'

const FIREFLY_URL = process.env.FIREFLY_URL ?? 'http://localhost:8081'
const FIREFLY_TOKEN = process.env.FIREFLY_TOKEN

if (!FIREFLY_TOKEN) {
  console.error('FIREFLY_TOKEN is missing (dev/.env MOCK_FIREFLY_TOKEN)')
  process.exit(1)
}
if (!/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(FIREFLY_URL.replace(/\/$/, ''))) {
  console.error(`Refusing to seed ${FIREFLY_URL}: only a local Firefly may get dummy data`)
  process.exit(1)
}

const firefly = new Firefly(FIREFLY_URL, FIREFLY_TOKEN)
const rng = random(20261030)

const MONTHS = 6
const today = new Date()
const iso = (date) => date.toISOString().slice(0, 10)
const addDays = (date, days) => new Date(date.getTime() + days * 86400000)
const start = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth() - MONTHS, 1))
const openingDate = iso(addDays(start, -1))
// Bank-fed transactions this recent are still uncategorized: the suggester's material
const UNCATEGORIZED_SINCE = iso(addDays(today, -40))

const log = (...args) => console.log('·', ...args)

async function tryPost(path, body, label) {
  try {
    return (await firefly.post(path, body)).data
  } catch (error) {
    console.warn(`  ! ${label ?? path}: ${error.message.slice(0, 240)}`)
    return null
  }
}

// ------------------------------------------------------------------ currencies

let vwceId = null

async function seedCurrencies() {
  await firefly.post('currencies/EUR/enable', {}).catch(() => null)
  // "primary" since Firefly 6.3; older versions called it "default"
  await firefly.post('currencies/EUR/primary', {}).catch(() => firefly.post('currencies/EUR/default', {}).catch(() => null))
  const currencies = await firefly.all('currencies')
  if (!currencies.some((item) => item.attributes.code === 'VWCE')) {
    await tryPost('currencies', { code: 'VWCE', name: 'Vanguard FTSE All-World (Acc)', symbol: 'VWCE', decimal_places: 4, enabled: true }, 'VWCE currency')
  }
  // Firefly checks currency_code against 3 characters in accounts and transactions: VWCE goes by id there
  vwceId = (await firefly.all('currencies')).find((item) => item.attributes.code === 'VWCE')?.id
  for (const code of ['USD', 'GBP', 'CHF']) {
    await firefly.post(`currencies/${code}/enable`, {}).catch(() => null)
  }
  for (const [month, price] of Object.entries(VWCE_PRICES)) {
    const date = `${month}-15`
    if (date > iso(today)) continue
    await tryPost('exchange-rates', { from: 'VWCE', to: 'EUR', rate: String(price), date }, `VWCE rate ${date}`)
  }
  log('currencies: EUR primary, VWCE with monthly EUR prices')
}

// -------------------------------------------------------------------- accounts

const ASSETS = [
  { key: 'nlb', name: 'NLB Current', iban: iban('SI', '020100012345678'), account_role: 'defaultAsset', currency_code: 'EUR', opening_balance: '2450.00', notes: 'Main account; salary comes in here' },
  { key: 'revolut', name: 'Revolut', iban: iban('LT', '325000012345678901'), account_role: 'defaultAsset', currency_code: 'EUR', opening_balance: '180.00', notes: 'Card for everyday spending' },
  { key: 'savings', name: 'Savings', iban: iban('SI', '020100098765432'), account_role: 'savingAsset', currency_code: 'EUR', opening_balance: '8000.00' },
  { key: 'wallet', name: 'Wallet', account_role: 'cashWalletAsset', currency_code: 'EUR', opening_balance: '60.00' },
  { key: 'vwce', name: 'IBKR · VWCE', account_role: 'defaultAsset', currency_code: 'VWCE', opening_balance: '42.0000', notes: 'Vanguard FTSE All-World UCITS ETF (Acc) shares at Interactive Brokers' },
]

const accounts = {}

async function seedAccounts() {
  const existing = await firefly.all('accounts', { type: 'asset' })
  if (existing.length > 0) {
    console.error(`Firefly at ${FIREFLY_URL} already has asset accounts. Reset it first: docker compose -f dev/docker-compose.yml down -v`)
    process.exit(1)
  }
  for (const { key, currency_code, ...account } of ASSETS) {
    account[currency_code === 'VWCE' ? 'currency_id' : 'currency_code'] = currency_code === 'VWCE' ? vwceId : currency_code
    const created = await firefly.post('accounts', {
      type: 'asset',
      include_net_worth: true,
      active: true,
      opening_balance_date: openingDate,
      ...account,
    })
    accounts[key] = created.data
  }
  accounts.loan = await tryPost(
    'accounts',
    {
      name: 'Car loan',
      type: 'liabilities',
      liability_type: 'loan',
      liability_direction: 'credit',
      opening_balance: '8400.00',
      opening_balance_date: openingDate,
      interest: '4.2',
      interest_period: 'monthly',
      currency_code: 'EUR',
      include_net_worth: true,
    },
    'Car loan',
  )
  log(`accounts: ${Object.keys(accounts).length}`)
}

// --------------------------------------------- categories, budgets, tags, rules

const CATEGORIES = {
  Groceries: 'Supermarkets and everyday food shopping',
  'Food & Drink': 'Restaurants, cafés and delivery',
  Transport: 'Fuel, rides, public transport',
  Housing: 'Rent and everything about the flat',
  Bills: 'Utilities and phone',
  Subscriptions: 'Streaming and software',
  Shopping: 'Clothes, electronics, online orders',
  Home: 'Furniture and household goods',
  Health: 'Pharmacy, gym, doctor',
  Entertainment: 'Cinema, concerts, games',
  Travel: 'Flights, stays, holidays',
  Gifts: 'Presents for others',
  Salary: 'Income from work',
  Investments: 'ETF buys (VWCE)',
  Other: 'Anything else',
}

const BUDGETS = {
  Groceries: { amount: 420, categories: ['Groceries'] },
  'Eating out': { amount: 220, categories: ['Food & Drink'] },
  Transport: { amount: 170, categories: ['Transport'] },
  Fun: { amount: 150, categories: ['Entertainment', 'Shopping'] },
  Subscriptions: { amount: 45, categories: ['Subscriptions'] },
}

const budgets = {}
const budgetForCategory = {}

async function seedCategoriesBudgetsTags() {
  for (const [name, notes] of Object.entries(CATEGORIES)) {
    await tryPost('categories', { name, notes }, `category ${name}`)
  }
  for (const [name, { amount, categories }] of Object.entries(BUDGETS)) {
    const budget = await tryPost('budgets', { name, active: true }, `budget ${name}`)
    if (!budget) continue
    budgets[name] = budget
    categories.forEach((category) => (budgetForCategory[category] = budget.id))
    for (let offset = -MONTHS; offset <= 1; offset++) {
      const monthStart = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth() + offset, 1))
      const monthEnd = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth() + offset + 1, 0))
      await tryPost(`budgets/${budget.id}/limits`, { start: iso(monthStart), end: iso(monthEnd), amount: String(amount), currency_code: 'EUR' }, `limit ${name}`)
    }
  }
  for (const [tag, description] of [
    ['vacation-2026', 'Summer holiday in Croatia'],
    ['work-lunch', 'Lunches with the team'],
    ['gift', 'Presents'],
    ['reimbursable', 'Paid for others, to be paid back'],
  ]) {
    await tryPost('tags', { tag, description }, `tag ${tag}`)
  }
  log(`categories: ${Object.keys(CATEGORIES).length}, budgets: ${Object.keys(budgets).length}, tags: 4`)
}

async function seedRules() {
  const categorization = await firefly.ruleGroup('Categorization', { create: true, description: 'Live category rules. Pocket adds approved suggestions here.' })
  await firefly.ruleGroup('Suggestions', { create: true, active: false, description: 'Pending category suggestions, written by the suggester' })

  const rule = (title, triggers, actions, order) =>
    tryPost(
      'rules',
      {
        title,
        rule_group_id: categorization.id,
        trigger: 'store-journal',
        active: true,
        strict: false,
        stop_processing: true,
        ...(order ? { order } : {}),
        triggers: triggers.map((trigger) => ({ active: true, prohibited: false, stop_processing: true, ...trigger })),
        actions: actions.map((action) => ({ active: true, stop_processing: false, ...action })),
      },
      title,
    )
  const categoryRule = (category, keywords) =>
    rule(
      `Category: ${category}`,
      keywords.map((value) => ({ type: 'description_contains', value })),
      [{ type: 'set_category', value: category }],
    )

  // The guard: a transaction that already has a category stops here. Firefly needs an action, so it removes a tag nobody uses
  await rule('Stop: already categorized', [{ type: 'has_any_category', value: 'true' }], [{ type: 'remove_tag', value: 'pocket-guard-noop' }], 1)
  await categoryRule('Groceries', ['mercator', 'spar slovenija', 'lidl', 'hofer'])
  await categoryRule('Food & Drink', ['gostilna'])
  await categoryRule('Subscriptions', ['netflix', 'spotify'])
  // The generic tail, in this order (RuleSuggestionService::GENERIC_TAIL_CATEGORIES)
  await categoryRule('Bills', ['telekom', 'elektro energija'])
  await categoryRule('Shopping', ['amazon', 'zara'])
  await categoryRule('Other', ['misc payment'])
  log('rule groups: Categorization (guard + category rules), Suggestions')
}

// ---------------------------------------------------------------- transactions

const queue = []

function withdrawal(date, source, merchant, category, extra = {}) {
  const amount = rng.amount(merchant.min, merchant.max)
  queue.push({
    type: 'withdrawal',
    date,
    amount: amount.toFixed(2),
    description: merchant.description,
    source_id: accounts[source].id,
    destination_name: merchant.destination,
    ...(category ? { category_name: category, budget_id: budgetForCategory[category] ?? undefined } : {}),
    ...extra,
  })
}

// Bank-fed spending: categorized when older, left for the rules/suggester when recent
const fedCategory = (date, category) => (date >= UNCATEGORIZED_SINCE ? null : category)

function planTransactions() {
  for (let day = new Date(start); day <= today; day = addDays(day, 1)) {
    const date = iso(day)
    const dom = day.getUTCDate()
    const month = date.slice(0, 7)
    const weekday = day.getUTCDay()

    if (dom === 1) {
      queue.push({ type: 'withdrawal', date, amount: '650.00', description: 'Rent', source_id: accounts.nlb.id, destination_name: 'Stanovanja Horvat d.o.o.', category_name: 'Housing' })
    }
    if (dom === 10) {
      queue.push({ type: 'deposit', date, amount: '2650.00', description: 'Salary', source_name: 'Acme Software d.o.o.', destination_id: accounts.nlb.id, category_name: 'Salary' })
    }
    if (dom === 11) {
      queue.push({ type: 'transfer', date, amount: '300.00', description: 'Monthly savings', source_id: accounts.nlb.id, destination_id: accounts.savings.id })
      queue.push({ type: 'transfer', date, amount: '250.00', description: 'Top up Revolut', source_id: accounts.nlb.id, destination_id: accounts.revolut.id })
    }
    if (dom === 12 && VWCE_PRICES[month]) {
      const shares = (400 / VWCE_PRICES[month]).toFixed(4)
      queue.push({
        type: 'transfer',
        date,
        amount: '400.00',
        description: 'Buy VWCE',
        source_id: accounts.nlb.id,
        destination_id: accounts.vwce.id,
        foreign_currency_id: vwceId,
        foreign_amount: shares,
        category_name: 'Investments',
        notes: `${shares} shares at €${VWCE_PRICES[month]}`,
      })
    }
    if (dom === 15) {
      withdrawal(date, 'nlb', { description: 'TELEKOM SLOVENIJE', destination: 'Telekom Slovenije', min: 29.99, max: 29.99 }, 'Bills')
      withdrawal(date, 'nlb', { description: 'ELEKTRO ENERGIJA D.O.O.', destination: 'Elektro energija', min: 38, max: 61 }, 'Bills')
      if (accounts.loan) {
        queue.push({ type: 'withdrawal', date, amount: '310.00', description: 'Car loan instalment', source_id: accounts.nlb.id, destination_id: accounts.loan.id })
      }
    }
    if (dom === 18) {
      withdrawal(date, 'revolut', { description: 'NETFLIX.COM', destination: 'Netflix', min: 12.99, max: 12.99 }, 'Subscriptions')
      withdrawal(date, 'revolut', { description: 'SPOTIFY P1A2B3', destination: 'Spotify', min: 10.99, max: 10.99 }, 'Subscriptions')
    }
    if (dom === 20) {
      withdrawal(date, 'revolut', UNSORTED[0], fedCategory(date, 'Subscriptions'))
    }
    if (dom === 3) {
      withdrawal(date, 'nlb', { description: 'FITNES FIT LJUBLJANA', destination: 'Fitnes Fit', min: 39, max: 39 }, 'Health')
    }

    // Groceries: about twice a week
    if (weekday === 2 || weekday === 6 || rng.chance(0.08)) {
      withdrawal(date, rng.chance(0.6) ? 'nlb' : 'revolut', rng.pick(GROCERIES), 'Groceries')
    }
    // Eating out: a few times a week
    if (rng.chance(0.4)) {
      const place = rng.pick(EATING_OUT)
      const category = place.destination === 'Gostilna Šestica' ? 'Food & Drink' : fedCategory(date, 'Food & Drink')
      withdrawal(date, 'revolut', place, category, weekday >= 1 && weekday <= 5 && rng.chance(0.25) ? { tags: ['work-lunch'] } : {})
    }
    if (rng.chance(0.03)) {
      withdrawal(date, 'wallet', { description: 'Market stand', destination: 'Tržnica', min: 4, max: 18 }, 'Groceries')
    }
    // Transport
    if (dom % 14 === 5) {
      withdrawal(date, 'nlb', TRANSPORT[0], fedCategory(date, 'Transport'))
    }
    if (rng.chance(0.12)) {
      withdrawal(date, 'revolut', TRANSPORT[1], fedCategory(date, 'Transport'))
    }
    // Occasional shopping
    if (rng.chance(0.09)) {
      const shop = rng.pick(OCCASIONAL)
      const isRuled = ['Amazon', 'Zara'].includes(shop.destination)
      withdrawal(date, rng.chance(0.5) ? 'nlb' : 'revolut', shop, isRuled ? shop.category : fedCategory(date, shop.category))
    }
    // The suggester's unknowns
    if (date >= UNCATEGORIZED_SINCE && rng.chance(0.06)) {
      withdrawal(date, 'revolut', rng.pick(UNSORTED.slice(1)), null)
    }
    if (dom === 25 && rng.chance(0.5)) {
      queue.push({ type: 'transfer', date, amount: '40.00', description: 'Cash withdrawal ATM', source_id: accounts.nlb.id, destination_id: accounts.wallet.id })
    }
  }

  // A holiday in August
  const year = today.getUTCFullYear()
  const holiday = [
    [`${year}-08-02`, 'RYANAIR FR 7HJ2K', 'Ryanair', 164.98],
    [`${year}-08-03`, 'BOOKING.COM HOTEL SPLIT', 'Booking.com', 486.0],
    [`${year}-08-05`, 'KONOBA MATEJUSKA SPLIT', 'Konoba Matejuška', 62.4],
    [`${year}-08-07`, 'JADROLINIJA BRAC', 'Jadrolinija', 38.5],
  ]
  for (const [date, description, destination, amount] of holiday) {
    if (date >= iso(start) && date <= iso(today)) {
      queue.push({ type: 'withdrawal', date, amount: amount.toFixed(2), description, source_id: accounts.revolut.id, destination_name: destination, category_name: 'Travel', tags: ['vacation-2026'] })
    }
  }
  // A purchase in USD
  queue.push({
    type: 'withdrawal',
    date: iso(addDays(today, -52)),
    amount: '18.42',
    description: 'STEAM PURCHASE',
    source_id: accounts.revolut.id,
    destination_name: 'Steam',
    foreign_currency_code: 'USD',
    foreign_amount: '19.99',
    category_name: 'Entertainment',
    budget_id: budgetForCategory.Entertainment,
  })
}

async function storeTransactions() {
  let stored = 0
  let failed = 0
  const workers = Array.from({ length: 4 }, async () => {
    while (queue.length) {
      const split = queue.shift()
      try {
        await firefly.storeTransaction({ ...split, date: `${split.date}T12:00:00+02:00` })
        stored++
      } catch (error) {
        failed++
        if (failed <= 5) console.warn(`  ! ${split.description}: ${error.message.slice(0, 240)}`)
      }
    }
  })
  await Promise.all(workers)
  log(`transactions: ${stored} stored${failed ? `, ${failed} failed` : ''}`)
}

// ----------------------------------------------- piggy banks and recurrences

async function seedPiggyBanks() {
  const piggy = (name, target, current, targetDate, notes) =>
    tryPost(
      'piggy-banks',
      {
        name,
        target_amount: String(target),
        start_date: iso(start),
        target_date: targetDate,
        transaction_currency_code: 'EUR',
        accounts: [{ account_id: accounts.savings.id, current_amount: String(current) }],
        notes,
      },
      `piggy bank ${name}`,
    )
  await piggy('Summer holiday 2027', 2000, 650, `${today.getUTCFullYear() + 1}-06-30`, 'Two weeks on the coast')
  await piggy('New laptop', 1600, 1180, iso(addDays(today, 75)), 'MacBook Air')
  await piggy('Emergency fund', 6000, 4100, null, 'Six months of rent')
  log('piggy banks: 3')
}

async function seedRecurrences() {
  const nextMonth = (day) => iso(new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth() + 1, day)))
  const recurrence = (title, type, day, transaction) =>
    tryPost(
      'recurrences',
      {
        type,
        title,
        first_date: nextMonth(day),
        repeat_until: `${today.getUTCFullYear() + 3}-12-31`,
        apply_rules: true,
        active: true,
        repetitions: [{ type: 'monthly', moment: String(day), skip: 0, weekend: 1 }],
        transactions: [{ currency_code: 'EUR', ...transaction }],
      },
      `recurrence ${title}`,
    )
  // The seeded transactions created these counterparties already
  const counterparty = async (type, name) =>
    (await firefly.all('accounts', { type })).find((item) => item.attributes.name === name) ?? (await tryPost('accounts', { name, type }, `${type} ${name}`))
  const expense = (name) => counterparty('expense', name)
  const revenue = (name) => counterparty('revenue', name)

  const landlord = await expense('Stanovanja Horvat d.o.o.')
  const netflix = await expense('Netflix')
  const employer = await revenue('Acme Software d.o.o.')

  await recurrence('Rent', 'withdrawal', 1, { description: 'Rent', amount: '650.00', source_id: accounts.nlb.id, destination_id: landlord?.id, category_name: 'Housing' })
  await recurrence('Salary', 'deposit', 10, { description: 'Salary', amount: '2650.00', source_id: employer?.id, destination_id: accounts.nlb.id, category_name: 'Salary' })
  await recurrence('Netflix', 'withdrawal', 18, { description: 'NETFLIX.COM', amount: '12.99', source_id: accounts.revolut.id, destination_id: netflix?.id, category_name: 'Subscriptions', budget_id: budgets.Subscriptions?.id })
  await recurrence('Monthly savings', 'transfer', 11, { description: 'Monthly savings', amount: '300.00', source_id: accounts.nlb.id, destination_id: accounts.savings.id })
  log('recurring transactions: 4')
}

// ------------------------------------------------------------------------ main

await seedCurrencies()
await seedAccounts()
await seedCategoriesBudgetsTags()
await seedRules()
planTransactions()
log(`planned ${queue.length} transactions from ${iso(start)}; bank-fed ones since ${UNCATEGORIZED_SINCE} stay uncategorized`)
await storeTransactions()
await seedPiggyBanks()
await seedRecurrences()

// Suggestions for the uncategorized ones, as the nightly suggester would leave them
const run = await suggest(firefly, { dateFrom: UNCATEGORIZED_SINCE, dateTo: iso(today), source: 'nightly' })
log(`suggestions: ${run.suggestions} (${run.low_confidence} below the threshold)${run.error ? ` — ${run.error}` : ''}`)
console.log('Done.')
