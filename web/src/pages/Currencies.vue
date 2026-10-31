<template>
  <div>
    <PageHeader title="Exchange rates" :eyebrow="store.rates?.date ? `Rates of ${shortDate(store.rates.date)} · base EUR` : 'Base EUR'" />

    <section class="card hero vwce">
      <div class="row">
        <div class="grow">
          <div class="hero-label">VWCE · Vanguard FTSE All-World (Acc)</div>
          <div class="hero-value">{{ price ? money(price) : '—' }}</div>
          <div class="small muted">a share, Xetra</div>
        </div>
        <span class="avatar vwce-badge">📈</span>
      </div>
      <div v-if="holding" class="kpis vwce-kpis">
        <div>
          <div class="kpi-label">You hold</div>
          <div class="kpi-value">{{ shares(holding.shares) }} shares</div>
        </div>
        <div>
          <div class="kpi-label">Worth</div>
          <div class="kpi-value">{{ money(holding.eur) }}</div>
        </div>
      </div>
      <table v-if="history.length" class="history">
        <caption class="sr-only">VWCE price history stored in Firefly</caption>
        <thead>
          <tr><th>Date</th><th>Price</th><th>Change</th></tr>
        </thead>
        <tbody>
          <tr v-for="row in history" :key="row.date">
            <td>{{ shortDate(row.date) }}</td>
            <td class="num">{{ money(row.rate) }}</td>
            <td class="num" :class="row.change > 0 ? 'pos' : row.change < 0 ? 'bad' : 'muted'">{{ change(row.change) }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="card">
      <h2 class="card-title">Convert</h2>
      <div class="field-row converter">
        <label class="field"><span>Amount</span><input v-model="amount" inputmode="decimal" /></label>
        <label class="field">
          <span>From</span>
          <select v-model="from">
            <option v-for="code in codes" :key="code" :value="code">{{ code }}</option>
          </select>
        </label>
      </div>
      <div v-if="Number.isNaN(parsed)" class="small muted convert-hint">Enter an amount like 100 or 1.234,56</div>
      <div v-else class="convert-result num">= {{ converted === null ? 'no rate' : money(converted) }}</div>
    </section>

    <h2 class="section-title">Your currencies</h2>
    <div v-if="loadError" class="banner bad"><Icon name="alert" :size="18" /> {{ loadError }}</div>
    <div v-else-if="!store.loaded.currencies" class="loading"><span class="spinner" /></div>
    <div v-else class="card flush">
      <div v-for="currency in enabled" :key="currency.id" class="item">
        <span class="avatar">{{ currency.attributes.symbol }}</span>
        <span class="grow">
          <span class="item-title" style="display: block">{{ currency.attributes.code }}</span>
          <span class="item-sub" style="display: block">{{ currency.attributes.name }}{{ currency.attributes.primary || currency.attributes.default ? ' · primary' : '' }}</span>
        </span>
        <span class="item-end num">
          <template v-if="currency.attributes.code === 'EUR'">1</template>
          <template v-else-if="rate(currency.attributes.code)">
            {{ currency.attributes.code === 'VWCE' ? `1 share = ${money(1 / rate('VWCE'))}` : `1 € = ${formatRate(rate(currency.attributes.code))} ${currency.attributes.code}` }}
          </template>
          <span v-else class="muted">no rate</span>
        </span>
      </div>
    </div>
    <p class="tiny muted rates-note">Rates come from Pocket's server (open.er-api.com, fxratesapi.com, and Yahoo Finance for VWCE). Pocket converts every balance to euro with them.</p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import { getAll } from '../lib/api.js'
import { balanceOf, load, store, toEur, vwcePrice } from '../lib/data.js'
import { money, parseAmount, shares, shortDate } from '../lib/format.js'

const amount = ref('100')
const from = ref('USD')
const history = ref([])
const loadError = ref('')

const price = computed(() => vwcePrice())
// Firefly ignores ?enabled=true and lists all its currencies: only the ones switched on are the user's
const enabled = computed(() => store.currencies.filter((currency) => currency.attributes.enabled !== false))
const codes = computed(() => [...new Set([...enabled.value.map((currency) => currency.attributes.code), ...Object.keys(store.rates?.rates ?? {})])].sort())
const rate = (code) => store.rates?.rates?.[code]
const formatRate = (value) => new Intl.NumberFormat(undefined, { maximumFractionDigits: value < 10 ? 4 : 2 }).format(value)
// Empty counts as 0; something unreadable shows a hint instead of a made-up result
const parsed = computed(() => (String(amount.value).trim() ? parseAmount(amount.value, { maxDecimals: from.value === 'VWCE' ? 4 : 2 }) : 0))
const converted = computed(() => (Number.isNaN(parsed.value) ? null : toEur(parsed.value, from.value)))
const percent = new Intl.NumberFormat(undefined, { style: 'percent', minimumFractionDigits: 1, maximumFractionDigits: 1 })
// "+1.2%" / "−0.8%" with a real minus, like money()
const change = (value) => (value === null ? '' : `${value > 0 ? '+' : value < 0 ? '−' : ''}${percent.format(Math.abs(value))}`)
const holding = computed(() => {
  const account = store.accounts.find((item) => item.attributes.type === 'asset' && item.attributes.currency_code === 'VWCE')
  return account ? { shares: balanceOf(account), eur: toEur(balanceOf(account), 'VWCE') ?? 0 } : null
})

async function loadHistory() {
  const rows = await getAll('exchange-rates').catch(() => [])
  const prices = rows
    .filter((row) => row.attributes.from_currency_code === 'VWCE' && row.attributes.to_currency_code === 'EUR')
    .map((row) => ({ date: row.attributes.date.slice(0, 10), rate: Number(row.attributes.rate) }))
    .sort((a, b) => b.date.localeCompare(a.date))
  history.value = prices.map((row, index) => ({ ...row, change: prices[index + 1] ? row.rate / prices[index + 1].rate - 1 : null }))
}

onMounted(() => {
  load('rates', { force: true })
  load('currencies').catch((error) => (loadError.value = error.message))
  load('accounts').catch(() => null)
  loadHistory()
})
</script>

<style>
.vwce-badge {
  width: 52px;
  height: 52px;
  font-size: 24px;
}
.vwce-kpis {
  margin-top: 14px;
}
.history {
  width: 100%;
  margin-top: 16px;
  border-collapse: collapse;
  font-size: 14px;
}
.history th {
  text-align: left;
  font-size: 12px;
  color: var(--muted);
  font-weight: 600;
  padding: 0 0 6px;
}
.history td {
  padding: 7px 0;
  border-top: 1px solid var(--line);
}
.history td:not(:first-child),
.history th:not(:first-child) {
  text-align: right;
}
.converter {
  grid-template-columns: 2fr 1fr;
}
.convert-hint {
  padding: 8px 0;
}
.convert-result {
  font-size: 24px;
  font-weight: 700;
}
.rates-note {
  margin: 12px 6px;
}
</style>
