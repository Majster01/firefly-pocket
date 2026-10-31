<template>
  <div class="card cashflow">
    <h2 class="card-title">
      <span>In · Out · Surplus</span>
      <span class="tiny">{{ periodName }}</span>
    </h2>
    <div v-if="!data" class="skeleton" style="height: 120px" />
    <template v-else>
      <div class="flow-figures">
        <button class="flow" @click="open('deposit')">
          <span class="kpi-label"><Icon name="arrowDown" :size="14" /> In</span>
          <span class="flow-value num pos blurable">{{ money(data.in) }}</span>
          <span v-if="options.compare && data.previous" class="tiny muted">{{ change(data.in, data.previous.in) }}</span>
        </button>
        <button class="flow" @click="open('withdrawal')">
          <span class="kpi-label"><Icon name="arrowUp" :size="14" /> Out</span>
          <span class="flow-value num blurable">{{ money(data.out) }}</span>
          <span v-if="options.compare && data.previous" class="tiny muted">{{ change(data.out, data.previous.out) }}</span>
        </button>
        <div class="flow surplus">
          <span class="kpi-label">{{ surplus >= 0 ? 'Surplus' : 'Deficit' }}</span>
          <span class="flow-value num blurable" :class="surplus >= 0 ? 'pos' : 'bad'">{{ money(surplus, 'EUR', { signed: true }) }}</span>
          <span class="tiny muted">{{ keptText }}</span>
        </div>
      </div>
      <div class="flow-bars" aria-hidden="true">
        <div class="flow-bar"><i class="in" :style="{ width: `${(data.in / scale) * 100}%` }" /></div>
        <div class="flow-bar"><i class="out" :style="{ width: `${(data.out / scale) * 100}%` }" /></div>
      </div>
      <p v-if="options.compare && data.previous" class="tiny muted flow-note">Compared with {{ previousName }}{{ running ? ', which is over; this one is still running' : '' }}. Transfers between your own accounts (savings, VWCE buys) are neither in nor out.</p>
    </template>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../../Icon.vue'
import { cachedGet, insightTotal } from '../../../lib/data.js'
import { isoDate, money } from '../../../lib/format.js'
import { periodLabel, periodRange } from '../../../lib/home.js'

const props = defineProps({ view: String, offset: Number, range: Object, options: Object, version: Number })
const router = useRouter()
const data = ref(null)

const surplus = computed(() => data.value.in - data.value.out)
const keptText = computed(() => {
  if (!data.value.in) return data.value.out ? 'nothing came in' : ''
  const ratio = Math.round((Math.abs(surplus.value) / data.value.in) * 100)
  return surplus.value >= 0 ? `${ratio}% of income kept` : `${ratio}% more out than in`
})
const scale = computed(() => Math.max(data.value.in, data.value.out, 1))
const periodName = computed(() => periodLabel(props.view, props.offset))
const previousName = computed(() => periodLabel(props.view, props.offset - 1))
const running = computed(() => props.range.end >= isoDate() && props.range.start <= isoDate())

const change = (now, before) => {
  if (!before) return `nothing in ${previousName.value}`
  const ratio = now / before - 1
  return `${ratio >= 0 ? '+' : '−'}${Math.abs(Math.round(ratio * 100))}% vs ${periodLabel(props.view, props.offset - 1, { short: true })}`
}

async function totals(range) {
  const [income, expense] = await Promise.all([cachedGet('insight/income/total', range), cachedGet('insight/expense/total', range)])
  return { in: insightTotal(income), out: insightTotal(expense) }
}

watch(
  () => [props.range.start, props.version, props.options.compare],
  async () => {
    data.value = null
    const [current, previous] = await Promise.all([totals(props.range), props.options.compare ? totals(periodRange(props.view, props.offset - 1)) : null])
    data.value = { ...current, previous }
  },
  { immediate: true },
)

const open = (type) => router.push({ path: '/transactions', query: { filter: type, start: props.range.start, end: props.range.end } })
</script>

<style>
.flow-figures {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}
.flow {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  padding: 10px;
  margin: -10px 0 0 -10px;
  border: 0;
  border-radius: 14px;
  background: transparent;
  text-align: left;
  min-width: 0;
}
button.flow:hover {
  background: var(--faint);
}
.flow .kpi-label {
  display: inline-flex;
  align-items: center;
  gap: 3px;
}
.flow-value {
  font-size: 21px;
  font-weight: 700;
  letter-spacing: -0.01em;
  white-space: nowrap;
}
.flow-bars {
  display: grid;
  gap: 5px;
  margin-top: 8px;
}
.flow-bar {
  height: 8px;
  border-radius: 999px;
  background: var(--track);
  overflow: hidden;
}
.flow-bar i {
  display: block;
  height: 100%;
  border-radius: 999px;
}
.flow-bar .in {
  background: var(--income);
}
.flow-bar .out {
  background: var(--chart);
}
.flow-note {
  margin: 10px 0 0;
}
@media (max-width: 420px) {
  .flow-value {
    font-size: 18px;
  }
}
.size-third .flow-figures {
  grid-template-columns: 1fr 1fr;
}
.size-third .flow.surplus {
  grid-column: span 2;
}
</style>
