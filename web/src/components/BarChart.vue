<template>
  <figure ref="root" class="bars" :aria-label="label">
    <div class="bars-plot" :style="{ height: `${height}px` }" @pointerleave="$event.pointerType === 'mouse' && (hover = null)">
      <div class="bars-grid" aria-hidden="true">
        <span v-for="tick in ticks" :key="tick" :style="{ bottom: `${(tick / max) * 100}%` }"><em class="blurable">{{ format(tick) }}</em></span>
      </div>
      <button
        v-for="(item, index) in items"
        :key="item.label"
        class="bar-hit"
        :aria-label="`${item.label}: ${format(item.value)}`"
        @pointerenter="$event.pointerType === 'mouse' && (hover = index)"
        @pointerdown="press($event, index)"
        @focus="hover = index"
        @blur="hover = null"
        @click="tap(index)"
      >
        <i class="bar" :class="{ current: item.current, dim: hover !== null && hover !== index }" :style="{ height: item.value > 0 ? `${Math.max(2, (item.value / max) * 100)}%` : '0' }" />
      </button>
      <div v-if="hover !== null" class="bars-tip" :style="tipStyle">
        <strong>{{ items[hover].label }}</strong>
        <span class="num blurable">{{ format(items[hover].value) }}</span>
        <span v-if="items[hover].note" class="muted">{{ items[hover].note }}</span>
      </div>
    </div>
    <figcaption class="bars-axis" :class="{ 'has-tiny': items.some((item) => item.tiny) }" aria-hidden="true">
      <span v-for="item in items" :key="item.label" :class="{ current: item.current }">
        <span class="axis-short">{{ item.short ?? item.label }}</span><span v-if="item.tiny" class="axis-tiny">{{ item.tiny }}</span>
      </span>
    </figcaption>
    <table class="sr-only">
      <caption>{{ label }}</caption>
      <tr v-for="item in items" :key="item.label"><th>{{ item.label }}</th><td>{{ format(item.value) }}</td></tr>
    </table>
  </figure>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'

// One series of columns on a zero baseline: one hue, the current column darker, a tooltip per column
const props = defineProps({
  items: { type: Array, required: true }, // [{label, short, tiny, value, current, note}]; tiny: a label for narrow charts
  label: { type: String, default: '' },
  height: { type: Number, default: 150 },
  format: { type: Function, default: (value) => String(Math.round(value)) },
})
const root = ref(null)
const hover = ref(null)

const niceStep = (value) => {
  const power = 10 ** Math.floor(Math.log10(value || 1))
  return [1, 2, 2.5, 5, 10].map((factor) => factor * power).find((step) => value / step <= 3) ?? power * 10
}
const step = computed(() => niceStep(Math.max(...props.items.map((item) => item.value), 1)))
const max = computed(() => Math.max(step.value, Math.ceil(Math.max(...props.items.map((item) => item.value), 1) / step.value) * step.value))
const ticks = computed(() => Array.from({ length: Math.round(max.value / step.value) + 1 }, (_, index) => index * step.value))
// The tooltip sits at the top of the plot beside its column, on the side with more room: inside the card, clear of
// the column it describes
const tipStyle = computed(() => {
  const count = props.items.length
  return hover.value < count / 2 ? { left: `calc(${((hover.value + 1) / count) * 100}% + 4px)` } : { right: `calc(${((count - hover.value) / count) * 100}% + 4px)` }
})

// A tap shows a column's tooltip and a second tap on it hides it; a mouse shows it on hover. Tapping a column
// focuses it first, so whether it was open is read before that.
let tapped = null
function press(event, index) {
  tapped = event.pointerType === 'mouse' ? null : { open: hover.value === index }
}
function tap(index) {
  hover.value = tapped?.open ? null : index
  tapped = null
}

// A tooltip opened by a tap stays until a tap elsewhere, or until a scroll moves the chart (a scroll still settling
// when the tap came does not count)
let openedAt = 0
const top = () => root.value?.getBoundingClientRect().top ?? 0
const dismiss = (event) => {
  if (event.type === 'scroll' ? Math.abs(top() - openedAt) > 2 : !root.value?.contains(event.target)) hover.value = null
}
function listen(on) {
  const method = on ? 'addEventListener' : 'removeEventListener'
  document[method]('pointerdown', dismiss, true)
  window[method]('scroll', dismiss, { capture: true, passive: true })
}
watch(hover, (value, before) => {
  if (value !== null && before === null) openedAt = top()
  if ((value === null) !== (before === null)) listen(value !== null)
})
onBeforeUnmount(() => listen(false))
</script>

<style>
.bars {
  margin: 0;
  container-type: inline-size;
}
.bars-plot {
  position: relative;
  display: flex;
  align-items: flex-end;
  gap: 6px;
  margin-left: 40px;
}
.bars-grid {
  position: absolute;
  inset: 0;
}
.bars-grid span {
  position: absolute;
  left: 0;
  right: 0;
  border-top: 1px solid var(--line);
}
.bars-grid em {
  position: absolute;
  right: calc(100% + 8px);
  top: -8px;
  font: 500 11px var(--font);
  font-style: normal;
  color: var(--muted);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
/* Columns and their labels share one width each, however long a label is, so each label sits under its column */
.bar-hit,
.bars-axis > span {
  flex: 1 1 0;
  min-width: 0;
}
.bar-hit {
  position: relative;
  height: 100%;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  border: 0;
  padding: 0;
  background: transparent;
}
.bar {
  width: min(70%, 34px);
  border-radius: 4px 4px 0 0;
  background: var(--chart-soft);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--chart) 30%, transparent);
  transition: opacity 0.15s ease, height 0.5s ease;
}
.bar.current {
  background: var(--chart);
}
.bar.dim {
  opacity: 0.5;
}
.bars-tip {
  position: absolute;
  top: 0;
  display: flex;
  flex-direction: column;
  gap: 1px;
  padding: 8px 11px;
  border-radius: 12px;
  background: var(--glass-strong);
  border: 1px solid var(--glass-border);
  box-shadow: var(--card-shadow);
  font-size: 13px;
  width: max-content;
  max-width: calc(50% - 4px);
  pointer-events: none;
  z-index: 2;
}
.bars-axis {
  display: flex;
  gap: 6px;
  margin: 6px 0 0 40px;
}
.bars-axis > span {
  overflow: hidden;
  text-align: center;
  font-size: 11.5px;
  color: var(--muted);
  white-space: nowrap;
}
.bars-axis span.current {
  color: var(--text);
  font-weight: 650;
}
.axis-tiny {
  display: none;
}
/* Twelve three-letter months need about 30px a column: narrower, they take one letter */
@container (max-width: 400px) {
  .bars-axis.has-tiny .axis-short {
    display: none;
  }
  .bars-axis.has-tiny .axis-tiny {
    display: inline;
  }
}
</style>
