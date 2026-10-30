<template>
  <div>
    <PageHeader :title="greeting" :eyebrow="todayLabel">
      <button class="btn icon small" :aria-label="settings.dashboard.hideBalances ? 'Show amounts' : 'Hide amounts'" @click="settings.dashboard.hideBalances = !settings.dashboard.hideBalances">
        <Icon :name="settings.dashboard.hideBalances ? 'eyeOff' : 'eye'" :size="18" />
      </button>
    </PageHeader>

    <div class="dash" :class="{ hidden: settings.dashboard.hideBalances }">
      <div class="dash-main">
        <section class="card hero">
          <div class="hero-label">Net worth</div>
          <div class="hero-value blurable">
            <template v-if="store.loaded.accounts">{{ whole(netWorth.total) }}<span class="cents">{{ cents(netWorth.total) }}</span></template>
            <span v-else class="skeleton" style="display: inline-block; width: 200px; height: 40px" />
          </div>
          <div class="small muted hero-sub">
            <span v-if="vwce">VWCE {{ shares(vwce.shares) }} shares · <span class="blurable">{{ money(vwce.eur) }}</span> at {{ money(vwcePrice()) }}</span>
            <span v-if="netWorth.missing"> · some balances have no exchange rate</span>
          </div>
        </section>

        <div class="kpis">
          <div class="kpi glass">
            <div class="kpi-label">Spent in {{ monthName }}</div>
            <div class="kpi-value blurable">{{ month ? money(month.spent) : '…' }}</div>
          </div>
          <div class="kpi glass">
            <div class="kpi-label">Income in {{ monthName }}</div>
            <div class="kpi-value pos blurable">{{ month ? money(month.income) : '…' }}</div>
          </div>
          <RouterLink v-if="settings.features.budgets" to="/budgets" class="kpi glass">
            <div class="kpi-label">Left in budgets</div>
            <div class="kpi-value blurable" :class="{ bad: budgetTotals.left < 0 }">{{ budgets ? money(budgetTotals.left) : '…' }}</div>
          </RouterLink>
          <RouterLink to="/transactions?filter=uncategorized" class="kpi glass">
            <div class="kpi-label">Uncategorized</div>
            <div class="kpi-value" :class="{ warn: uncategorized > 0 }">{{ uncategorized ?? '…' }}</div>
          </RouterLink>
        </div>

        <RouterLink v-if="settings.features.ruleSuggestions && badges.suggestions" to="/suggestions" class="card attention">
          <span class="avatar"><Icon name="sparkles" :size="20" /></span>
          <span class="grow">
            <strong>{{ badges.suggestions }} category {{ badges.suggestions === 1 ? 'suggestion' : 'suggestions' }}</strong>
            <span class="item-sub" style="display: block">Review what the suggester proposes for new transactions</span>
          </span>
          <Icon name="chevron" class="chev" />
        </RouterLink>
        <RouterLink v-for="connection in attentionConnections" :key="connection.name" to="/bank-connections" class="card attention">
          <span class="avatar"><img v-if="connection.bank.logo" :src="connection.bank.logo" alt="" /></span>
          <span class="grow">
            <strong>{{ connection.bank.name }}: {{ connectionNote(connection) }}</strong>
            <span class="item-sub" style="display: block">{{ connection.status === 'needs_mapping' ? 'Choose the accounts to import' : 'Renew the bank consent to keep importing' }}</span>
          </span>
          <Icon name="chevron" class="chev" />
        </RouterLink>

        <section class="card">
          <h2 class="card-title">Spending per month</h2>
          <BarChart v-if="monthly.length" :items="monthly" label="Spending per month, in euro" :format="(value) => money(value, 'EUR', { decimals: 0 })" />
          <div v-else class="skeleton" style="height: 170px" />
        </section>

        <section class="card">
          <h2 class="card-title">
            <span>Where it went in {{ monthName }}</span>
            <RouterLink to="/categories" class="tiny">All categories</RouterLink>
          </h2>
          <ul v-if="categories.length" class="list cat-bars">
            <li v-for="category in categories.slice(0, 7)" :key="category.name" class="cat-bar">
              <div class="row">
                <span class="grow ellipsis">{{ category.name }}</span>
                <span class="num blurable">{{ money(category.amount) }}</span>
                <span class="num muted tiny share">{{ Math.round(category.share * 100) }}%</span>
              </div>
              <div class="track"><i class="ink" :style="{ width: `${category.share * 100}%` }" /></div>
            </li>
          </ul>
          <p v-else-if="month" class="muted small">Nothing spent yet this month.</p>
          <div v-else class="skeleton" style="height: 120px" />
        </section>
      </div>

      <div class="dash-side">
        <section v-if="settings.features.budgets" class="card">
          <h2 class="card-title">
            <span>Budgets</span>
            <RouterLink to="/budgets" class="tiny">Details</RouterLink>
          </h2>
          <ul v-if="budgets?.length" class="list">
            <li v-for="budget in budgets" :key="budget.id" class="budget-line">
              <div class="row">
                <span class="grow ellipsis">{{ budget.name }}</span>
                <span class="small num blurable" :class="{ bad: budget.left < 0 }">{{ budget.left < 0 ? `${money(-budget.left)} over` : `${money(budget.left)} left` }}</span>
              </div>
              <div class="track" :title="`${money(budget.spent)} of ${money(budget.limit)}`"><i :class="meterClass(budget.ratio)" :style="{ width: `${Math.min(100, budget.ratio * 100)}%` }" /></div>
            </li>
          </ul>
          <p v-else-if="budgets" class="muted small">No budgets with a limit this month.</p>
          <div v-else class="skeleton" style="height: 140px" />
        </section>

        <section class="card flush">
          <h2 class="card-title">
            <span>Latest</span>
            <RouterLink to="/transactions" class="tiny">All transactions</RouterLink>
          </h2>
          <div v-if="recent.length">
            <TransactionRow v-for="split in recent" :key="split.transaction_journal_id" :split="split" show-date />
          </div>
          <div v-else class="loading"><span class="spinner" /></div>
        </section>

        <section class="card flush">
          <h2 class="card-title">
            <span>Accounts</span>
            <RouterLink to="/accounts" class="tiny">All accounts</RouterLink>
          </h2>
          <RouterLink v-for="account in topAccounts" :key="account.id" :to="`/accounts/${account.id}`" class="item">
            <span class="avatar">{{ initials(account.attributes.name) }}</span>
            <span class="grow ellipsis item-title">{{ account.attributes.name }}</span>
            <span class="item-end num blurable">
              {{ money(balanceOf(account), account.attributes.currency_code) }}
              <span v-if="account.attributes.currency_code !== 'EUR' && toEur(balanceOf(account), account.attributes.currency_code) !== null" class="item-sub" style="display: block">{{ money(toEur(balanceOf(account), account.attributes.currency_code)) }}</span>
            </span>
          </RouterLink>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import BarChart from '../components/BarChart.vue'
