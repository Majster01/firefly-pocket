<template>
  <Sheet :open="Boolean(category)" :title="category?.name ?? ''" cancel-label="Close" @close="$emit('close')">
    <template v-if="category">
      <!-- "Other" in the donut, or the rest of the bar list: pick one of the folded categories -->
      <template v-if="category.others">
        <p class="small muted sheet-lead">{{ category.others.length }} smaller categories · {{ money(category.amount) }} in {{ periodName }}</p>
        <div class="card flush">
          <button v-for="other in category.others" :key="other.name" class="item" @click="$emit('close'), pickLater(other)">
            <span class="grow item-title">{{ other.name }}</span>
            <span class="item-end num">{{ money(other.amount) }}</span>
            <Icon name="chevron" class="chev" :size="18" />
          </button>
        </div>
      </template>

      <template v-else>
        <div class="cat-head">
          <div class="hero-label">Spent in {{ periodName }}</div>
          <div class="hero-value">{{ money(category.amount) }}</div>
          <div class="small muted">{{ count === null ? '…' : `${count} ${count === 1 ? 'transaction' : 'transactions'}` }}<template v-if="count"> · {{ money(category.amount / count) }} on average</template></div>
        </div>

        <button class="btn primary block" @click="showAll">
          <Icon name="list" :size="18" /> {{ count === 1 ? 'Show the transaction' : `Show all ${count ?? ''} transactions` }}
        </button>
        <div class="row cat-actions">
          <RouterLink v-if="!category.uncategorized" class="btn small grow" :to="{ path: '/transactions', query: { category: category.name } }" @click="$emit('close')">All time</RouterLink>
          <RouterLink v-if="category.uncategorized && settings.features.ruleSuggestions" class="btn small grow" to="/suggestions" @click="$emit('close')">Suggestions</RouterLink>
          <RouterLink class="btn small grow" to="/categories" @click="$emit('close')">Categories</RouterLink>
        </div>

        <h3 class="section-title">Largest in {{ periodName }}</h3>
        <div v-if="largest === null" class="loading"><span class="spinner" /></div>
        <div v-else class="card flush">
          <TransactionRow v-for="split in largest" :key="split.transaction_journal_id" :split="split" show-date />
        </div>
      </template>
    </template>
  </Sheet>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Sheet from '../Sheet.vue'
import Icon from '../Icon.vue'
import TransactionRow from '../TransactionRow.vue'
import { get } from '../../lib/api.js'
import { flatten } from '../../lib/data.js'
import { money } from '../../lib/format.js'
import { periodQuery } from '../../lib/home.js'
import { settings } from '../../lib/settings.js'

const props = defineProps({ category: { type: Object, default: null }, range: { type: Object, required: true }, periodName: { type: String, default: '' } })
const emit = defineEmits(['close', 'pick'])
const router = useRouter()
const count = ref(null)
const largest = ref(null)

const categoryQuery = (category) => (category.uncategorized ? 'has_no_category:true type:withdrawal' : `category_is:"${category.name.replace(/"/g, '\\"')}" type:withdrawal`)

watch(
  () => props.category,
  async (category) => {
    count.value = null
    largest.value = null
    if (!category || category.others) return
    // Firefly's search pages by date; to find the largest, read the period's matches and sort (a month or a year of one category is small)
    const query = `${categoryQuery(category)} ${periodQuery(props.range)}`
    const rows = []
    let page = 1
    let totalPages = 1
    do {
      const json = await get('search/transactions', { query, limit: 100, page })
      rows.push(...flatten(json.data ?? []))
      totalPages = Math.min(json.meta?.pagination?.total_pages ?? 1, 5)
      page++
    } while (page <= totalPages)
    count.value = rows.length
    largest.value = rows.sort((a, b) => Number(b.amount) - Number(a.amount)).slice(0, 5)
  },
)

// Picking from "Other" closes this sheet and reopens it for that category
const pickLater = (other) => setTimeout(() => emit('pick', other), 300)

function showAll() {
  const { category, range } = props
  emit('close')
  router.push({ path: '/transactions', query: { ...(category.uncategorized ? { filter: 'uncategorized' } : { category: category.name, filter: 'withdrawal' }), start: range.start, end: range.end } })
}
</script>

<style>
.sheet-lead {
  margin: 0 4px 12px;
}
.cat-head {
  padding: 4px 4px 16px;
}
.cat-actions {
  margin-top: 10px;
  gap: 8px;
}
</style>
