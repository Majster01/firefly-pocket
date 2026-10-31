<template>
  <figure class="bars" :aria-label="label">
    <div class="bars-plot" :style="{ height: `${height}px` }" @mouseleave="hover = null">
      <div class="bars-grid" aria-hidden="true">
        <span v-for="tick in ticks" :key="tick" :style="{ bottom: `${(tick / max) * 100}%` }"><em>{{ format(tick) }}</em></span>
      </div>
      <button
        v-for="(item, index) in items"
        :key="item.label"
        class="bar-hit"
        :aria-label="`${item.label}: ${format(item.value)}`"
        @mouseenter="hover = index"
        @focus="hover = index"
        @blur="hover = null"
        @click="hover = hover === index ? null : index"
      >
        <i class="bar" :class="{ current: item.current, dim: hover !== null && hover !== index }" :style="{ height: item.value > 0 ? `${Math.max(2, (item.value / max) * 100)}%` : '0' }" />
      </button>
      <div v-if="hover !== null" class="bars-tip" :style="tipStyle">
        <strong>{{ items[hover].label }}</strong>
        <span class="num">{{ format(items[hover].value) }}</span>
        <span v-if="items[hover].note" class="muted">{{ items[hover].note }}</span>
      </div>
    </div>
    <figcaption class="bars-axis" aria-hidden="true">
      <span v-for="item in items" :key="item.label" :class="{ current: item.current }">{{ item.short ?? item.label }}</span>
    </figcaption>
    <table class="sr-only">
      <caption>{{ label }}</caption>
      <tr v-for="item in items" :key="item.label"><th>{{ item.label }}</th><td>{{ format(item.value) }}</td></tr>
    </table>
  </figure>
</template>

<script setup>
import { computed, ref } from 'vue'

// One series of columns on a zero baseline: one hue, the current column darker, a tooltip per column
const props = defineProps({
  items: { type: Array, required: true }, // [{label, short, value, current, note}]
  label: { type: String, default: '' },
  height: { type: Number, default: 150 },
  format: { type: Function, default: (value) => String(Math.round(value)) },
})
const hover = ref(null)

const niceStep = (value) => {
  const power = 10 ** Math.floor(Math.log10(value || 1))
  return [1, 2, 2.5, 5, 10].map((factor) => factor * power).find((step) => value / step <= 3) ?? power * 10
}
const step = computed(() => niceStep(Math.max(...props.items.map((item) => item.value), 1)))
const max = computed(() => Math.max(step.value, Math.ceil(Math.max(...props.items.map((item) => item.value), 1) / step.value) * step.value))
const ticks = computed(() => Array.from({ length: Math.round(max.value / step.value) + 1 }, (_, index) => index * step.value))
const tipStyle = computed(() => {
  const share = (hover.value + 0.5) / props.items.length
  return { left: `${share * 100}%`, transform: `translateX(${share > 0.7 ? '-90%' : share < 0.3 ? '-10%' : '-50%'})` }
})
</script>

<style>
.bars {
  margin: 0;
}
.bars-plot {
  position: relative;
  display: flex;
  align-items: flex-end;
  gap: 6px;
  margin-left: 40px;
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
.bar-hit {
  position: relative;
  flex: 1;
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
  bottom: calc(100% + 6px);
  display: flex;
  flex-direction: column;
  gap: 1px;
  padding: 8px 11px;
  border-radius: 12px;
  background: var(--glass-strong);
  border: 1px solid var(--glass-border);
  box-shadow: var(--card-shadow);
  font-size: 13px;
  white-space: nowrap;
  pointer-events: none;
  z-index: 2;
}
.bars-axis {
  display: flex;
  gap: 6px;
  margin: 6px 0 0 40px;
}
.bars-axis span {
  flex: 1;
  text-align: center;
  font-size: 11.5px;
  color: var(--muted);
}
.bars-axis span.current {
  color: var(--text);
  font-weight: 650;
}
</style>
