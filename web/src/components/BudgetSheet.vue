<template>
  <Sheet :open="Boolean(budget)" :title="budget?.name ?? ''" cancel-label="Close" @close="$emit('close')">
    <SpendDetail v-if="budget" :state="state" :amount="budget.spent" :period-name="periodName" :version="version" @close="$emit('close')">
      <template #standing>
        <div class="small" :class="[isOverLimit(budget) ? 'bad' : 'muted', { blurable: budget.limit > 0 }]">{{ budgetStanding(budget, periodName, money) }}</div>
      </template>
      <template #actions>
        <RouterLink class="btn small grow" :to="{ path: '/transactions', query: toRouteQuery({ budget: budget.name }) }" @click="$emit('close')">All time</RouterLink>
        <!-- Where it was opened from adds its own: Edit on the Budgets page, a link to it on Home -->
        <slot name="actions" />
      </template>
    </SpendDetail>
  </Sheet>
</template>

<script setup>
import { computed } from 'vue'
import Sheet from './Sheet.vue'
import SpendDetail from './SpendDetail.vue'
import { budgetStanding, isOverLimit } from '../lib/budget.js'
import { money } from '../lib/format.js'
import { toRouteQuery } from '../lib/search.js'

const props = defineProps({
  budget: { type: Object, default: null }, // a row of budgetMonth()
  range: { type: Object, default: null }, // the month that row belongs to
  periodName: { type: String, default: '' },
  version: { type: Number, default: 0 },
})
defineEmits(['close'])

const state = computed(() => ({ budget: props.budget?.name, filter: 'withdrawal', start: props.range?.start, end: props.range?.end }))
</script>
