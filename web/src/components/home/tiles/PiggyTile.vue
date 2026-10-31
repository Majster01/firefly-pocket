<template>
  <div class="card">
    <h2 class="card-title">
      <span>Piggy banks</span>
      <RouterLink to="/piggy-banks" class="tiny">All</RouterLink>
    </h2>
    <div v-if="!piggies" class="skeleton" style="height: 100px" />
    <p v-else-if="!piggies.length" class="muted small">No piggy banks yet.</p>
    <ul v-else class="list">
      <li v-for="piggy in piggies" :key="piggy.id" class="budget-line">
        <div class="row">
          <span class="grow ellipsis">{{ piggy.attributes.name }}</span>
          <span class="small num blurable">{{ money(piggy.attributes.current_amount) }}<span v-if="Number(piggy.attributes.target_amount)" class="muted"> / {{ money(piggy.attributes.target_amount) }}</span></span>
        </div>
        <div v-if="Number(piggy.attributes.target_amount)" class="track"><i class="ink" :style="{ width: `${Math.min(100, (piggy.attributes.current_amount / piggy.attributes.target_amount) * 100)}%` }" /></div>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { cachedGet } from '../../../lib/data.js'
import { money } from '../../../lib/format.js'

const props = defineProps({ version: Number })
const piggies = ref(null)
watch(
  () => props.version,
  async () => (piggies.value = (await cachedGet('piggy-banks', { limit: 50 }))?.data ?? []),
  { immediate: true },
)
</script>
