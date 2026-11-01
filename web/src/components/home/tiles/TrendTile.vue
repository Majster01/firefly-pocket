<template>
  <div class="card">
    <h2 class="card-title">
      <span>{{ options.metric === 'income' ? 'Income' : 'Spending' }} {{ view === 'year' ? `in ${periodName}` : 'per month' }}</span>
    </h2>
    <div v-if="!items" class="skeleton" style="height: 170px" />
    <p v-else-if="items.every((item) => !item.value)" class="muted small">
      Nothing {{ options.metric === 'income' ? 'received' : 'spent' }} in {{ view === 'year' ? periodName : `${periodName} or the five months before` }}.
    </p>
    <BarChart v-else :items="items" :label="`${options.metric === 'income' ? 'Income' : 'Spending'} per month, in euro`" :format="(value) => money(value, 'EUR', { decimals: 0 })" />
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import BarChart from '../../BarChart.vue'
import { get } from '../../../lib/api.js'
import { insightTotal } from '../../../lib/data.js'
import { isoDate, money, monthRange } from '../../../lib/format.js'
import { periodLabel } from '../../../lib/home.js'

// Monthly view: the six months up to the one shown. Yearly view: the twelve months of the year shown.
const props = defineProps({ view: String, offset: Number, range: Object, options: Object, version: Number })
const items = ref(null)
const periodName = computed(() => periodLabel(props.view, props.offset))
const shortMonth = new Intl.DateTimeFormat(undefined, { month: 'short' })
const longMonth = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' })

// January in the monthly view and the year in the yearly one start on the same day: the view is part of the key
watch(
  [() => props.range.start, () => props.view, () => props.version, () => props.options.metric],
  async (_, __, onCleanup) => {
    let stale = false
    onCleanup(() => (stale = true))
    items.value = null
    const anchor = new Date(`${props.range.start}T12:00:00`)
    const months = props.view === 'year' ? Array.from({ length: 12 }, (_, index) => index - anchor.getMonth()) : [-5, -4, -3, -2, -1, 0]
    const today = isoDate()
    const path = props.options.metric === 'income' ? 'insight/income/total' : 'insight/expense/total'
    const totals = await Promise.all(months.map((offset) => (monthRange(offset, anchor).start > today ? [] : get(path, monthRange(offset, anchor)).catch(() => []))))
    if (stale) return
    items.value = months.map((offset, index) => {
      const range = monthRange(offset, anchor)
      const date = new Date(`${range.start}T12:00:00`)
      const running = range.start <= today && range.end >= today
      const short = shortMonth.format(date).replace('.', '').slice(0, 3)
      return {
        label: longMonth.format(date),
        short,
        // Twelve columns in a narrow tile leave room for one letter
        tiny: props.view === 'year' ? short.slice(0, 1) : undefined,
        value: insightTotal(totals[index]),
        current: props.view === 'year' ? running : offset === 0,
        note: running ? 'so far' : range.start > today ? 'not yet' : '',
      }
    })
  },
  { immediate: true },
)
</script>