import TransactionRow from '../components/TransactionRow.vue'
import { get } from '../lib/api.js'
import { assetAccounts, balanceOf, budgetMonth, flatten, load, meterClass, netWorthEur, store, toEur, vwcePrice } from '../lib/data.js'
import { initials, money, monthRange, shares } from '../lib/format.js'
import { settings } from '../lib/settings.js'
import { badges } from '../lib/badges.js'
import { composer } from '../lib/composer.js'

const now = new Date()
const hour = now.getHours()
const greeting = hour < 5 ? 'Good night' : hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'
const todayLabel = new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long' }).format(now)
const monthName = new Intl.DateTimeFormat(undefined, { month: 'long' }).format(now)
const shortMonth = new Intl.DateTimeFormat(undefined, { month: 'short' })
const longMonth = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' })

const month = ref(null)
const monthly = ref([])
const categories = ref([])
const budgets = ref(null)
const recent = ref([])
const uncategorized = ref(null)
const connections = ref([])

const netWorth = computed(() => netWorthEur())
const whole = (value) => {
  const text = money(value)
  const match = text.match(/^(.*?)([.,]\d{2})(\D*)$/)
  return match ? match[1] + match[3] : text
}
const cents = (value) => money(value).match(/[.,]\d{2}/)?.[0] ?? ''

