<template>
  <div class="card">
    <h2 class="card-title">
      <span>Budgets · {{ periodName }}</span>
      <RouterLink to="/budgets" class="tiny">Details</RouterLink>
    </h2>
    <div v-if="!budgets" class="skeleton" style="height: 140px" />
    <p v-else-if="!budgets.length" class="muted small">No budget has a limit in {{ periodName }}.</p>
    <ul v-else class="list">
      <li v-for="budget in budgets" :key="budget.id">
        <button class="budget-line" @click="pick(budget)">
          <span class="row">
            <span class="grow ellipsis">{{ budget.name }}</span>
            <span class="small num blurable" :class="{ bad: budget.left < 0 }">{{ budget.left < 0 ? `${money(-budget.left)} over` : `${money(budget.left)} left` }}</span>
          </span>
          <span class="track" :title="`${money(budget.spent)} of ${money(budget.limit)}`"><i :class="meterClass(budget.ratio)" :style="{ width: `${Math.min(100, budget.ratio * 100)}%` }" /></span>
        </button>
      </li>
    </ul>
    <BudgetSheet :budget="picked" :range="range" :period-name="periodName" :version="version" @close="unpick">
      <template #actions>
        <RouterLink class="btn small grow" :to="{ path: '/budgets', query: offset ? { offset: String(offset) } : {} }" @click="unpick">Budgets</RouterLink>
      </template>
    </BudgetSheet>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import BudgetSheet from '../../BudgetSheet.vue'
import { budgetMonth, meterClass } from '../../../lib/data.js'
import { money } from '../../../lib/format.js'
import { periodLabel } from '../../../lib/home.js'
import { usePicked } from '../../../lib/picked.js'

// Monthly view only (see TILES.budgets.view)
const props = defineProps({ view: String, offset: Number, range: Object, options: Object, version: Number })
const budgets = ref(null)
const { picked, pick, unpick } = usePicked(budgets)
const periodName = computed(() => periodLabel(props.view, props.offset))

const periodKey = computed(() => `${props.range.start} ${props.view}`)

watch(
  [periodKey, () => props.version],
  async ([key], [oldKey], onCleanup) => {
    let stale = false
    onCleanup(() => (stale = true))
    // A refresh keeps the rows, and an open sheet, until the new ones are in
    if (key !== oldKey) budgets.value = null
    const found = await budgetMonth(props.range)
    if (!stale) budgets.value = found.filter((budget) => budget.limit > 0).sort((a, b) => b.ratio - a.ratio)
  },
  { immediate: true },
)
</script>

<style>
.budget-line {
  display: block;
  width: calc(100% + 16px);
  margin: 0 -8px;
  padding: 8px;
  border: 0;
  border-radius: 12px;
  background: transparent;
  text-align: left;
}
.budget-line:hover {
  background: var(--faint);
}
.budget-line .track {
  display: block;
  margin-top: 7px;
}
</style>
