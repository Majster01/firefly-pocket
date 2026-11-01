<template>
  <div class="card">
    <h2 class="card-title">
      <span>Spending by category</span>
      <span class="tiny">{{ periodName }}</span>
    </h2>
    <div v-if="!rows" class="skeleton" style="height: 160px" />
    <p v-else-if="!rows.length" class="muted small">Nothing spent in {{ periodName }}.</p>
    <DonutChart v-else-if="options.chart === 'donut'" :items="rows" :label="`Spending by category, ${periodName}`" :format="(value) => money(value, 'EUR', { decimals: 0 })" @select="select" />
    <ul v-else class="list cat-bars">
      <li v-for="row in shown" :key="row.name">
        <button class="cat-bar" @click="select(row)">
          <span class="row">
            <span class="grow ellipsis">{{ row.name }}</span>
            <span class="num blurable">{{ money(row.amount) }}</span>
            <span class="num muted tiny share">{{ Math.round(row.share * 100) }}%</span>
          </span>
          <span class="track"><i class="ink" :class="{ warn: row.uncategorized }" :style="{ width: `${(row.amount / rows[0].amount) * 100}%` }" /></span>
        </button>
      </li>
      <li v-if="rows.length > shown.length">
        <button class="cat-bar more-cats" @click="select({ name: 'Other', others: rows.slice(shown.length), amount: rows.slice(shown.length).reduce((sum, row) => sum + row.amount, 0) })">
          {{ rows.length - shown.length }} more categories · <span class="num blurable">{{ money(rows.slice(shown.length).reduce((sum, row) => sum + row.amount, 0)) }}</span>
        </button>
      </li>
    </ul>
    <CategorySheet :category="picked" :range="range" :period-name="periodName" @close="picked = null" @pick="select" />
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import DonutChart from '../DonutChart.vue'
import CategorySheet from '../CategorySheet.vue'
import { get } from '../../../lib/api.js'
import { insightTotal } from '../../../lib/data.js'
import { money } from '../../../lib/format.js'
import { periodLabel } from '../../../lib/home.js'

const props = defineProps({ view: String, offset: Number, range: Object, options: Object, version: Number })
const rows = ref(null)
const picked = ref(null)

const periodName = computed(() => periodLabel(props.view, props.offset))
const shown = computed(() => (Number(props.options.limit) ? rows.value.slice(0, Number(props.options.limit)) : rows.value))

watch(
  [() => props.range.start, () => props.view, () => props.version, () => props.options.uncategorized],
  async (_, __, onCleanup) => {
    let stale = false
    onCleanup(() => (stale = true))
    rows.value = null
    const [byCategory, none] = await Promise.all([get('insight/expense/category', props.range), get('insight/expense/no-category', props.range).catch(() => [])])
    if (stale) return
    const list = (byCategory ?? []).filter((row) => row.currency_code === 'EUR').map((row) => ({ id: row.id, name: row.name, amount: Math.abs(Number(row.difference_float)) }))
    const uncategorized = insightTotal(none)
    if (props.options.uncategorized && uncategorized > 0) list.push({ name: 'Uncategorized', amount: uncategorized, uncategorized: true })
    const total = list.reduce((sum, row) => sum + row.amount, 0) || 1
    rows.value = list.filter((row) => row.amount > 0).sort((a, b) => b.amount - a.amount).map((row) => ({ ...row, share: row.amount / total }))
  },
  { immediate: true },
)

const select = (row) => (picked.value = row)
</script>

<style>
.cat-bars li + li {
  margin-top: 2px;
}
.cat-bar {
  display: block;
  width: 100%;
  padding: 7px 8px;
  margin: 0 -8px;
  width: calc(100% + 16px);
  border: 0;
  border-radius: 12px;
  background: transparent;
  text-align: left;
}
.cat-bar:hover {
  background: var(--faint);
}
.cat-bar .track {
  display: block;
  margin-top: 6px;
  height: 6px;
}
.cat-bar .share {
  width: 34px;
  text-align: right;
}
.more-cats {
  font-size: 13px;
  font-weight: 600;
  color: var(--muted);
}
</style>
