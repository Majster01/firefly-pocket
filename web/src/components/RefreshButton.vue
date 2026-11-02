<template>
  <button class="btn icon small" aria-label="Refresh" @click="onClick">
    <span class="refresh-icon" :class="{ spin: spinning }" @animationend="spinning = false"><Icon name="refresh" :size="18" /></span>
  </button>
</template>

<script setup>
import { ref } from 'vue'
import Icon from './Icon.vue'

// The refresh button of a page header. It spins once per press, even when the data is cached and nothing changes
const emit = defineEmits(['click'])
const spinning = ref(false)

function onClick(event) {
  // Drop the class and set it again on the next frame, so a press during a spin starts a new one
  spinning.value = false
  requestAnimationFrame(() => (spinning.value = true))
  emit('click', event)
}
</script>

<style scoped>
.refresh-icon {
  display: inline-flex;
}
.refresh-icon.spin {
  animation: refresh-spin 0.5s ease-in-out;
}
@keyframes refresh-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
