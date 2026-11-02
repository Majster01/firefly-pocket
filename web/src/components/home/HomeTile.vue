<template>
  <section
    class="tile"
    :class="[`size-${entry.size}`, { editing, hidden: !entry.visible, bare: tile.notice && !editing }]"
    :style="{ gridColumn: `span ${span}` }"
    :data-tile-id="entry.id"
  >
    <div v-if="editing" class="tile-bar">
      <span class="grow ellipsis tile-name">{{ tile.title }}</span>
      <!-- The phone layout reads down one column, the desktop one across rows -->
      <button class="btn small icon" :aria-label="column ? 'Move up' : 'Move earlier'" :disabled="first" @click="$emit('move', -1)">
        <Icon :name="column ? 'down' : 'back'" :class="{ 'icon-up': column }" :size="16" />
      </button>
      <button class="btn small icon" :aria-label="column ? 'Move down' : 'Move later'" :disabled="last" @click="$emit('move', 1)"><Icon :name="column ? 'down' : 'chevron'" :size="16" /></button>
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

const props = defineProps({ entry: { type: Object, required: true }, device: { type: String, required: true }, editing: Boolean, first: Boolean, last: Boolean })
defineEmits(['move', 'resize', 'options', 'toggle'])

const tile = computed(() => TILES[props.entry.id])
const sizes = computed(() => tile.value[props.device])
const span = computed(() => DEVICES[props.device].sizes[props.entry.size] ?? DEVICES[props.device].columns)
const column = computed(() => props.device === 'phone')
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
.tile-size {
  min-width: 46px;
}
.tile-bar .icon-up {
  transform: rotate(180deg);
}
@keyframes tile-wiggle {
  from {
    transform: scale(0.985);
  }
}
</style>
