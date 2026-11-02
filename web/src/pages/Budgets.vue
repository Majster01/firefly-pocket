<template>
  <div>
    <PageHeader title="Budgets" :eyebrow="monthLabel(range.start)">
      <button class="btn icon small" aria-label="Previous month" @click="offset--"><Icon name="back" :size="18" /></button>
      <button class="btn icon small" aria-label="Next month" :disabled="offset >= 1" @click="offset++"><Icon name="chevron" :size="18" /></button>
      <button class="btn small" @click="open(null)"><Icon name="plus" :size="18" /> Add</button>
    </PageHeader>

    <section v-if="budgets" class="card hero">
      <div class="hero-label">{{ offset === 0 ? 'Left this month' : `Left in ${monthLabel(range.start)}` }}</div>
      <div class="hero-value blurable" :class="{ bad: totals.left < 0 }">{{ money(totals.left) }}</div>
      <div class="track hero-track"><i :class="meterClass(totals.ratio)" :style="{ width: `${Math.min(100, totals.ratio * 100)}%` }" /></div>
      <div class="small muted"><span class="blurable">{{ money(totals.spent) }} spent of {{ money(totals.limit) }}</span> · {{ daysLeft }}</div>
    </section>

    <div v-if="loadError" class="banner bad"><Icon name="alert" :size="18" /> {{ loadError }}</div>
    <div v-else-if="!budgets" class="loading"><span class="spinner" /></div>
    <div v-else class="budget-grid">
      <button v-for="budget in budgets" :key="budget.id" class="card budget-card" @click="open(budget)">
        <div class="row">
          <strong class="grow ellipsis">{{ budget.name }}</strong>
          <span v-if="budget.limit" class="chip" :class="budget.ratio > 1 ? 'bad' : budget.ratio > 0.85 ? 'warn' : 'good'">
            {{ budget.ratio > 1 ? 'Over' : budget.ratio > 0.85 ? 'Close' : 'On track' }}
          </span>
        </div>
        <div class="budget-amount num blurable">
          {{ money(budget.spent) }}
          <span class="muted"> / {{ budget.limit ? money(budget.limit) : 'no limit' }}</span>
        </div>
        <div v-if="budget.limit" class="track"><i :class="meterClass(budget.ratio)" :style="{ width: `${Math.min(100, budget.ratio * 100)}%` }" /></div>
        <div v-if="budget.limit" class="small muted budget-left blurable">{{ budget.left >= 0 ? `${money(budget.left)} left` : `${money(-budget.left)} over` }}</div>
      </button>
    </div>

    <Sheet :open="Boolean(editing)" :title="editing?.id ? 'Edit budget' : 'New budget'" @close="editing = null">
      <template #action>
        <button class="btn small primary" :disabled="!editing?.name.trim() || saving" @click="save">
          <span v-if="saving" class="spinner" />
          <span v-else>Save</span>
        </button>
      </template>
      <template v-if="editing">
        <label class="field"><span>Name</span><input v-model="editing.name" /></label>
        <label class="field"><span>Limit for {{ monthLabel(editing.range.start) }} (EUR)</span><input v-model="editing.limit" inputmode="decimal" placeholder="No limit" /></label>
        <button v-if="editing.id" class="btn block danger" :disabled="saving" @click="remove">Delete budget</button>
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
import { money, monthLabel, monthRange, parseAmount } from '../lib/format.js'
import { toast, toastError } from '../lib/toast.js'

const offset = ref(0)
const budgets = ref(null)
// The month the shown budgets belong to: an edit sets that month's limit, even if the arrows moved on meanwhile
const budgetsRange = ref(null)
const editing = ref(null)
const saving = ref(false)
const loadError = ref('')
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

// Stepping through months quickly: only the last request may fill the page
let request = 0

async function reload() {
  const mine = ++request
  const shown = range.value
  budgets.value = null
  loadError.value = ''
  try {
    const list = (await budgetMonth(shown)).sort((a, b) => b.limit - a.limit)
    if (mine !== request) return
    budgets.value = list
    budgetsRange.value = shown
  } catch (error) {
    if (mine === request) loadError.value = error.message
  }
}

const open = (budget) =>
  (editing.value = budget
    ? { id: budget.id, name: budget.name, limit: budget.limit ? String(budget.limit) : '', limits: budget.limits, range: budgetsRange.value ?? range.value }
    : { id: null, name: '', limit: '', limits: [], range: budgetsRange.value ?? range.value })

async function save() {
  if (saving.value) return
  const value = editing.value
  const text = String(value.limit).trim()
  const amount = text ? parseAmount(text) : null
  // Only an empty field removes the limit; something unreadable must not delete it and say Saved
  if (text && !(amount > 0)) {
    toastError('Enter the limit as an amount, like 250 or 250,50. Leave it empty for no limit.')
    return
  }
  saving.value = true
  try {
    if (value.id) await put(`budgets/${value.id}`, { name: value.name.trim() })
    // Remember the new id at once, so a retry after a failed limit does not create the budget twice
    else value.id = (await post('budgets', { name: value.name.trim(), active: true })).data.id
    const existing = value.limits[0]
    if (amount) {
      const body = { start: value.range.start, end: value.range.end, amount: amount.toFixed(2), currency_code: 'EUR' }
      if (existing) await put(`budgets/${value.id}/limits/${existing.id}`, body)
      else await post(`budgets/${value.id}/limits`, body)
    } else if (existing) {
      await del(`budgets/${value.id}/limits/${existing.id}`)
    }
    toast('Saved')
    editing.value = null
  } catch {
    // The error toast comes from api()
  } finally {
    saving.value = false
    reload()
  }
}

async function remove() {
  if (!confirm(`Delete the budget ${editing.value.name}?`)) return
  saving.value = true
  try {
    await del(`budgets/${editing.value.id}`)
    editing.value = null
  } catch {
    // Toasted by api()
  } finally {
    saving.value = false
    reload()
  }
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
