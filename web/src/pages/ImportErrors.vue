<template>
  <div>
    <PageHeader title="Import errors" eyebrow="System status" back="/system-status">
      <RefreshButton @click="reload" />
    </PageHeader>

    <div class="toolbar">
      <label class="search grow">
        <Icon name="search" :size="18" />
        <input v-model="search" class="input" type="search" placeholder="Search the error text" aria-label="Search errors" />
      </label>
      <div class="seg filter-seg" role="group" aria-label="Show">
        <button v-for="option in SOURCES" :key="option.value" :aria-pressed="source === option.value" :class="{ on: source === option.value }" @click="source = option.value">{{ option.label }}</button>
      </div>
    </div>

    <div class="toolbar">
      <div v-if="banks.length" ref="bankSeg" class="seg filter-seg" role="group" aria-label="Bank connection">
        <button :aria-pressed="!connection" :class="{ on: !connection }" @click="connection = ''">All banks</button>
        <button v-for="bank in banks" :key="bank.name" :aria-pressed="connection === bank.name" :class="{ on: connection === bank.name }" @click="connection = bank.name">{{ bank.bank ?? bank.name }}</button>
      </div>
    </div>

    <div class="row error-dates">
      <label class="field grow"><span>From</span><input v-model="start" type="date" :max="end || today" aria-label="From" /></label>
      <label class="field grow"><span>To</span><input v-model="end" type="date" :min="start" :max="today" aria-label="To" /></label>
    </div>

    <div v-if="error" class="banner bad error-text"><Icon name="alert" :size="18" /> <span>Unavailable: {{ error }}</span></div>
    <div v-else-if="loading && !entries.length" class="loading"><span class="spinner" /></div>
    <div v-else-if="!entries.length" class="empty card">
      <h3>No errors</h3>
      <p>{{ search ? 'No error matches your search.' : 'No import error in these days.' }}</p>
    </div>
    <!-- The old rows stay while new ones load, dimmed, so a filter change is visibly working -->
    <div v-else class="feed-wrap" :class="{ refreshing }" :aria-busy="refreshing">
      <span v-if="refreshing" class="spinner feed-spinner" />
      <section v-for="day in days" :key="day.date" class="day">
        <div class="day-head">
          <span>{{ dayLabel(day.date) }}</span>
          <span :class="{ bad: day.errors }">{{ plural(day.errors, 'error') }}</span>
        </div>
        <div class="card flush">
          <div v-for="entry in day.entries" :key="entry.id" class="item error-entry">
            <span class="avatar">{{ initials(entry.bank ?? entry.connection ?? '?') }}</span>
            <span class="grow">
              <span class="item-title" :class="{ muted: entry.message === null }">{{ entry.message ?? `${plural(entry.count, 'more error')}, not kept` }}</span>
              <span class="item-sub error-sub">{{ entry.bank ?? entry.connection ?? 'Unknown bank' }} · {{ entry.source === 'manual' ? 'Import now' : 'Nightly' }} · {{ entry.at.slice(11, 16) }}</span>
            </span>
            <span v-if="entry.message !== null && entry.count > 1" class="chip error-count" :title="`${entry.count} times in the same import`">×{{ entry.count }}</span>
          </div>
        </div>
      </section>
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
import { get } from '../lib/api.js'
import { dayLabel, initials, isoDate } from '../lib/format.js'

const SOURCES = [
  { value: 'all', label: 'All' },
  { value: 'nightly', label: 'Nightly' },
  { value: 'manual', label: 'Import now' },
]
const PAGE_SIZE = 50

