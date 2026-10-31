<template>
  <div class="card flush">
    <h2 class="card-title">
      <span>{{ isCurrent ? 'Latest' : `Last in ${periodName}` }}</span>
      <RouterLink :to="{ path: '/transactions', query: isCurrent ? {} : { start: range.start, end: range.end } }" class="tiny">All</RouterLink>
    </h2>
    <div v-if="splits === null" class="loading"><span class="spinner" /></div>
    <p v-else-if="!splits.length" class="muted small empty-recent">No transactions in {{ periodName }}.</p>
    <div v-else>
      <TransactionRow v-for="split in splits" :key="split.transaction_journal_id" :split="split" show-date />
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import TransactionRow from '../../TransactionRow.vue'
import { get } from '../../../lib/api.js'
import { flatten } from '../../../lib/data.js'
import { isoDate } from '../../../lib/format.js'
import { periodLabel } from '../../../lib/home.js'

const props = defineProps({ view: String, offset: Number, range: Object, options: Object, version: Number })
const splits = ref(null)
const periodName = computed(() => periodLabel(props.view, props.offset))
const isCurrent = computed(() => props.range.end >= isoDate())

watch(
  () => [props.range.start, props.view, props.version, props.options.count],
  async () => {
    const count = Number(props.options.count)
    const json = await get('transactions', { type: 'all', limit: count + 2, start: props.range.start, end: props.range.end })
    splits.value = flatten(json.data ?? [])
      .filter((split) => split.type !== 'opening balance')
      .slice(0, count)
  },
  { immediate: true },
)
</script>

<style>
.empty-recent {
  padding: 0 16px 16px;
}
</style>
