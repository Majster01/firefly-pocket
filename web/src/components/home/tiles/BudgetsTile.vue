<template>
  <div class="card">
    <h2 class="card-title">
      <span>Budgets · {{ periodName }}</span>
      <RouterLink to="/budgets" class="tiny">Details</RouterLink>
    </h2>
    <div v-if="!budgets" class="skeleton" style="height: 140px" />
    <p v-else-if="!budgets.length" class="muted small">No budget has a limit in {{ periodName }}.</p>
    <ul v-else class="list">
      <li v-for="budget in budgets" :key="budget.id" class="budget-line">
        <div class="row">
          <span class="grow ellipsis">{{ budget.name }}</span>
          <span class="small num blurable" :class="{ bad: budget.left < 0 }">{{ budget.left < 0 ? `${money(-budget.left)} over` : `${money(budget.left)} left` }}</span>
        </div>
        <div class="track" :title="`${money(budget.spent)} of ${money(budget.limit)}`"><i :class="meterClass(budget.ratio)" :style="{ width: `${Math.min(100, budget.ratio * 100)}%` }" /></div>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { budgetMonth, meterClass } from '../../../lib/data.js'
import { money } from '../../../lib/format.js'
import { periodLabel } from '../../../lib/home.js'

// Monthly view only (see TILES.budgets.view)
const props = defineProps({ view: String, offset: Number, range: Object, options: Object, version: Number })
const budgets = ref(null)
const periodName = computed(() => periodLabel(props.view, props.offset))

watch(
  [() => props.range.start, () => props.view, () => props.version],
  async (_, __, onCleanup) => {
    let stale = false
    onCleanup(() => (stale = true))
    budgets.value = null
    const list = await budgetMonth(props.range)
    if (!stale) budgets.value = list.filter((budget) => budget.limit > 0).sort((a, b) => b.ratio - a.ratio)
  },
  { immediate: true },
)
</script>

<style>
.budget-line {
  padding: 8px 0;
}
.budget-line .track {
  margin-top: 7px;
}
</style>
