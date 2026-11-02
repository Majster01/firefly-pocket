<template>
  <div class="card calendar">
    <h2 class="card-title">
      <span>Calendar</span>
      <span class="tiny">{{ periodName }}</span>
    </h2>
    <div v-if="!days" class="skeleton" style="height: 220px" />
    <template v-else>
      <div class="cal-grid" role="grid" :aria-label="`In and out per day, ${periodName}`">
        <span v-for="name in weekdays" :key="name" class="cal-head tiny muted" aria-hidden="true">{{ name }}</span>
        <span v-for="n in lead" :key="`lead-${n}`" class="cal-gap" aria-hidden="true" />
        <button v-for="day in days" :key="day.date" class="cal-day" :class="{ today: day.date === today, future: day.date > today, empty: !day.in && !day.out }" :aria-label="label(day)" :disabled="!day.in && !day.out" @click="open(day.date)">
          <span class="cal-num">{{ day.number }}</span>
          <span class="cal-in num pos blurable">{{ day.in ? short(day.in) : '' }}</span>
          <span class="cal-out num blurable">{{ day.out ? short(day.out) : '' }}</span>
        </button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { getAll } from '../../../lib/api.js'
import { PRIMARY, flatten, isBookkeeping } from '../../../lib/data.js'
import { hideDigits, isoDate, money } from '../../../lib/format.js'
import { periodLabel } from '../../../lib/home.js'

// Monthly view only (see TILES.calendar.view): one cell per day of the month shown, with what came in and went out
const props = defineProps({ view: String, offset: Number, range: Object, options: Object, version: Number })
const router = useRouter()
const days = ref(null)
const today = isoDate()
const periodName = computed(() => periodLabel('month', props.offset))

// Monday first
const weekdays = Array.from({ length: 7 }, (_, index) => new Intl.DateTimeFormat(undefined, { weekday: 'narrow' }).format(new Date(2024, 0, 1 + index, 12)))
const lead = computed(() => (new Date(`${props.range.start}T12:00:00`).getDay() + 6) % 7)

const compact = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 })
const short = (value) => hideDigits(value >= 1000 ? compact.format(value) : String(Math.round(value)))
const label = (day) => `${day.date}: in ${money(day.in)}, out ${money(day.out)}`

watch(
  [() => props.range.start, () => props.version],
  async (_, __, onCleanup) => {
    let stale = false
    onCleanup(() => (stale = true))
    days.value = null
    let splits = []
    try {
      splits = flatten(await getAll('transactions', { type: 'all', start: props.range.start, end: props.range.end }))
    } catch {
      // Shown as a month without transactions
    }
    if (stale) return
    const last = Number(props.range.end.slice(8))
    const list = Array.from({ length: last }, (_, index) => ({ number: index + 1, date: `${props.range.start.slice(0, 8)}${String(index + 1).padStart(2, '0')}`, in: 0, out: 0 }))
    for (const split of splits) {
      if (isBookkeeping(split) || split.currency_code !== PRIMARY || !['deposit', 'withdrawal'].includes(split.type)) continue
      const day = list[Number(String(split.date).slice(8, 10)) - 1]
      if (!day) continue
      day[split.type === 'deposit' ? 'in' : 'out'] += Math.abs(Number(split.amount))
    }
    days.value = list
  },
  { immediate: true },
)

const open = (date) => router.push({ path: '/transactions', query: { start: date, end: date } })
</script>

<style>
.calendar {
  container-type: inline-size;
}
.cal-grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 3px;
}
.cal-head {
  text-align: center;
}
.cal-day {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 1px;
  min-height: 52px;
  padding: 3px 4px;
  border: 0;
  border-radius: 8px;
  background: var(--faint);
  color: inherit;
  font-size: 10px;
  line-height: 1.2;
  overflow: hidden;
}
.cal-day.empty {
  opacity: 0.55;
}
.cal-day.future {
  opacity: 0.35;
}
.cal-day.today {
  outline: 2px solid var(--income);
}
button.cal-day:not(:disabled):hover {
  background: var(--track);
}
.cal-num {
  align-self: flex-start;
  font-size: 11px;
  font-weight: 600;
}
.cal-in {
  color: var(--income);
}
.cal-out {
  color: var(--bad);
}
</style>
