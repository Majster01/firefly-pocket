<template>
  <div>
    <PageHeader title="Suggestions" eyebrow="Categories for new transactions">
      <button class="btn icon small" aria-label="Refresh" @click="reload"><Icon name="refresh" :size="18" /></button>
    </PageHeader>

    <div v-if="!items" class="loading"><span class="spinner" /></div>
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
        <h3 class="suggestion-title">{{ item.attributes.company ?? triggerSummary(item) }}</h3>
        <p class="small muted">{{ item.attributes.reason }}</p>
        <div class="row small">
          <span class="grow muted">{{ count(item) }} · {{ money(total(item)) }}</span>
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
import Confidence from '../components/Confidence.vue'
import { get } from '../lib/api.js'
import { money } from '../lib/format.js'
import { badges } from '../lib/badges.js'

const items = ref(null)

const count = (item) => {
  const length = item.attributes.transactions?.length ?? 0
  return `${length} ${length === 1 ? 'transaction' : 'transactions'}`
}
const total = (item) => (item.attributes.transactions ?? []).reduce((sum, transaction) => sum + Number(transaction.amount ?? 0), 0)
const triggerSummary = (item) => item.attributes.triggers?.find((trigger) => trigger.type === 'description_contains')?.value ?? item.attributes.title

async function reload() {
  items.value = null
  items.value = ((await get('rule-suggestions'))?.data ?? []).sort((a, b) => Number(a.attributes.low_confidence) - Number(b.attributes.low_confidence) || b.attributes.confidence - a.attributes.confidence)
  badges.suggestions = items.value.length
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
