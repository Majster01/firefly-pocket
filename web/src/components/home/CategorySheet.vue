<template>
  <Sheet :open="Boolean(category)" :title="category?.name ?? ''" cancel-label="Close" @close="$emit('close')">
    <template v-if="category">
      <!-- "Other" in the donut, or the rest of the bar list: pick one of the folded categories -->
      <template v-if="category.others">
        <p class="small muted sheet-lead">{{ category.others.length }} smaller categories · <span class="blurable">{{ money(category.amount) }}</span> in {{ periodName }}</p>
        <div class="card flush">
          <button v-for="other in category.others" :key="other.name" class="item" @click="$emit('close'), pickLater(other)">
            <span class="grow item-title">{{ other.name }}</span>
            <span class="item-end num blurable">{{ money(other.amount) }}</span>
            <Icon name="chevron" class="chev" :size="18" />
          </button>
        </div>
      </template>

      <SpendDetail v-else :state="state" :amount="category.amount" :period-name="periodName" :version="version" @close="$emit('close')">
        <template #actions>
          <RouterLink v-if="!category.uncategorized" class="btn small grow" :to="{ path: '/transactions', query: toRouteQuery({ category: category.name }) }" @click="$emit('close')">All time</RouterLink>
          <RouterLink v-if="category.uncategorized && settings.features.ruleSuggestions" class="btn small grow" to="/suggestions" @click="$emit('close')">Suggestions</RouterLink>
          <RouterLink class="btn small grow" to="/categories" @click="$emit('close')">Categories</RouterLink>
        </template>
      </SpendDetail>
    </template>
  </Sheet>
</template>

<script setup>
import { computed } from 'vue'
import Sheet from '../Sheet.vue'
import Icon from '../Icon.vue'
import SpendDetail from '../SpendDetail.vue'
import { money } from '../../lib/format.js'
import { toRouteQuery } from '../../lib/search.js'
import { settings } from '../../lib/settings.js'

const props = defineProps({
  category: { type: Object, default: null },
  range: { type: Object, required: true },
  periodName: { type: String, default: '' },
  version: { type: Number, default: 0 },
})
const emit = defineEmits(['close', 'pick'])

const state = computed(() => ({
  ...(props.category?.uncategorized ? { filter: 'uncategorized' } : { category: props.category?.name, filter: 'withdrawal' }),
  start: props.range.start,
  end: props.range.end,
}))

// Picking from "Other" closes this sheet and reopens it for that category
const pickLater = (other) => setTimeout(() => emit('pick', other), 300)
</script>

<style>
.sheet-lead {
  margin: 0 4px 12px;
}
</style>
