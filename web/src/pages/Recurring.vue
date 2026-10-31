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

    <div v-if="loadError" class="banner bad"><Icon name="alert" :size="18" /> {{ loadError }}</div>
    <div v-else-if="!items" class="loading"><span class="spinner" /></div>
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
            <input type="checkbox" class="switch" :checked="item.attributes.active" :disabled="busy[item.id]" :aria-label="`${item.attributes.title} active`" @change="toggle(item, $event)" />
            <button class="btn icon small" aria-label="Delete" :disabled="busy[item.id]" @click="remove(item)"><Icon name="trash" :size="16" /></button>
          </span>
        </span>
      </div>
    </div>

    <Sheet :open="Boolean(editing)" title="New recurring transaction" @close="editing = null">
      <template #action>
        <button class="btn small primary" :disabled="!canSave || saving" @click="save">
          <span v-if="saving" class="spinner" />
          <span v-else>Save</span>
        </button>
      </template>
      <template v-if="editing">
        <div class="seg block" style="margin-bottom: 12px">
          <button v-for="option in TYPES" :key="option.value" :class="{ on: editing.type === option.value }" :aria-pressed="editing.type === option.value" @click="editing.type = option.value">{{ option.label }}</button>
        </div>
        <label class="field"><span>Title</span><input v-model="editing.title" placeholder="Rent, Netflix…" /></label>
        <div class="field-row">
          <label class="field"><span>Amount (EUR)</span><input v-model="editing.amount" inputmode="decimal" /></label>
          <label class="field"><span>Day of the month</span><input v-model="editing.day" type="number" min="1" max="31" /></label>
        </div>
        <div class="field-row">
          <label class="field">
            <span>{{ editing.type === 'withdrawal' ? 'Account' : 'From' }}</span>
            <select v-model="editing.source_id">
              <option v-for="account in sourceAccounts" :key="account.id" :value="account.id">{{ account.attributes.name }}</option>
            </select>
          </label>
          <label class="field">
            <span>To</span>
            <select v-model="editing.destination_id">
              <option v-for="account in destinationAccounts" :key="account.id" :value="account.id">{{ account.attributes.name }}</option>
            </select>
          </label>
        </div>
        <label class="field">
          <span>Category</span>
          <select v-model="editing.category_id">
            <option value="">None</option>
            <option v-for="category in store.categories" :key="category.id" :value="category.id">{{ category.attributes.name }}</option>
          </select>
        </label>
      </template>
    </Sheet>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import Sheet from '../components/Sheet.vue'
import { del, get, getAll, post, put } from '../lib/api.js'
import { accountsOfType, assetAccounts, loadAll, ownAccounts, store } from '../lib/data.js'
import { initials, isoDate, money, parseAmount, shortDate } from '../lib/format.js'
import { toast } from '../lib/toast.js'

const TYPES = [
  { value: 'withdrawal', label: 'Expense' },
  { value: 'deposit', label: 'Income' },
  { value: 'transfer', label: 'Transfer' },
]
const PER_MONTH = { daily: 30.4, weekly: 4.35, ndom: 1, monthly: 1, quarterly: 1 / 3, 'half-year': 1 / 6, yearly: 1 / 12 }
const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
const yearlyFormat = new Intl.DateTimeFormat('en', { month: 'long', day: 'numeric' })

const items = ref(null)
const editing = ref(null)
const saving = ref(false)
const loadError = ref('')
// Recurrences with a pause/resume or delete on the way: their controls wait for it
const busy = reactive({})

const total = (item) => (item.attributes.transactions ?? []).reduce((sum, transaction) => sum + Number(transaction.amount), 0)
const perMonth = (item) => total(item) * (item.attributes.repetitions ?? []).reduce((sum, repetition) => sum + (PER_MONTH[repetition.type] ?? 1) / (Number(repetition.skip ?? 0) + 1), 0)
const monthlyOut = computed(() => (items.value ?? []).filter((item) => item.attributes.active && item.attributes.type === 'withdrawal').reduce((sum, item) => sum + perMonth(item), 0))
const monthlyIn = computed(() => (items.value ?? []).filter((item) => item.attributes.active && item.attributes.type === 'deposit').reduce((sum, item) => sum + perMonth(item), 0))

