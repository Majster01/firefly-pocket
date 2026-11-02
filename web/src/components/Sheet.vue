<template>
  <Teleport to="body">
    <Transition name="sheet">
      <div v-if="open" ref="layer" class="sheet-layer" @keydown.esc="close" @keydown.tab="trapTab">
        <div class="sheet-scrim" @click="close" />
        <div ref="panel" class="sheet" role="dialog" aria-modal="true" :aria-label="title" tabindex="-1" :class="{ wide }">
          <div class="sheet-grabber" aria-hidden="true" />
          <header class="sheet-head">
            <button v-if="!hideCancel" class="btn small" @click="close">{{ cancelLabel }}</button>
            <h2 class="sheet-title">{{ title }}</h2>
            <slot name="action"><span class="sheet-spacer" /></slot>
          </header>
          <div class="sheet-body">
            <slot />
          </div>
          <footer v-if="$slots.footer" class="sheet-foot">
            <slot name="footer" />
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script>
// Open sheets, oldest first, shared by every Sheet: a sheet opened from another sheet makes the one below inert too
const stack = []
// Everything any sheet made inert. When the last sheet closes, all of it is released, so a missed release can never
// leave the page (and the tab bar) dead to touch
const inerted = new Set()
</script>

<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'

// A bottom sheet on phones, a centered dialog on desktop
const props = defineProps({
  open: Boolean,
  title: { type: String, default: '' },
  cancelLabel: { type: String, default: 'Cancel' },
  hideCancel: Boolean,
  wide: Boolean,
})
const emit = defineEmits(['close'])
const layer = ref(null)
const panel = ref(null)
const close = () => emit('close')

let mine = null
let opener = null
let silenced = []

async function activate() {
  opener = document.activeElement instanceof HTMLElement && document.activeElement !== document.body ? document.activeElement : null
  await nextTick()
  // Closed again while waiting, or a quick close and reopen already activated this sheet
  if (!props.open || mine || !layer.value) return
  mine = layer.value
  stack.push(mine)
  document.documentElement.classList.add('sheet-open')
  // The sheet lives in <body>; everything else (except the toasts, whose Undo must stay reachable) goes inert
  const behind = [...document.querySelectorAll('#app > :not(.toasts), body > .sheet-layer')].filter((el) => el !== mine && !el.inert)
  behind.forEach((el) => {
    el.inert = true
    inerted.add(el)
  })
  silenced = behind
  panel.value?.focus({ preventScroll: true })
}

function deactivate() {
  const at = stack.indexOf(mine)
  if (at === -1) return
  stack.splice(at, 1)
  mine = null
  silenced.forEach((el) => {
    el.inert = false
    inerted.delete(el)
  })
  silenced = []
  if (!stack.length) {
    inerted.forEach((el) => (el.inert = false))
    inerted.clear()
    document.documentElement.classList.remove('sheet-open')
  }
  // Back to the button or row that opened it, when that is still on the page
  if (opener?.isConnected) opener.focus({ preventScroll: true })
  opener = null
}

// Tab and Shift+Tab cycle within the panel
function trapTab(event) {
  const focusable = [...panel.value.querySelectorAll('a[href], button, input, select, textarea, summary, [tabindex]:not([tabindex="-1"])')].filter(
    // An unchecked radio is skipped by Tab (only the group's checked one takes focus)
    (el) => !el.disabled && !(el.type === 'radio' && !el.checked) && !el.closest('[inert]') && el.getClientRects().length,
  )
  if (!focusable.length) return event.preventDefault()
  const [first, last] = [focusable[0], focusable.at(-1)]
  const current = document.activeElement
  if (event.shiftKey && (current === first || current === panel.value || !panel.value.contains(current))) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && (current === last || !panel.value.contains(current))) {
    event.preventDefault()
    first.focus()
  }
}

watch(
  () => props.open,
  (open) => (open ? activate() : deactivate()),
  { immediate: true },
)
onBeforeUnmount(deactivate)
</script>

<style>
html.sheet-open body {
  overflow: hidden;
}
.sheet-layer {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: flex-end;
  justify-content: center;
}
.sheet-scrim {
  position: absolute;
  inset: 0;
  background: var(--scrim);
}
.sheet {
  position: relative;
  display: flex;
  flex-direction: column;
  width: 100%;
  max-height: calc(100dvh - var(--safe-top) - 12px);
  border-radius: 28px 28px 0 0;
  background: var(--glass-strong);
  border: 1px solid var(--glass-border);
  border-bottom: 0;
  box-shadow: var(--frost-edge), var(--float-shadow);
  -webkit-backdrop-filter: blur(36px) saturate(160%);
  backdrop-filter: blur(36px) saturate(160%);
  outline: none;
}
/* The panel takes focus only so the next Tab lands inside it; the global ring would outline the whole sheet */
.sheet:focus-visible {
  outline: none;
}
.sheet-grabber {
  width: 38px;
  height: 5px;
  border-radius: 3px;
  background: color-mix(in srgb, var(--muted) 45%, transparent);
  margin: 8px auto 0;
}
.sheet-head {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  gap: 8px;
  padding: 8px 14px 10px;
}
.sheet-head > :first-child {
  justify-self: start;
}
.sheet-head > :last-child {
  justify-self: end;
}
.sheet-title {
  font-size: 17px;
  font-weight: 700;
  text-align: center;
}
.sheet-body {
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  overscroll-behavior: contain;
  padding: 4px 16px calc(20px + var(--safe-bottom));
}
.sheet-foot {
  padding: 10px 16px calc(12px + var(--safe-bottom));
  border-top: 1px solid var(--line);
}
.sheet-foot + .sheet-body,
.sheet-body:has(+ .sheet-foot) {
  padding-bottom: 16px;
}
.sheet-enter-active,
.sheet-leave-active {
  transition: opacity 0.25s ease;
}
.sheet-enter-active .sheet,
.sheet-leave-active .sheet {
  transition: transform 0.32s cubic-bezier(0.2, 0.9, 0.3, 1);
}
.sheet-enter-from,
.sheet-leave-to {
  opacity: 0;
}
.sheet-enter-from .sheet,
.sheet-leave-to .sheet {
  transform: translateY(100%);
}

@media (min-width: 900px) {
  .sheet-layer {
    align-items: center;
    padding: 32px;
  }
  .sheet {
    max-width: 520px;
    max-height: min(820px, calc(100dvh - 64px));
    border-radius: 28px;
    border-bottom: 1px solid var(--glass-border);
  }
  .sheet.wide {
    max-width: 720px;
  }
  .sheet-grabber {
    display: none;
  }
  .sheet-head {
    padding-top: 16px;
  }
  .sheet-body {
    padding-bottom: 20px;
  }
  .sheet-enter-from .sheet,
  .sheet-leave-to .sheet {
    transform: translateY(16px) scale(0.98);
  }
}
</style>
