<template>
  <div>
    <PageHeader title="Transactions">
      <button class="btn icon small" aria-label="Refresh" @click="reload"><Icon name="refresh" :size="18" /></button>
    </PageHeader>

    <div class="toolbar">
      <label class="search grow">
        <Icon name="search" :size="18" />
        <input v-model="search" class="input" type="search" placeholder="Search description, payee, amount" aria-label="Search transactions" />
      </label>
      <div class="seg filter-seg">
        <button v-for="option in FILTERS" :key="option.value" :class="{ on: filter === option.value }" @click="filter = option.value">{{ option.label }}</button>
      </div>
    </div>

    <div v-if="searchTotal !== null" class="small muted total-line">
      {{ total }} matching · <span class="num">{{ money(searchTotal, 'EUR', { signed: true }) }}</span>
    </div>

    <div v-if="loading && !splits.length" class="loading"><span class="spinner" /></div>
    <div v-else-if="!splits.length" class="empty card">
      <h3>Nothing here</h3>
      <p>{{ search ? 'No transaction matches your search.' : 'Add one with the + button.' }}</p>
    </div>
    <TransactionFeed v-else :splits="splits" />

    <div v-if="hasMore" class="more">
      <button class="btn" :disabled="loading" @click="loadPage(page + 1)">
        <span v-if="loading" class="spinner" />
        <span v-else>Load more</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import TransactionFeed from '../components/TransactionFeed.vue'
import { get } from '../lib/api.js'
import { flatten, load } from '../lib/data.js'
import { money } from '../lib/format.js'
import { composer } from '../lib/composer.js'

const FILTERS = [
  { value: 'all', label: 'All' },
  { value: 'withdrawal', label: 'Expenses' },
  { value: 'deposit', label: 'Income' },
  { value: 'transfer', label: 'Transfers' },
  { value: 'uncategorized', label: 'Uncategorized' },
]
const PAGE_SIZE = 50

const route = useRoute()
const router = useRouter()
const search = ref(typeof route.query.q === 'string' ? route.query.q : '')
const filter = ref(FILTERS.some((option) => option.value === route.query.filter) ? route.query.filter : 'all')
const splits = ref([])
const page = ref(1)
const totalPages = ref(1)
const total = ref(0)
const loading = ref(false)
const searchTotal = ref(null)
const hasMore = computed(() => page.value < totalPages.value)

const query = computed(() => {
  const parts = []
  if (search.value.trim()) parts.push(search.value.trim())
  if (filter.value === 'uncategorized') parts.push('has_no_category:true type:withdrawal')
  return parts.join(' ')
})

let requestId = 0
async function loadPage(number) {
  const id = ++requestId
  loading.value = true
  try {
    const json = query.value
      ? await get('search/transactions', { query: query.value + (filter.value !== 'all' && filter.value !== 'uncategorized' ? ` type:${filter.value}` : ''), page: number, limit: PAGE_SIZE })
      : await get('transactions', { type: filter.value === 'all' ? 'all' : filter.value, page: number, limit: PAGE_SIZE })
    if (id !== requestId) return
    const rows = flatten(json.data ?? []).filter((split) => split.type !== 'opening balance' || filter.value === 'all')
    splits.value = number === 1 ? rows : [...splits.value, ...rows]
    page.value = number
    totalPages.value = json.meta?.pagination?.total_pages ?? 1
    total.value = json.meta?.pagination?.total ?? rows.length
  } finally {
    if (id === requestId) loading.value = false
  }
}

async function loadTotal() {
  searchTotal.value = null
  if (!search.value.trim()) return
  const json = await get('search/transactions/total', { query: query.value }, { quiet: true }).catch(() => null)
  // Pocket sums per currency, spending positive; show the EUR part as money in or out
  const eur = (json?.data?.totals ?? []).find((item) => item.currency_code === 'EUR')
  searchTotal.value = eur ? -eur.amount : null
}

const reload = () => {
  loadPage(1)
  loadTotal()
}

let debounce
watch([search, filter], () => {
  clearTimeout(debounce)
  debounce = setTimeout(() => {
    router.replace({ query: { ...(search.value ? { q: search.value } : {}), ...(filter.value !== 'all' ? { filter: filter.value } : {}) } })
    reload()
  }, 300)
})
watch(() => composer.version, () => loadPage(1))

onMounted(() => {
  load('accounts')
  load('rates')
  reload()
})
</script>

<style>
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 14px;
}
.toolbar .search {
  min-width: 240px;
}
.filter-seg {
  align-self: center;
}
.total-line {
  margin: -4px 6px 12px;
}
.more {
  display: flex;
  justify-content: center;
  margin-top: 16px;
}
</style>
