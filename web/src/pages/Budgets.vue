<template>
  <div>
    <PageHeader title="Budgets" :eyebrow="monthLabel(range.start)">
      <button class="btn icon small" aria-label="Previous month" @click="offset--"><Icon name="back" :size="18" /></button>
      <button class="btn icon small" aria-label="Next month" :disabled="offset >= 1" @click="offset++"><Icon name="chevron" :size="18" /></button>
      <button class="btn small" @click="open(null)"><Icon name="plus" :size="18" /> Add</button>
    </PageHeader>

    <section v-if="budgets" class="card hero">
      <div class="hero-label">Left this month</div>
      <div class="hero-value" :class="{ bad: totals.left < 0 }">{{ money(totals.left) }}</div>
      <div class="track hero-track"><i :class="meterClass(totals.ratio)" :style="{ width: `${Math.min(100, totals.ratio * 100)}%` }" /></div>
      <div class="small muted">{{ money(totals.spent) }} spent of {{ money(totals.limit) }} · {{ daysLeft }}</div>
    </section>

    <div v-if="!budgets" class="loading"><span class="spinner" /></div>
    <div v-else class="budget-grid">
      <button v-for="budget in budgets" :key="budget.id" class="card budget-card" @click="open(budget)">
        <div class="row">
          <strong class="grow ellipsis">{{ budget.name }}</strong>
          <span v-if="budget.limit" class="chip" :class="budget.ratio > 1 ? 'bad' : budget.ratio > 0.85 ? 'warn' : 'good'">
            {{ budget.ratio > 1 ? 'Over' : budget.ratio > 0.85 ? 'Close' : 'On track' }}
          </span>
        </div>
        <div class="budget-amount num">
          {{ money(budget.spent) }}
          <span class="muted"> / {{ budget.limit ? money(budget.limit) : 'no limit' }}</span>
        </div>
        <div v-if="budget.limit" class="track"><i :class="meterClass(budget.ratio)" :style="{ width: `${Math.min(100, budget.ratio * 100)}%` }" /></div>
        <div v-if="budget.limit" class="small muted budget-left">{{ budget.left >= 0 ? `${money(budget.left)} left` : `${money(-budget.left)} over` }}</div>
      </button>
    </div>

    <Sheet :open="Boolean(editing)" :title="editing?.id ? 'Edit budget' : 'New budget'" @close="editing = null">
      <template #action><button class="btn small primary" :disabled="!editing?.name.trim()" @click="save">Save</button></template>
      <template v-if="editing">
        <label class="field"><span>Name</span><input v-model="editing.name" /></label>
        <label class="field"><span>Limit for {{ monthLabel(range.start) }} (EUR)</span><input v-model="editing.limit" inputmode="decimal" placeholder="No limit" /></label>
        <button v-if="editing.id" class="btn block danger" @click="remove">Delete budget</button>
      </template>
    </Sheet>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import Sheet from '../components/Sheet.vue'
import { del, post, put } from '../lib/api.js'
import { budgetMonth, meterClass } from '../lib/data.js'
import { money, monthLabel, monthRange } from '../lib/format.js'
import { toast } from '../lib/toast.js'

const offset = ref(0)
const budgets = ref(null)
const editing = ref(null)
const range = computed(() => monthRange(offset.value))

const totals = computed(() => {
  const list = (budgets.value ?? []).filter((budget) => budget.limit > 0)
  const limit = list.reduce((sum, budget) => sum + budget.limit, 0)
  const spent = list.reduce((sum, budget) => sum + budget.spent, 0)
  return { limit, spent, left: limit - spent, ratio: limit ? spent / limit : 0 }
})
const daysLeft = computed(() => {
  if (offset.value !== 0) return offset.value < 0 ? 'month over' : 'not started'
  const end = new Date(`${range.value.end}T23:59:59`)
  const days = Math.ceil((end - Date.now()) / 86400000)
  return `${days} ${days === 1 ? 'day' : 'days'} to go`
})

async function reload() {
  budgets.value = null
  budgets.value = (await budgetMonth(range.value)).sort((a, b) => b.limit - a.limit)
}

const open = (budget) => (editing.value = budget ? { id: budget.id, name: budget.name, limit: budget.limit ? String(budget.limit) : '', limits: budget.limits } : { id: null, name: '', limit: '', limits: [] })

async function save() {
  const { id, name, limit, limits } = editing.value
  let budgetId = id
  if (id) await put(`budgets/${id}`, { name: name.trim() })
  else budgetId = (await post('budgets', { name: name.trim(), active: true })).data.id
  const amount = Number(String(limit).replace(',', '.'))
  const existing = limits[0]
  if (limit && amount > 0) {
    const body = { start: range.value.start, end: range.value.end, amount: amount.toFixed(2), currency_code: 'EUR' }
    if (existing) await put(`budgets/${budgetId}/limits/${existing.id}`, body)
    else await post(`budgets/${budgetId}/limits`, body)
  } else if (existing) {
    await del(`budgets/${budgetId}/limits/${existing.id}`)
  }
  toast('Saved')
  editing.value = null
  reload()
}

async function remove() {
  if (!confirm(`Delete the budget ${editing.value.name}?`)) return
  await del(`budgets/${editing.value.id}`)
  editing.value = null
  reload()
}

watch(offset, reload)
onMounted(reload)
</script>

<style>
.hero-track {
  margin: 12px 0 8px;
  height: 10px;
}
.budget-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr));
  gap: 12px;
  margin-top: 12px;
}
.budget-grid .card + .card {
  margin-top: 0;
}
.budget-card {
  text-align: left;
  border-radius: var(--radius);
}
.budget-amount {
  margin: 10px 0 8px;
  font-size: 20px;
  font-weight: 700;
}
.budget-amount .muted {
  font-size: 14px;
  font-weight: 500;
}
.budget-left {
  margin-top: 6px;
}
</style>