const route = useRoute()
const router = useRouter()
const today = isoDate()
const isDay = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value ?? '')
// The page's state as the URL has it; a link from Bank connections brings the day and the bank
const fromQuery = (query) => ({
  search: typeof query.q === 'string' ? query.q : '',
  source: SOURCES.some((option) => option.value === query.filter) ? query.filter : 'all',
  connection: typeof query.connection === 'string' ? query.connection : '',
  start: isDay(query.start) ? query.start : '',
  end: isDay(query.end) ? query.end : '',
})
const initial = fromQuery(route.query)
const search = ref(initial.search)
const source = ref(initial.source)
const connection = ref(initial.connection)
const start = ref(initial.start)
const end = ref(initial.end)
const bankSeg = ref(null)
const entries = ref([])
// Kept while a bank is chosen, so the filter can be changed back
const banks = ref([])
const page = ref(1)
const hasMore = ref(false)
const loading = ref(false)
// Page 1 loading over rows already shown (a filter change), not "Load more"
const refreshing = ref(false)
const error = ref('')

const plural = (count, word) => `${count} ${count === 1 ? word : `${word}s`}`
const days = computed(() => {
  const groups = new Map()
  for (const entry of entries.value) {
    const date = entry.at.slice(0, 10)
    if (!groups.has(date)) groups.set(date, { date, entries: [], errors: 0 })
    const group = groups.get(date)
    group.entries.push(entry)
    group.errors += entry.count
  }
  return [...groups.values()]
})

let requestId = 0
async function loadPage(number) {
  const id = ++requestId
  loading.value = true
  refreshing.value = number === 1
  try {
    const json = await get(
      'system-status/errors',
      { from: start.value, to: end.value, connection: connection.value, source: source.value === 'all' ? '' : source.value, q: search.value.trim(), page: number, limit: PAGE_SIZE },
      { quiet: true },
    )
    if (id !== requestId) return
    const result = json.data
    entries.value = number === 1 ? result.items : [...entries.value, ...result.items]
    // A bank named in the link that has no error in these days is still shown as the chosen one
    banks.value = connection.value && !result.connections.some((bank) => bank.name === connection.value) ? [...result.connections, { name: connection.value, bank: null }] : result.connections
    page.value = number
    hasMore.value = result.has_more
    error.value = ''
  } catch (caught) {
    if (id === requestId) error.value = caught.message
  } finally {
    if (id === requestId) loading.value = refreshing.value = false
  }
}

const reload = () => loadPage(1)

const toQuery = () => ({
  ...(search.value ? { q: search.value } : {}),
  ...(source.value !== 'all' ? { filter: source.value } : {}),
  ...(connection.value ? { connection: connection.value } : {}),
  ...(start.value ? { start: start.value } : {}),
  ...(end.value ? { end: end.value } : {}),
})
const sameState = (a, b) => JSON.stringify(a) === JSON.stringify(b)

let debounce
let replaced = null // the query we last put in the URL ourselves
watch([search, source, connection, start, end], ([text], [oldText]) => {
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
// The page is kept when only the query changes (a link from Bank connections while this page is open): take the URL's state
watch(
  () => route.query,
  (query) => {
    if (route.name !== 'import-errors') return
    const next = fromQuery(query)
    if (replaced && sameState(next, fromQuery(replaced))) return
    if (sameState(next, { search: search.value, source: source.value, connection: connection.value, start: start.value, end: end.value })) return
    clearTimeout(debounce)
    search.value = next.search
    source.value = next.source
    connection.value = next.connection
    start.value = next.start
    end.value = next.end
  },
)
// On a phone the banks scroll sideways: keep the chosen one in view
watch(connection, () => nextTick(() => bankSeg.value?.querySelector('.on')?.scrollIntoView({ block: 'nearest', inline: 'nearest' })))

onMounted(reload)
</script>

<style>
/* Importer errors carry URLs and ids without spaces: break them anywhere rather than widen the page */
.error-text {
  overflow-wrap: anywhere;
}
.error-dates {
  gap: 10px;
  margin-bottom: 6px;
  align-items: flex-end;
}
.error-sub {
  display: block;
}
.error-count {
  flex: none;
  padding: 0 6px;
  font-size: 11px;
}
</style>
