<template>
  <div>
    <section v-for="day in days" :key="day.date" class="day">
      <div class="day-head">
        <span>{{ dayLabel(day.date) }}</span>
        <span class="num">{{ day.total ? money(day.total, 'EUR', { signed: true }) : '' }}</span>
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
import { signedAmount, toEur } from '../lib/data.js'
import { dayLabel, money } from '../lib/format.js'

const props = defineProps({ splits: { type: Array, required: true }, accountId: { type: [String, Number], default: null } })

const days = computed(() => {
  const groups = new Map()
  for (const split of props.splits) {
    const date = split.date.slice(0, 10)
    if (!groups.has(date)) groups.set(date, { date, splits: [], total: 0 })
    const group = groups.get(date)
    group.splits.push(split)
    group.total += toEur(signedAmount(split), split.currency_code) ?? 0
  }
  return [...groups.values()]
})
</script>

<style>
.day + .day {
  margin-top: 16px;
}
.day-head {
  display: flex;
  justify-content: space-between;
  margin: 0 6px 6px;
  font-size: 13px;
  font-weight: 650;
  color: var(--muted);
}
</style>