const vwce = computed(() => {
  const account = store.accounts.find((item) => item.attributes.type === 'asset' && item.attributes.currency_code === 'VWCE')
  if (!account) return null
  return { shares: balanceOf(account), eur: toEur(balanceOf(account), 'VWCE') ?? 0 }
})
const topAccounts = computed(() => assetAccounts().slice(0, 6))
const budgetTotals = computed(() => (budgets.value ?? []).reduce((totals, budget) => ({ left: totals.left + budget.left }), { left: 0 }))
const attentionConnections = computed(() => (settings.features.bankConnections ? connections.value.filter((item) => ['expiring', 'expired', 'error', 'needs_mapping'].includes(item.status)) : []))
const connectionNote = (connection) =>
  ({ expiring: `consent ends in ${connection.days_left} ${connection.days_left === 1 ? 'day' : 'days'}`, expired: 'consent ended', error: 'last import failed', needs_mapping: 'accounts not set up' })[connection.status]

const sumDifference = (rows) => -(rows ?? []).filter((row) => row.currency_code === 'EUR').reduce((total, row) => total + Number(row.difference_float), 0)

async function loadMonth() {
  const range = monthRange(0)
  const [expense, income, byCategory, uncategorizedJson] = await Promise.all([
    get('insight/expense/total', range),
    get('insight/income/total', range),
    get('insight/expense/category', range),
    get('search/transactions', { query: 'has_no_category:true type:withdrawal', limit: 1 }),
  ])
  month.value = { spent: sumDifference(expense), income: -sumDifference(income) }
  const rows = (byCategory ?? []).filter((row) => row.currency_code === 'EUR').map((row) => ({ name: row.name, amount: -Number(row.difference_float) }))
  const withoutCategory = await get('insight/expense/no-category', range).catch(() => [])
  const none = sumDifference(withoutCategory)
  if (none > 0) rows.push({ name: 'Uncategorized', amount: none })
  const total = rows.reduce((sum, row) => sum + row.amount, 0) || 1
  categories.value = rows.sort((a, b) => b.amount - a.amount).map((row) => ({ ...row, share: row.amount / total }))
  uncategorized.value = uncategorizedJson.meta?.pagination?.total ?? 0
}

async function loadMonthly() {
  const offsets = [-5, -4, -3, -2, -1, 0]
  const totals = await Promise.all(offsets.map((offset) => get('insight/expense/total', monthRange(offset)).catch(() => [])))
  monthly.value = offsets.map((offset, index) => {
    const date = new Date(now.getFullYear(), now.getMonth() + offset, 1)
    return { label: longMonth.format(date), short: shortMonth.format(date), value: sumDifference(totals[index]), current: offset === 0, note: offset === 0 ? 'so far' : '' }
  })
}

async function loadRecent() {
  const json = await get('transactions', { limit: 8, type: 'all' })
  recent.value = flatten(json.data ?? []).filter((split) => split.type !== 'opening balance').slice(0, 8)
}

function loadEverything() {
  load('accounts', { force: true })
  load('rates')
  loadMonth()
  loadMonthly()
  loadRecent()
  if (settings.features.budgets) budgetMonth(monthRange(0)).then((list) => (budgets.value = list.filter((budget) => budget.limit > 0).sort((a, b) => b.ratio - a.ratio)))
  if (settings.features.bankConnections) get('bank-connections', undefined, { quiet: true }).then((json) => (connections.value = json?.data ?? [])).catch(() => null)
}

watch(() => composer.version, loadEverything)
onMounted(loadEverything)
</script>

<style>
.dash {
  display: grid;
  gap: 12px;
}
.dash-main,
.dash-side {
  display: grid;
  gap: 12px;
  align-content: start;
  min-width: 0;
}
.dash .card + .card {
  margin-top: 0;
}
.hero-sub {
  margin-top: 6px;
}
.attention {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
}
.attention .avatar {
  color: var(--accent);
}
.cat-bar {
  padding: 7px 0;
}
.cat-bar .track {
  margin-top: 6px;
  height: 6px;
}
.cat-bar .share {
  width: 34px;
  text-align: right;
}
.budget-line {
  padding: 8px 0;
}
.budget-line .track {
  margin-top: 7px;
}
.dash.hidden .blurable {
  filter: blur(9px);
}
.blurable {
  transition: filter 0.2s ease;
}
@media (min-width: 900px) {
  .dash {
    grid-template-columns: minmax(0, 1.5fr) minmax(320px, 1fr);
    gap: 16px;
  }
  .dash-main,
  .dash-side {
    gap: 16px;
  }
  .kpis {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}
</style>
