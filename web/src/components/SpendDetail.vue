<template>
  <div class="spend-head">
    <div class="hero-label">Spent in {{ periodName }}</div>
    <div class="hero-value blurable">{{ money(amount) }}</div>
    <slot name="standing" />
    <div class="small muted">{{ countLabel }}<template v-if="count"> · <span class="blurable">{{ money(amount / count) }}</span> on average</template></div>
  </div>

  <p v-if="count === 0" class="small muted spend-none">Nothing spent in {{ periodName }}.</p>
  <button v-else class="btn primary block" @click="showAll">
    <Icon name="list" :size="18" /> {{ count === 1 ? 'Show the transaction' : `Show all ${count ?? ''} transactions` }}
  </button>
  <div class="row spend-actions"><slot name="actions" /></div>

  <template v-if="count !== 0">
    <h3 class="section-title">Largest in {{ periodName }}</h3>
    <div v-if="rows === null && failed" class="banner bad"><Icon name="alert" :size="18" /> Could not load the transactions.</div>
    <div v-else-if="rows === null" class="loading"><span class="spinner" /></div>
    <div v-else class="card flush">
      <TransactionRow v-for="split in largest" :key="split.transaction_journal_id" :split="split" show-date />
    </div>
  </template>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Icon from './Icon.vue'
import TransactionRow from './TransactionRow.vue'
import { get } from '../lib/api.js'
import { flatten } from '../lib/data.js'
import { money } from '../lib/format.js'
import { searchQuery, toRouteQuery } from '../lib/search.js'

// The spending behind one row of a period (a category, a budget), shown inside its sheet
const props = defineProps({
  state: { type: Object, required: true }, // the Transactions page state that lists these transactions (lib/search.js)
  amount: { type: Number, required: true },
  periodName: { type: String, required: true },
  version: { type: Number, default: 0 }, // bumped after a save or delete: read again
})
const emit = defineEmits(['close'])
const router = useRouter()
const rows = ref(null)
const failed = ref(false)

const query = computed(() => searchQuery(props.state))
const count = computed(() => rows.value?.length ?? null)
const countLabel = computed(() => {
  if (count.value === null) return failed.value ? '' : '…'
  return `${count.value} ${count.value === 1 ? 'transaction' : 'transactions'}`
})
const largest = computed(() => [...(rows.value ?? [])].sort((a, b) => Number(b.amount) - Number(a.amount)).slice(0, 5))

watch(
  [query, () => props.version],
  async ([text], [oldText], onCleanup) => {
    let stale = false
    onCleanup(() => (stale = true))
    failed.value = false
    // A refresh keeps the rows on screen until the new ones are in
    if (text !== oldText) rows.value = null

    // Firefly's search pages by date; to find the largest, read the period's matches and sort (a month or a year of one name is small)
    const found = []
    let page = 1
    let totalPages = 1
    try {
      do {
        const json = await get('search/transactions', { query: text, limit: 100, page })
        if (stale) return
        found.push(...flatten(json.data ?? []))
        totalPages = Math.min(json.meta?.pagination?.total_pages ?? 1, 5)
        page++
      } while (page <= totalPages)
      rows.value = found
    } catch {
      // The error toast comes from api()
      if (!stale) failed.value = true
    }
  },
  { immediate: true },
)

function showAll() {
  const target = { path: '/transactions', query: toRouteQuery(props.state) }
  emit('close')
  router.push(target)
}
</script>

<style>
.spend-head {
  padding: 4px 4px 16px;
}
.spend-none {
  margin: 0 4px;
}
.spend-actions {
  margin-top: 10px;
  gap: 8px;
}
</style>
