<template>
  <header class="page-head" :class="{ scrolled }">
    <div class="page-head-inner">
      <RouterLink v-if="back" :to="back" class="btn icon small page-back" aria-label="Back"><Icon name="back" :size="20" /></RouterLink>
      <div class="grow">
        <div v-if="eyebrow" class="page-eyebrow">{{ eyebrow }}</div>
        <h1 class="page-title" tabindex="-1">{{ title }}</h1>
      </div>
      <div class="row page-actions"><slot /></div>
    </div>
  </header>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import Icon from './Icon.vue'

defineProps({ title: { type: String, required: true }, eyebrow: { type: String, default: '' }, back: { type: [String, Object], default: null } })

const scrolled = ref(false)
const onScroll = () => (scrolled.value = window.scrollY > 8)
onMounted(() => window.addEventListener('scroll', onScroll, { passive: true }))
onBeforeUnmount(() => window.removeEventListener('scroll', onScroll))
</script>

<style>
.page-head {
  position: sticky;
  top: 0;
  z-index: var(--z-sticky);
  margin: 0 -16px 8px;
  padding: calc(var(--safe-top) + 10px) 16px 10px;
  transition: background 0.2s ease, box-shadow 0.2s ease;
}
.page-head.scrolled {
  background: color-mix(in srgb, var(--glass-base) 45%, transparent);
  -webkit-backdrop-filter: blur(24px) saturate(160%);
  backdrop-filter: blur(24px) saturate(160%);
  box-shadow: 0 1px 0 var(--line);
}
.page-head-inner {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  min-height: 44px;
}
/* The title keeps at least 140px: on a narrow phone a row of actions moves to its own line instead of covering it */
.page-head-inner > .grow {
  flex: 1 1 140px;
}
.page-eyebrow {
  font-size: 13px;
  font-weight: 600;
  color: var(--bare-text);
}
.page-title {
  font-size: 30px;
  font-weight: 750;
  letter-spacing: -0.025em;
  line-height: 1.1;
  overflow-wrap: break-word;
}
/* Focused by the router after navigation so screen readers announce the page; not something to click */
.page-title:focus {
  outline: none;
}
.page-head.scrolled .page-title {
  font-size: 22px;
}
.page-actions {
  gap: 8px;
  margin-left: auto;
}
@media (min-width: 900px) {
  /* The floating sidebar already gives context, so the header scrolls away with the page instead of overlaying it */
  .page-head,
  .page-head.scrolled {
    position: static;
    margin: 0 0 12px;
    padding: 22px 0 14px;
    background: none;
    -webkit-backdrop-filter: none;
    backdrop-filter: none;
    box-shadow: none;
  }
  .page-title,
  .page-head.scrolled .page-title {
    font-size: 32px;
  }
}
</style>
