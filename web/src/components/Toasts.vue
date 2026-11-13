<template>
  <div class="toasts" aria-live="polite">
    <TransitionGroup name="toast">
      <div v-for="item in toasts" :key="item.id" class="toast" :class="item.type" @click="dismiss(item.id)">
        <Icon :name="item.type === 'error' ? 'alert' : 'check'" :size="18" />
        <span class="grow">{{ item.message }}</span>
        <button v-if="item.action" class="btn small" @click.stop="item.action.run(); dismiss(item.id)">{{ item.action.label }}</button>
      </div>
    </TransitionGroup>
  </div>
</template>

<script setup>
import { dismiss, toasts } from '../lib/toast.js'
import Icon from './Icon.vue'
</script>

<style>
.toasts {
  position: fixed;
  z-index: var(--z-toast);
  left: 14px;
  right: 14px;
  bottom: calc(var(--safe-bottom) + 92px);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  pointer-events: none;
}
.toast {
  pointer-events: auto;
  display: flex;
  align-items: center;
  gap: 10px;
  max-width: 460px;
  width: 100%;
  padding: 12px 14px 12px 16px;
  border-radius: 20px;
  background: var(--ink);
  color: var(--on-ink);
  font-weight: 500;
  box-shadow: var(--float-shadow);
}
.toast.error svg {
  color: var(--toast-bad);
}
.toast .btn {
  background: transparent;
  color: inherit;
  border-color: color-mix(in srgb, var(--on-ink) 30%, transparent);
}
.toast-enter-active,
.toast-leave-active {
  transition: all 0.25s ease;
}
.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(12px);
}
@media (min-width: 900px) {
  .toasts {
    left: auto;
    right: 28px;
    bottom: 28px;
    align-items: flex-end;
  }
}
</style>
