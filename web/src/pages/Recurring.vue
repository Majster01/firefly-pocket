<template>
  <div>
    <PageHeader title="Recurring">
      <button class="btn small" @click="open"><Icon name="plus" :size="18" /> Add</button>
    </PageHeader>

    <section v-if="items" class="card hero">
      <div class="hero-label">Fixed costs a month</div>
      <div class="hero-value">{{ money(monthlyOut) }}</div>
      <div class="small muted">{{ money(monthlyIn) }} comes in every month</div>
    </section>

    <div v-if="!items" class="loading"><span class="spinner" /></div>
    <div v-else-if="!items.length" class="card empty"><h3>Nothing recurring</h3></div>
    <div v-else class="card flush">
      <div v-for="item in items" :key="item.id" class="item recurring" :class="{ inactive: !item.attributes.active }">
        <span class="avatar">{{ initials(item.attributes.title) }}</span>
        <span class="grow">
          <span class="item-title" style="display: block">{{ item.attributes.title }}</span>
          <span class="item-sub" style="display: block">{{ schedule(item) }}</span>
          <span class="item-sub" style="display: block">Next: {{ nextDate(item) }}</span>
        </span>
        <span class="item-end">
          <span class="num item-title" :class="{ pos: item.attributes.type === 'deposit' }" style="display: block">{{ amount(item) }}</span>
          <span class="row" style="justify-content: flex-end; margin-top: 6px; gap: 6px">
            <input type="checkbox" class="switch" :checked="item.attributes.active" :aria-label="`${item.attributes.title} active`" @change="toggle(item)" />
            <button class="btn icon small" aria-label="Delete" @click="remove(item)"><Icon name="trash" :size="16" /></button>
          </span>
        </span>
      </div>
    </div>

    <Sheet :open="Boolean(editing)" title="New recurring transaction" @close="editing = null">
      <template #action><button class="btn small primary" :disabled="!canSave" @click="save">Save</button></template>
      <template v-if="editing">
        <div class="seg block" style="margin-bottom: 12px">
          <button v-for="option in TYPES" :key="option.value" :class="{ on: editing.type === option.value }" @click="editing.type = option.value">{{ option.label }}</button>
        </div>
        <label class="field"><span>Title</span><input v-model="editing.title" placeholder="Rent, Netflix…" /></label>
        <div class="field-row">
          <label class="field"><span>Amount (EUR)</span><input v-model="editing.amount" inputmode="decimal" /></label>
          <label class="field"><span>Day of the month</span><input v-model="editing.day" type="number" min="1" max="31" /></label>
        </div>
        <div class="field-row">
          <label class="field">
            <span>{{ editing.type === 'deposit' ? 'From' : 'Account' }}</span>
            <select v-if="editing.type !== 'deposit'" v-model="editing.source_id">
              <option v-for="account in ownAccounts()" :key="account.id" :value="account.id">{{ account.attributes.name }}</option>
            </select>
            <select v-else v-model="editing.source_id">
              <option v-for="account in accountsOfType('revenue')" :key="account.id" :value="account.id">{{ account.attributes.name }}</option>
            </select>
          </label>
          <label class="field">
            <span>To</span>
            <select v-if="editing.type === 'withdrawal'" v-model="editing.destination_id">
              <option v-for="account in accountsOfType('expense')" :key="account.id" :value="account.id">{{ account.attributes.name }}</option>
            </select>
            <select v-else v-model="editing.destination_id">
              <option v-for="account in ownAccounts()" :key="account.id" :value="account.id">{{ account.attributes.name }}</option>
            </select>
          </label>
        </div>
        <label class="field">
          <span>Category</span>
          <select v-model="editing.category_name">
            <option value="">None</option>
            <option v-for="category in store.categories" :key="category.id" :value="category.attributes.name">{{ category.attributes.name }}</option>
          </select>
        </label>
      </template>
    </Sheet>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import Sheet from '../components/Sheet.vue'
