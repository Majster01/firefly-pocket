<template>
  <span class="chip confidence" :class="low ? 'warn' : value >= 0.85 ? 'good' : ''" :title="`Model confidence ${Math.round(value * 100)}%`">
    <Icon :name="low ? 'alert' : 'sparkles'" :size="13" /> {{ Math.round(value * 100) }}%{{ low ? ' · unsure' : '' }}
  </span>
</template>

<script setup>
import Icon from './Icon.vue'

defineProps({ value: { type: Number, default: 0 }, low: Boolean })
</script>
