<template>
  <RouterLink v-if="badges.suggestions || editing" to="/suggestions" class="card attention">
    <span class="avatar"><Icon name="sparkles" :size="20" /></span>
    <span class="grow">
      <strong>{{ badges.suggestions ? `${badges.suggestions} category ${badges.suggestions === 1 ? 'suggestion' : 'suggestions'}` : 'No suggestions right now' }}</strong>
      <span class="item-sub" style="display: block">{{ badges.suggestions ? 'Review what the suggester proposes for new transactions' : 'Shows up when the suggester proposes categories' }}</span>
    </span>
    <Icon name="chevron" class="chev" />
  </RouterLink>
</template>

<script setup>
import Icon from '../../Icon.vue'
import { badges } from '../../../lib/badges.js'

defineProps({ editing: Boolean })
</script>

<style>
.attention {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
}
.attention .avatar {
  color: var(--accent);
}
</style>
