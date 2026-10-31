<template>
  <div class="donut">
    <!-- The slices answer a mouse or a finger; the legend's buttons name them for a keyboard and a screen reader -->
    <div class="donut-ring" @mouseleave="hover = null">
      <svg viewBox="0 0 120 120" class="donut-svg" role="img" :aria-label="`${label}: ${segments.map((segment) => `${segment.name} ${format(segment.amount)}`).join(', ')}`">
        <circle cx="60" cy="60" :r="R" class="donut-track" />
        <path
          v-for="(segment, index) in arcs"
          :key="segment.name"
          :d="segment.path"
          class="donut-arc"
          :class="[`slot-${segment.slot}`, { dim: hover !== null && hover !== index }]"
          @mouseenter="hover = index"
          @click="$emit('select', segment)"
        />
      </svg>
      <!-- HTML rather than SVG text, so hiding amounts can blur it in every browser -->
      <div class="donut-center" aria-hidden="true">
        <span class="donut-total num blurable">{{ format(focus ? focus.amount : total) }}</span>
        <span class="donut-caption ellipsis">{{ focus ? focus.name : caption }}</span>
      </div>
    </div>
    <ul class="donut-legend">
      <li v-for="(segment, index) in segments" :key="segment.name">
        <button class="legend-row" :class="{ on: hover === index }" @mouseenter="hover = index" @mouseleave="hover = null" @focus="hover = index" @blur="hover = null" @click="$emit('select', segment)">
          <i class="swatch" :class="`slot-${segment.slot}`" />
          <span class="grow ellipsis">{{ segment.name }}</span>
          <span class="num blurable">{{ format(segment.amount) }}</span>
          <span class="num muted tiny share">{{ Math.round((segment.amount / total) * 100) }}%</span>
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'

// Five categorical slots (validated as a ring: adjacent pairs and the wrap from last to first, light and dark),
// then everything else folded into a neutral "Other". The legend names every slice, so color never works alone.
const props = defineProps({
  items: { type: Array, required: true }, // [{name, amount, ...}] largest first
  label: { type: String, default: '' },
  caption: { type: String, default: 'spent' },
  format: { type: Function, default: String },
})
defineEmits(['select'])

const SLOTS = 5
const R = 46
const WIDTH = 16
const GAP = 0.025 // radians of surface between slices
const hover = ref(null)

const segments = computed(() => {
  const head = props.items.slice(0, SLOTS).map((item, index) => ({ ...item, slot: index + 1 }))
  const rest = props.items.slice(SLOTS)
  if (rest.length) head.push({ name: 'Other', amount: rest.reduce((sum, item) => sum + item.amount, 0), slot: 'other', others: rest })
  return head.filter((segment) => segment.amount > 0)
})
const total = computed(() => segments.value.reduce((sum, segment) => sum + segment.amount, 0) || 1)
const focus = computed(() => (hover.value === null ? null : segments.value[hover.value]))

const point = (angle, radius) => [60 + radius * Math.sin(angle), 60 - radius * Math.cos(angle)]
const arcs = computed(() => {
  let angle = 0
  const outer = R + WIDTH / 2
  const inner = R - WIDTH / 2
  return segments.value.map((segment) => {
    const sweep = (segment.amount / total.value) * Math.PI * 2
    const gap = segments.value.length > 1 ? Math.min(GAP, sweep / 3) : 0
    const from = angle + gap / 2
    const to = angle + sweep - gap / 2
    angle += sweep
    const large = to - from > Math.PI ? 1 : 0
    // A full ring cannot be one arc: draw it as two halves
    if (segments.value.length === 1) {
      return { ...segment, path: `M60 ${60 - outer} A${outer} ${outer} 0 1 1 59.99 ${60 - outer} L59.99 ${60 - inner} A${inner} ${inner} 0 1 0 60 ${60 - inner} Z` }
    }
    const [x1, y1] = point(from, outer)
    const [x2, y2] = point(to, outer)
    const [x3, y3] = point(to, inner)
    const [x4, y4] = point(from, inner)
    return { ...segment, path: `M${x1} ${y1} A${outer} ${outer} 0 ${large} 1 ${x2} ${y2} L${x3} ${y3} A${inner} ${inner} 0 ${large} 0 ${x4} ${y4} Z` }
  })
})
</script>

<style>
.donut {
  --slot-1: #2a78d6;
  --slot-2: #eb6834;
  --slot-3: #1baf7a;
  --slot-4: #eda100;
  --slot-5: #e87ba4;
  --slot-other: #a39a90;
  display: flex;
  align-items: center;
  gap: 18px;
  flex-wrap: wrap;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme='light']) .donut {
    --slot-1: #3987e5;
    --slot-2: #d95926;
    --slot-3: #199e70;
    --slot-4: #c98500;
    --slot-5: #d55181;
    --slot-other: #7d746b;
  }
}
:root[data-theme='night'] .donut {
  --slot-1: #3987e5;
  --slot-2: #d95926;
  --slot-3: #199e70;
  --slot-4: #c98500;
  --slot-5: #d55181;
  --slot-other: #7d746b;
}
.donut-ring {
  position: relative;
  width: min(190px, 100%);
  flex-shrink: 0;
  margin: 0 auto;
  container-type: inline-size;
}
.donut-svg {
  display: block;
  width: 100%;
}
/* Inside the ring's hole: 120 units of the drawing are the ring's width */
.donut-center {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 0 24cqi;
  pointer-events: none;
  text-align: center;
}
.donut-track {
  fill: none;
  stroke: var(--track);
  stroke-width: 16;
}
.donut-arc {
  cursor: pointer;
  transition: opacity 0.15s ease;
}
.donut-arc.dim {
  opacity: 0.35;
}
.donut-total {
  font: 700 11.7cqi var(--font-display);
  color: var(--text);
  white-space: nowrap;
}
.donut-caption {
  max-width: 100%;
  margin-top: 1.5cqi;
  font: 500 7.1cqi var(--font);
  color: var(--muted);
}
.slot-1 {
  fill: var(--slot-1);
  background: var(--slot-1);
}
.slot-2 {
  fill: var(--slot-2);
  background: var(--slot-2);
}
.slot-3 {
  fill: var(--slot-3);
  background: var(--slot-3);
}
.slot-4 {
  fill: var(--slot-4);
  background: var(--slot-4);
}
.slot-5 {
  fill: var(--slot-5);
  background: var(--slot-5);
}
.slot-other {
  fill: var(--slot-other);
  background: var(--slot-other);
}
.donut-legend {
  flex: 1;
  min-width: 190px;
  list-style: none;
  margin: 0;
  padding: 0;
}
.legend-row {
  display: flex;
  align-items: center;
  gap: 9px;
  width: 100%;
  padding: 7px 6px;
  border: 0;
  border-radius: 10px;
  background: transparent;
  text-align: left;
  font-size: 14px;
}
.legend-row:hover,
.legend-row.on {
  background: var(--faint);
}
.swatch {
  width: 10px;
  height: 10px;
  border-radius: 3px;
  flex-shrink: 0;
}
.legend-row .share {
  width: 34px;
  text-align: right;
}
</style>
