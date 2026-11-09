<template>
  <div>
    <PageHeader title="Transactions">
      <RefreshButton @click="reload" />
    </PageHeader>

    <div class="toolbar">
      <label class="search grow">
        <Icon name="search" :size="18" />
        <input v-model="search" class="input" type="search" placeholder="Search description, payee, amount" aria-label="Search transactions" />
      </label>
      <div ref="filterSeg" class="seg filter-seg" role="group" aria-label="Show">
        <button v-for="option in FILTERS" :key="option.value" :aria-pressed="filter === option.value" :class="{ on: filter === option.value }" @click="filter = option.value">{{ option.label }}</button>
      </div>
    </div>

    <div v-if="category || start" class="row wrap active-filters">
      <button v-if="category" class="chip filter-chip" @click="category = ''">{{ category }} <Icon name="x" :size="13" /></button>
      <button v-if="start" class="chip filter-chip" @click="clearDates">{{ rangeLabel }} <Icon name="x" :size="13" /></button>
    </div>

    <div v-if="searchTotal !== null" class="small muted total-line">
      {{ total }} matching · <span class="num blurable">{{ money(searchTotal, 'EUR', { signed: true }) }}</span>
    </div>

    <div v-if="loading && !splits.length" class="loading"><span class="spinner" /></div>
    <div v-else-if="!splits.length" class="empty card">
      <h3>Nothing here</h3>
      <p>{{ search ? 'No transaction matches your search.' : filtered ? 'No transaction matches these filters.' : 'Add one with the + button.' }}</p>
    </div>
    <!-- The old rows stay while new ones load, dimmed, so a filter change is visibly working -->
    <div v-else class="feed-wrap" :class="{ refreshing }" :aria-busy="refreshing">
      <span v-if="refreshing" class="spinner feed-spinner" />
      <TransactionFeed :splits="splits" />
    </div>

    <div v-if="hasMore" class="more">
      <button class="btn" :disabled="loading" @click="loadPage(page + 1)">
        <span v-if="loading" class="spinner" />
        <span v-else>Load more</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import RefreshButton from '../components/RefreshButton.vue'
import TransactionFeed from '../components/TransactionFeed.vue'
import { get } from '../lib/api.js'
import { flatten, load } from '../lib/data.js'
import { money, shortDate } from '../lib/format.js'
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
const isDay = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value ?? '')
// The page's state as the URL has it; category and dates come from Home
const fromQuery = (query) => ({
  search: typeof query.q === 'string' ? query.q : '',
  filter: FILTERS.some((option) => option.value === query.filter) ? query.filter : 'all',
  category: typeof query.category === 'string' ? query.category : '',
  start: isDay(query.start) ? query.start : '',
  end: isDay(query.end) ? query.end : '',
})
const initial = fromQuery(route.query)
const search = ref(initial.search)
const filter = ref(initial.filter)
const category = ref(initial.category)
const start = ref(initial.start)
const end = ref(initial.end)
const filterSeg = ref(null)
const splits = ref([])
const page = ref(1)
const totalPages = ref(1)
const total = ref(0)
const loading = ref(false)
// Page 1 loading over rows already shown (a filter change), not "Load more"
const refreshing = ref(false)
const searchTotal = ref(null)
const hasMore = computed(() => page.value < totalPages.value)
const filtered = computed(() => filter.value !== 'all' || Boolean(category.value || start.value))

const query = computed(() => {
  const parts = []
  if (search.value.trim()) parts.push(search.value.trim())
  if (category.value) parts.push(`category_is:"${category.value.replace(/"/g, '\\"')}"`)
  if (start.value) parts.push(`date_after:${start.value}`)
  if (end.value) parts.push(`date_before:${end.value}`)
  if (filter.value === 'uncategorized') parts.push('has_no_category:true type:withdrawal')
  else if (parts.length && filter.value !== 'all') parts.push(`type:${filter.value}`)
  return parts.join(' ')
})