import { del, getAll, post, put } from '../lib/api.js'
import { accountsOfType, loadAll, ownAccounts, store } from '../lib/data.js'
import { initials, isoDate, money, shortDate } from '../lib/format.js'
import { toast } from '../lib/toast.js'

const TYPES = [
  { value: 'withdrawal', label: 'Expense' },
  { value: 'deposit', label: 'Income' },
  { value: 'transfer', label: 'Transfer' },
]
const PER_MONTH = { daily: 30.4, weekly: 4.35, ndom: 1, monthly: 1, quarterly: 1 / 3, 'half-year': 1 / 6, yearly: 1 / 12 }

const items = ref(null)
const editing = ref(null)

const total = (item) => (item.attributes.transactions ?? []).reduce((sum, transaction) => sum + Number(transaction.amount), 0)
const perMonth = (item) => total(item) * (item.attributes.repetitions ?? []).reduce((sum, repetition) => sum + (PER_MONTH[repetition.type] ?? 1) / (Number(repetition.skip ?? 0) + 1), 0)
const monthlyOut = computed(() => (items.value ?? []).filter((item) => item.attributes.active && item.attributes.type === 'withdrawal').reduce((sum, item) => sum + perMonth(item), 0))
const monthlyIn = computed(() => (items.value ?? []).filter((item) => item.attributes.active && item.attributes.type === 'deposit').reduce((sum, item) => sum + perMonth(item), 0))

const amount = (item) => money(total(item) * (item.attributes.type === 'withdrawal' ? -1 : 1), item.attributes.transactions?.[0]?.currency_code ?? 'EUR', { signed: item.attributes.type !== 'transfer' })
const schedule = (item) => (item.attributes.repetitions ?? []).map((repetition) => repetition.description).join(', ')
const nextDate = (item) => {
  const upcoming = (item.attributes.repetitions ?? []).flatMap((repetition) => repetition.occurrences ?? []).sort()[0]
  return upcoming ? shortDate(upcoming) : item.attributes.active ? 'none planned' : 'paused'
}
const canSave = computed(() => editing.value?.title.trim() && Number(String(editing.value.amount).replace(',', '.')) > 0 && editing.value.source_id && editing.value.destination_id && editing.value.day >= 1 && editing.value.day <= 31)

async function reload() {
  items.value = await getAll('recurrences')
}

function open() {
  editing.value = { type: 'withdrawal', title: '', amount: '', day: new Date().getDate(), source_id: ownAccounts()[0]?.id, destination_id: accountsOfType('expense')[0]?.id, category_name: '' }
}

async function save() {
  const value = editing.value
  const today = new Date()
  const first = new Date(today.getFullYear(), today.getMonth() + (Number(value.day) <= today.getDate() ? 1 : 0), Number(value.day))
  await post('recurrences', {
    type: value.type,
    title: value.title.trim(),
    first_date: isoDate(first),
    repeat_until: `${today.getFullYear() + 5}-12-31`,
    apply_rules: true,
    active: true,
    repetitions: [{ type: 'monthly', moment: String(value.day), skip: 0, weekend: 1 }],
    transactions: [{ description: value.title.trim(), amount: Number(String(value.amount).replace(',', '.')).toFixed(2), currency_code: 'EUR', source_id: value.source_id, destination_id: value.destination_id, category_name: value.category_name || null }],
  })
  toast('Saved')
  editing.value = null
  reload()
}

async function toggle(item) {
  await put(`recurrences/${item.id}`, { active: !item.attributes.active })
  reload()
}

async function remove(item) {
  if (!confirm(`Delete ${item.attributes.title}? Transactions it made stay.`)) return
  await del(`recurrences/${item.id}`)
  reload()
}

onMounted(() => {
  loadAll('accounts', 'categories')
  reload()
})
</script>

<style>
.recurring.inactive {
  opacity: 0.55;
}
</style>
