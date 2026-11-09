<template>
  <div>
    <section v-for="day in days" :key="day.date" class="day">
      <div class="day-head">
        <span>{{ dayLabel(day.date) }}</span>
        <span class="num blurable">{{ day.total ? money(day.total, code, { signed: true }) : '' }}</span>
      </div>
      <div class="card flush">
        <TransactionRow v-for="split in day.splits" :key="split.transaction_journal_id" :split="split" :account-id="accountId" />
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import TransactionRow from './TransactionRow.vue'
import { accountById, signedAmount, signedFor, store, toEur } from '../lib/data.js'
import { dayLabel, money } from '../lib/format.js'

const props = defineProps({ splits: { type: Array, required: true }, accountId: { type: [String, Number], default: null } })

// On an account's page the days add up in its currency, transfers included; elsewhere in EUR, transfers left out
const code = computed(() => (props.accountId ? (accountById(props.accountId)?.attributes.currency_code ?? 'EUR') : 'EUR'))
const decimals = computed(() => (code.value === 'VWCE' ? 4 : 2))

function inFeedCurrency(amount, from) {
  if (from === code.value) return amount
  const eur = toEur(amount, from) ?? 0
  return code.value === 'EUR' ? eur : eur * (store.rates?.rates?.[code.value] ?? 0)
}

const days = computed(() => {
  const groups = new Map()
  for (const split of props.splits) {
    const date = split.date.slice(0, 10)
    if (!groups.has(date)) groups.set(date, { date, splits: [], total: 0 })
    const group = groups.get(date)
    group.splits.push(split)
    const { amount, code: from } = props.accountId ? signedFor(split, props.accountId) : { amount: signedAmount(split), code: split.currency_code }
    group.total += inFeedCurrency(amount, from)
  }
  // Rounded first, so a day that nets out shows nothing instead of "−€0.00"
  const factor = 10 ** decimals.value
  for (const group of groups.values()) group.total = Math.round(group.total * factor) / factor || 0
  return [...groups.values()]
})
</script>
