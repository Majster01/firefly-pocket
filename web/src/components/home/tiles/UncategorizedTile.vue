<template>
  <RouterLink :to="{ path: '/transactions', query: { filter: 'uncategorized', start: range.start, end: range.end } }" class="card uncat-tile">
    <span class="kpi-label">Uncategorized · {{ periodName }}</span>
    <span class="kpi-value" :class="{ warn: count > 0 }">{{ count ?? '…' }}</span>
    <span class="tiny muted">{{ count ? 'expenses without a category' : count === 0 ? 'all sorted' : '' }}</span>
  </RouterLink>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { get } from '../../../lib/api.js'
import { periodLabel, periodQuery } from '../../../lib/home.js'

const props = defineProps({ view: String, offset: Number, range: Object, version: Number })
const count = ref(null)
const periodName = computed(() => periodLabel(props.view, props.offset, { short: true }))

watch(
  [() => props.range.start, () => props.view, () => props.version],
  async (_, __, onCleanup) => {
    let stale = false
    onCleanup(() => (stale = true))
    count.value = null
    const json = await get('search/transactions', { query: `has_no_category:true type:withdrawal ${periodQuery(props.range)}`, limit: 1 })
    if (!stale) count.value = json.meta?.pagination?.total ?? 0
  },
  { immediate: true },
)
</script>

<style>
.uncat-tile {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.uncat-tile .kpi-value {
  font-size: 30px;
}
</style>
