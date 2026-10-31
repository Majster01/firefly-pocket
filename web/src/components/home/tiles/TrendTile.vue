<template>
  <div class="card">
    <h2 class="card-title">
      <span>{{ options.metric === 'income' ? 'Income' : 'Spending' }} {{ view === 'year' ? `in ${periodName}` : 'per month' }}</span>
    </h2>
    <BarChart v-if="items.length" :items="items" :label="`${options.metric === 'income' ? 'Income' : 'Spending'} per month, in euro`" :format="(value) => money(value, 'EUR', { decimals: 0 })" />
    <div v-else class="skeleton" style="height: 170px" />
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import BarChart from '../../BarChart.vue'
import { cachedGet, insightTotal } from '../../../lib/data.js'
import { isoDate, money, monthRange } from '../../../lib/format.js'
import { periodLabel } from '../../../lib/home.js'

// Monthly view: the six months up to the one shown. Yearly view: the twelve months of the year shown.
const props = defineProps({ view: String, offset: Number, range: Object, options: Object, version: Number })
const items = ref([])
const periodName = computed(() => periodLabel(props.view, props.offset))
const shortMonth = new Intl.DateTimeFormat(undefined, { month: 'short' })
const longMonth = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' })

watch(
  () => [props.range.start, props.version, props.options.metric],
  async () => {
    items.value = []
    const anchor = new Date(`${props.range.start}T12:00:00`)
    const months = props.view === 'year' ? Array.from({ length: 12 }, (_, index) => index - anchor.getMonth()) : [-5, -4, -3, -2, -1, 0]
    const today = isoDate()
    const path = props.options.metric === 'income' ? 'insight/income/total' : 'insight/expense/total'
    const totals = await Promise.all(months.map((offset) => (monthRange(offset, anchor).start > today ? [] : cachedGet(path, monthRange(offset, anchor)).catch(() => []))))
    items.value = months.map((offset, index) => {
      const range = monthRange(offset, anchor)
      const date = new Date(`${range.start}T12:00:00`)
      const running = range.start <= today && range.end >= today
      return {
        label: longMonth.format(date),
        short: props.view === 'year' ? shortMonth.format(date).slice(0, 1) + shortMonth.format(date).slice(1, 3) : shortMonth.format(date),
        value: insightTotal(totals[index]),
        current: props.view === 'year' ? running : offset === 0,
        note: running ? 'so far' : range.start > today ? 'not yet' : '',
      }
    })
  },
  { immediate: true },
)
</script>
