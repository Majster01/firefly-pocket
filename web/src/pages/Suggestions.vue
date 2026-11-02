<template>
  <div>
    <PageHeader title="Suggestions" eyebrow="Categories for new transactions">
      <RefreshButton @click="reload" />
    </PageHeader>

    <div v-if="loadError" class="card empty">
      <h3>Could not load the suggestions</h3>
      <p>{{ loadError }}</p>
      <button class="btn small" @click="reload">Retry</button>
    </div>
    <div v-else-if="!items" class="loading"><span class="spinner" /></div>
    <div v-else-if="!items.length" class="card empty">
      <h3>All caught up</h3>
      <p>The suggester proposes categories after each bank import. Approved ones become rules in Categorization.</p>
    </div>
    <div v-else class="suggestion-grid">
      <RouterLink v-for="item in items" :key="item.id" :to="`/suggestions/${item.id}`" class="card suggestion-card">
        <div class="row">
          <span class="chip">{{ item.attributes.category }}</span>
          <Confidence :value="item.attributes.confidence" :low="item.attributes.low_confidence" />
        </div>
        <h3 class="suggestion-title">{{ item.attributes.company ?? fallbackName(item) }}</h3>
        <p class="small muted">{{ item.attributes.reason }}</p>
        <div class="row small">
          <span class="grow muted">{{ count(item) }} · <span class="blurable">{{ money(total(item), item.attributes.transactions?.[0]?.currency_code) }}</span></span>
          <Icon name="chevron" class="chev" :size="18" />
        </div>
      </RouterLink>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import RefreshButton from '../components/RefreshButton.vue'
import Confidence from '../components/Confidence.vue'
import { get } from '../lib/api.js'
import { money } from '../lib/format.js'
import { badges } from '../lib/badges.js'

const items = ref(null)
const loadError = ref('')

const count = (item) => {
  const length = item.attributes.transactions?.length ?? 0
  return `${length} ${length === 1 ? 'transaction' : 'transactions'}`
}
// Signed like the review page and the transaction lists: money spent is negative
const signed = (transaction) => (transaction.type === 'deposit' ? 1 : -1) * Number(transaction.amount ?? 0)
const total = (item) => (item.attributes.transactions ?? []).reduce((sum, transaction) => sum + signed(transaction), 0)

// Without a company (a person, an unknown shop) the suggester's trigger is lowercased: show the bank's own words instead
const titleCase = (text) =>
  text === text.toUpperCase() || text === text.toLowerCase()
    ? text.replace(/\S+/g, (word) => (/\d/.test(word) ? word.toUpperCase() : word[0].toUpperCase() + word.slice(1).toLowerCase()))
    : text
const fallbackName = (item) => {
  const text = item.attributes.transactions?.[0]?.description ?? item.attributes.triggers?.find((trigger) => trigger.type === 'description_contains')?.value ?? item.attributes.title
  return titleCase(text)
}

async function reload() {
  items.value = null
  loadError.value = ''
  try {
    items.value = ((await get('rule-suggestions', undefined, { quiet: true }))?.data ?? []).sort((a, b) => Number(a.attributes.low_confidence) - Number(b.attributes.low_confidence) || b.attributes.confidence - a.attributes.confidence)
    badges.suggestions = items.value.length
  } catch (error) {
    loadError.value = error.message
  }
}

onMounted(reload)
</script>

<style>
.suggestion-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr));
  gap: 12px;
}
.suggestion-grid .card + .card {
  margin-top: 0;
}
.suggestion-card {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.suggestion-card p {
  margin: 0;
  flex: 1;
}
.suggestion-title {
  font-size: 17px;
}
</style>