const amount = (item) => money(total(item) * (item.attributes.type === 'withdrawal' ? -1 : 1), item.attributes.transactions?.[0]?.currency_code ?? 'EUR', { signed: item.attributes.type !== 'transfer' })

const ordinal = (n) => {
  const tens = n % 100
  const suffix = tens >= 11 && tens <= 13 ? 'th' : { 1: 'st', 2: 'nd', 3: 'rd' }[n % 10] ?? 'th'
  return `${n}${suffix}`
}
// "Every 3 months", or the plain word when it is every one
const every = (skip, one, unit) => (skip > 0 ? `Every ${skip + 1} ${unit}s` : one)

/** Firefly's own text reads "Every month on the 10(st/nd/rd/th) day": say it from type and moment instead. */
function describeRepetition({ type, moment, skip, description }) {
  const gap = Number(skip ?? 0)
  const value = String(moment ?? '')
  if (type === 'daily') return every(gap, 'Every day', 'day')
  if (type === 'weekly' && WEEKDAYS[Number(value) - 1]) return gap > 0 ? `${every(gap, '', 'week')} on ${WEEKDAYS[Number(value) - 1]}` : `Every ${WEEKDAYS[Number(value) - 1]}`
  if (type === 'monthly' && Number(value) >= 1) return `${every(gap, 'Monthly', 'month')} on the ${ordinal(Number(value))}`
  if (type === 'ndom') {
    // "2,4": the second Thursday
    const [week, day] = value.split(',').map(Number)
    if (week >= 1 && WEEKDAYS[day - 1]) return `${every(gap, 'Monthly', 'month')} on the ${ordinal(week)} ${WEEKDAYS[day - 1]}`
  }
  if (type === 'yearly' && /^\d{4}-\d{2}-\d{2}/.test(value)) {
    const [year, month, day] = value.slice(0, 10).split('-').map(Number)
    return `${every(gap, 'Yearly', 'year')} on ${yearlyFormat.format(new Date(year, month - 1, day, 12))}`
  }
  return description ?? ''
}
const schedule = (item) => (item.attributes.repetitions ?? []).map(describeRepetition).join(', ')
const nextDate = (item) => {
  // Firefly still lists the dates of a paused one: nothing comes until it is resumed
  if (!item.attributes.active) return 'paused'
  const upcoming = (item.attributes.repetitions ?? []).flatMap((repetition) => repetition.occurrences ?? []).sort()[0]
  return upcoming ? shortDate(upcoming) : 'none planned'
}

// Firefly wants the accounts to fit the type: own → expense, revenue → own, own → own
const active = (list) => list.filter((account) => account.attributes.active !== false)
const sourceAccounts = computed(() => (editing.value?.type === 'deposit' ? active(accountsOfType('revenue')) : ownAccounts()))
const destinationAccounts = computed(() => (editing.value?.type === 'withdrawal' ? active(accountsOfType('expense')) : ownAccounts()))
const listed = (list, id) => list.some((account) => String(account.id) === String(id))
// An everyday account first, like the transaction sheet: not the car loan that sorts first
const firstOwn = (except) => [...assetAccounts(), ...ownAccounts()].find((account) => String(account.id) !== String(except))?.id ?? ''

const canSave = computed(() => {
  const value = editing.value
  if (!value) return false
  const day = Number(value.day)
  return Boolean(value.title.trim()) && parseAmount(value.amount) > 0 && Number.isInteger(day) && day >= 1 && day <= 31 && listed(sourceAccounts.value, value.source_id) && listed(destinationAccounts.value, value.destination_id) && String(value.source_id) !== String(value.destination_id)
})

// Switching the type: keep the accounts that still fit, pick fitting ones for the others
watch(
  () => editing.value?.type,
  () => {
    const value = editing.value
    if (!value) return
    if (!listed(sourceAccounts.value, value.source_id)) value.source_id = value.type === 'deposit' ? (sourceAccounts.value[0]?.id ?? '') : firstOwn(value.destination_id)
    if (!listed(destinationAccounts.value, value.destination_id) || String(value.destination_id) === String(value.source_id)) value.destination_id = value.type === 'withdrawal' ? (destinationAccounts.value[0]?.id ?? '') : firstOwn(value.source_id)
  },
)