const rangeLabel = computed(() => {
  if (!start.value) return ''
  const from = new Date(`${start.value}T12:00:00`)
  const to = new Date(`${end.value || start.value}T12:00:00`)
  if (start.value.endsWith('-01-01') && end.value.endsWith('-12-31') && start.value.slice(0, 4) === end.value.slice(0, 4)) return start.value.slice(0, 4)
  if (from.getDate() === 1 && from.getMonth() === to.getMonth() && new Date(to.getFullYear(), to.getMonth() + 1, 0).getDate() === to.getDate()) {
    return new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' }).format(from)
  }
  return end.value ? `${shortDate(start.value)} – ${shortDate(end.value)}` : `From ${shortDate(start.value)}`
})
const clearDates = () => {
  start.value = ''
  end.value = ''
}

let requestId = 0
async function loadPage(number) {
  const id = ++requestId
  loading.value = true
  refreshing.value = number === 1
  try {
    const json = query.value
      ? await get('search/transactions', { query: query.value, page: number, limit: PAGE_SIZE })
      : await get('transactions', { type: filter.value === 'all' ? 'all' : filter.value, page: number, limit: PAGE_SIZE })
    if (id !== requestId) return
    const rows = flatten(json.data ?? []).filter((split) => split.type !== 'opening balance' || filter.value === 'all')
    splits.value = number === 1 ? rows : [...splits.value, ...rows]
    page.value = number
    totalPages.value = json.meta?.pagination?.total_pages ?? 1
    total.value = json.meta?.pagination?.total ?? rows.length
  } finally {
    if (id === requestId) loading.value = refreshing.value = false
  }
}

// Its own counter: an older, slower total must not overwrite the one for the current search
let totalId = 0
async function loadTotal() {
  const id = ++totalId
  searchTotal.value = null
  if (!search.value.trim() && !category.value && !start.value) return
  const json = await get('search/transactions/total', { query: query.value }, { quiet: true }).catch(() => null)
  if (id !== totalId) return
  // Pocket sums per currency, spending positive; show the EUR part as money in or out
  const eur = (json?.data?.totals ?? []).find((item) => item.currency_code === 'EUR')
  searchTotal.value = eur ? -eur.amount : null
}

const reload = () => {
  loadPage(1)
  loadTotal()
}

const toQuery = () => ({
  ...(search.value ? { q: search.value } : {}),
  ...(filter.value !== 'all' ? { filter: filter.value } : {}),
  ...(category.value ? { category: category.value } : {}),
  ...(start.value ? { start: start.value, ...(end.value ? { end: end.value } : {}) } : {}),
})
const sameState = (a, b) => JSON.stringify(a) === JSON.stringify(b)

let debounce
let replaced = null // the query we last put in the URL ourselves
watch([search, filter, category, start, end], ([text], [oldText]) => {
  clearTimeout(debounce)
  // Only typing waits; a tapped filter loads at once
  debounce = setTimeout(
    () => {
      replaced = toQuery()
      router.replace({ query: replaced })
      reload()
    },
    text !== oldText ? 300 : 0,
  )
})
// The same page is kept when only the query changes (the Activity tab, a link from Home): take the URL's state.
// Our own router.replace above lands here too, and matches what is already shown
watch(
  () => route.query,
  (query) => {
    if (route.name !== 'transactions') return
    const next = fromQuery(query)
    // Ours, possibly older than what was typed since: nothing to take
    if (replaced && sameState(next, fromQuery(replaced))) return
    if (sameState(next, { search: search.value, filter: filter.value, category: category.value, start: start.value, end: end.value })) return
    clearTimeout(debounce)
    search.value = next.search
    filter.value = next.filter
    category.value = next.category
    start.value = next.start
    end.value = next.end
  },
)
watch(() => composer.version, reload)
// On a phone the filters scroll sideways: keep the chosen one in view ("Uncategorized", not "Unc")
const showFilter = () => nextTick(() => filterSeg.value?.querySelector('.on')?.scrollIntoView({ block: 'nearest', inline: 'nearest' }))
watch(filter, showFilter)

onMounted(() => {
  load('accounts')
  load('rates')
  reload()
  if (filter.value !== 'all') showFilter()
})
</script>

<style>
.active-filters {
  gap: 8px;
  margin: -4px 2px 12px;
}
.filter-chip {
  font-size: 13px;
  padding: 5px 10px;
  cursor: pointer;
}
.total-line {
  margin: -4px 6px 12px;
}
</style>
