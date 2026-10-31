<template>
  <section
    class="tile"
    :class="[`size-${entry.size}`, { editing, hidden: !entry.visible, dragging, bare: tile.notice && !editing }]"
    :style="{ gridColumn: `span ${span}` }"
    :data-tile-id="entry.id"
  >
    <div v-if="editing" class="tile-bar">
      <button class="tile-handle" aria-label="Drag to move" title="Drag to move" @pointerdown="$emit('drag', $event)"><Icon name="grid" :size="18" /></button>
      <span class="grow ellipsis tile-name">{{ tile.title }}</span>
      <button class="btn small icon" aria-label="Move earlier" @click="$emit('move', -1)"><Icon name="back" :size="16" /></button>
      <button class="btn small icon" aria-label="Move later" @click="$emit('move', 1)"><Icon name="chevron" :size="16" /></button>
      <button v-if="sizes.length > 1" class="btn small tile-size" :aria-label="`Size ${SIZE_LABELS[entry.size]}, change`" @click="$emit('resize')">{{ SIZE_LABELS[entry.size] }}</button>
      <button v-if="tile.options" class="btn small icon" aria-label="Tile settings" @click="$emit('options')"><Icon name="settings" :size="16" /></button>
      <button class="btn small icon" :aria-label="entry.visible ? 'Hide tile' : 'Show tile'" @click="$emit('toggle')"><Icon :name="entry.visible ? 'eye' : 'eyeOff'" :size="16" /></button>
    </div>
    <div class="tile-body" :inert="editing || undefined">
      <slot />
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import Icon from '../Icon.vue'
import { DEVICES, SIZE_LABELS, TILES } from '../../lib/home.js'

const props = defineProps({ entry: { type: Object, required: true }, device: { type: String, required: true }, editing: Boolean, dragging: Boolean })
defineEmits(['drag', 'move', 'resize', 'options', 'toggle'])

const tile = computed(() => TILES[props.entry.id])
const sizes = computed(() => tile.value[props.device])
const span = computed(() => DEVICES[props.device].sizes[props.entry.size] ?? DEVICES[props.device].columns)
</script>

<style>
.tile {
  position: relative;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.tile-body {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.tile-body > .card {
  flex: 1;
}
.tile-body > .card + .card {
  margin-top: 12px;
}
.tile.editing .tile-body {
  pointer-events: none;
  user-select: none;
}
.tile.editing {
  border-radius: calc(var(--radius) + 6px);
  padding: 6px;
  background: color-mix(in srgb, var(--glass-base) 30%, transparent);
  box-shadow: inset 0 0 0 1.5px color-mix(in srgb, var(--ink) 25%, transparent);
  animation: tile-wiggle 0.3s ease;
}
.tile.editing.hidden .tile-body {
  opacity: 0.35;
  filter: grayscale(1);
}
.tile.dragging {
  opacity: 0.6;
  box-shadow: inset 0 0 0 2px var(--accent);
}
.tile-bar {
  display: flex;
  align-items: center;
  gap: 4px;
  margin: 0 0 6px;
}
.tile-name {
  font-size: 13px;
  font-weight: 650;
}
.tile-handle {
  display: grid;
  place-items: center;
  width: 34px;
  height: 34px;
  border: 0;
  border-radius: 10px;
  background: var(--ink);
  color: var(--on-ink);
  cursor: grab;
  touch-action: none;
}
.tile-size {
  min-width: 46px;
}
@keyframes tile-wiggle {
  from {
    transform: scale(0.985);
  }
}
</style>
