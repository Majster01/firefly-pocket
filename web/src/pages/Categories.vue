<template>
  <div>
    <PageHeader title="Categories">
      <button class="btn small" @click="open(null)"><Icon name="plus" :size="18" /> Add</button>
    </PageHeader>

    <div class="seg period-seg">
      <button v-for="option in PERIODS" :key="option.value" :class="{ on: period === option.value }" @click="period = option.value">{{ option.label }}</button>
    </div>

    <div v-if="loading" class="loading"><span class="spinner" /></div>
    <div v-else class="card flush">
      <button v-for="category in rows" :key="category.id" class="item" @click="open(category)">
        <span class="avatar">{{ initials(category.name) }}</span>
        <span class="grow">
          <span class="item-title" style="display: block">{{ category.name }}</span>
          <span v-if="category.notes" class="item-sub ellipsis" style="display: block">{{ category.notes }}</span>
        </span>
        <span class="item-end">
          <span class="num item-title" style="display: block">{{ category.spent ? money(category.spent) : '—' }}</span>
          <span v-if="category.earned" class="item-sub num pos" style="display: block">+{{ money(category.earned) }}</span>
        </span>
      </button>
      <div v-if="!rows.length" class="empty"><h3>No categories yet</h3></div>
    </div>

    <Sheet :open="Boolean(editing)" :title="editing?.id ? 'Edit category' : 'New category'" @close="editing = null">
      <template #action><button class="btn small primary" :disabled="!editing?.name.trim()" @click="save">Save</button></template>
      <template v-if="editing">
        <label class="field"><span>Name</span><input v-model="editing.name" /></label>
        <label class="field"><span>Notes</span><textarea v-model="editing.notes" rows="3" placeholder="What belongs here, for you and the suggester" /></label>
        <RouterLink v-if="editing.id" class="btn block" :to="{ path: '/transactions', query: { q: `category_is:&quot;${editing.original}&quot;` } }">Show transactions</RouterLink>
        <button v-if="editing.id" class="btn block danger" style="margin-top: 10px" @click="remove">Delete category</button>
      </template>
    </Sheet>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import Sheet from '../components/Sheet.vue'
import { del, get, post, put } from '../lib/api.js'
import { load, store } from '../lib/data.js'
import { initials, isoDate, money, monthRange } from '../lib/format.js'
import { toast } from '../lib/toast.js'

const PERIODS = [
  { value: 'month', label: 'This month' },
  { value: 'last', label: 'Last month' },
  { value: 'year', label: 'This year' },
]
const period = ref('month')
const loading = ref(true)
const spent = ref({})
const earned = ref({})
const editing = ref(null)

const range = computed(() => {
  if (period.value === 'last') return monthRange(-1)
  if (period.value === 'year') return { start: `${new Date().getFullYear()}-01-01`, end: isoDate() }
  return monthRange(0)
})
const rows = computed(() =>
  store.categories
    .map((category) => ({ id: category.id, name: category.attributes.name, notes: category.attributes.notes, spent: spent.value[category.id] ?? 0, earned: earned.value[category.id] ?? 0 }))
    .sort((a, b) => b.spent - a.spent || a.name.localeCompare(b.name)),
)

const byId = (rows) => Object.fromEntries((rows ?? []).filter((row) => row.currency_code === 'EUR').map((row) => [row.id, Math.abs(Number(row.difference_float))]))

async function reload() {
  loading.value = true
  try {
    const [, expense, income] = await Promise.all([load('categories', { force: true }), get('insight/expense/category', range.value), get('insight/income/category', range.value)])
    spent.value = byId(expense)
    earned.value = byId(income)
  } finally {
    loading.value = false
  }
}

const open = (category) => (editing.value = category ? { ...category, original: category.name } : { id: null, name: '', notes: '' })

async function save() {
  const body = { name: editing.value.name.trim(), notes: editing.value.notes || null }
  if (editing.value.id) await put(`categories/${editing.value.id}`, body)
  else await post('categories', body)
  toast('Saved')
  editing.value = null
  reload()
}

async function remove() {
  if (!confirm(`Delete ${editing.value.original}? Its transactions stay, without a category.`)) return
  await del(`categories/${editing.value.id}`)
  editing.value = null
  reload()
}

watch(period, reload)
onMounted(reload)
</script>

<style>
.period-seg {
  margin-bottom: 14px;
}
</style>