// Toggles and saves each reload: an older answer must not overwrite a newer one
let request = 0

async function reload() {
  const mine = ++request
  try {
    const list = await getAll('recurrences')
    if (mine !== request) return
    items.value = list
    loadError.value = ''
  } catch (error) {
    if (mine === request && !items.value) loadError.value = error.message
  }
}

async function open() {
  editing.value = { type: 'withdrawal', title: '', amount: '', day: new Date().getDate(), source_id: firstOwn(), destination_id: active(accountsOfType('expense'))[0]?.id ?? '', category_id: '' }
  // Opened before the accounts arrived: fill them in once they are here
  if (!editing.value.source_id) {
    await loadAll('accounts').catch(() => null)
    if (editing.value && !editing.value.source_id) Object.assign(editing.value, { source_id: firstOwn(), destination_id: active(accountsOfType('expense'))[0]?.id ?? '' })
  }
}

/** The next time the day comes round, today included. Day 31 in a 30-day month is its last day (Firefly does the same). */
function firstDate(day) {
  const today = new Date()
  const inMonth = (offset) => new Date(today.getFullYear(), today.getMonth() + offset, Math.min(day, new Date(today.getFullYear(), today.getMonth() + offset + 1, 0).getDate()))
  const thisMonth = inMonth(0)
  return thisMonth.getDate() >= today.getDate() ? thisMonth : inMonth(1)
}

async function save() {
  if (!canSave.value || saving.value) return
  const value = editing.value
  const today = new Date()
  saving.value = true
  try {
    await post('recurrences', {
      type: value.type,
      title: value.title.trim(),
      first_date: isoDate(firstDate(Number(value.day))),
      repeat_until: `${today.getFullYear() + 5}-12-31`,
      apply_rules: true,
      active: true,
      repetitions: [{ type: 'monthly', moment: String(Number(value.day)), skip: 0, weekend: 1 }],
      // category_id: Firefly 6.6 drops a category_name on recurrences
      transactions: [{ description: value.title.trim(), amount: parseAmount(value.amount).toFixed(2), currency_code: 'EUR', source_id: value.source_id, destination_id: value.destination_id, category_id: value.category_id || null }],
    })
    toast('Saved')
    editing.value = null
  } catch {
    // The error toast comes from api()
  } finally {
    saving.value = false
    reload()
  }
}

async function toggle(item, event) {
  // What the switch shows now, not the list's copy, which may be from before an earlier toggle
  const wanted = event.target.checked
  busy[item.id] = true
  try {
    // Firefly 6.6 refuses an update without transactions ("Need at least one transaction"); their ids alone
    // keep them as they are. Fresh ids: an edit elsewhere may have replaced them
    const fresh = (await get(`recurrences/${item.id}`)).data
    const transactions = (fresh.attributes.transactions ?? []).map((transaction) => ({ id: transaction.id }))
    await put(`recurrences/${item.id}`, { active: wanted, transactions })
    toast(wanted ? 'Resumed' : 'Paused')
  } catch {
    // Toasted by api(); the reload puts the switch back where Firefly has it
    event.target.checked = item.attributes.active
  } finally {
    // Enabled again once the list shows Firefly's state, so a second toggle starts from it
    await reload()
    delete busy[item.id]
  }
}

async function remove(item) {
  if (!confirm(`Delete ${item.attributes.title}? Transactions it made stay.`)) return
  busy[item.id] = true
  try {
    await del(`recurrences/${item.id}`)
  } catch {
    // Toasted by api(); one deleted elsewhere is gone after the reload
  } finally {
    await reload()
    delete busy[item.id]
  }
}

onMounted(() => {
  loadAll('accounts', 'categories').catch(() => null)
  reload()
})
</script>

<style>
.recurring.inactive {
  opacity: 0.55;
}
</style>
