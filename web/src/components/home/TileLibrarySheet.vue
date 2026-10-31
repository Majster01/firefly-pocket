<template>
  <Sheet :open="open" :title="`${DEVICES[device].label} tiles`" cancel-label="Done" @close="$emit('close')">
    <p class="small muted sheet-lead">
      {{ device === 'phone' ? 'Phones and narrow windows' : 'Windows 900px wide and more' }} use this layout. The {{ other.toLowerCase() }} layout is separate. Switch tiles on and off here; move and resize them on Home.
    </p>
    <div class="card flush">
      <label v-for="entry in entries" :key="entry.id" class="item">
        <span class="avatar"><Icon :name="TILES[entry.id].icon" :size="19" /></span>
        <span class="grow">
          <span class="item-title" style="display: block">{{ TILES[entry.id].title }}</span>
          <span class="item-sub" style="display: block">{{ TILES[entry.id].notice ? 'Only shows when there is something to act on' : SIZE_LABELS[entry.size] + ' width' }}</span>
        </span>
        <input type="checkbox" class="switch" :checked="entry.visible" @change="toggle(entry)" />
      </label>
    </div>
    <div class="row wrap library-actions">
      <button class="btn small grow" @click="copy">Copy the {{ other.toLowerCase() }} layout</button>
      <button class="btn small grow danger" @click="reset">Reset to default</button>
    </div>
  </Sheet>
</template>

<script setup>
import { computed } from 'vue'
import Sheet from '../Sheet.vue'
import Icon from '../Icon.vue'
import { DEVICES, SIZE_LABELS, TILES, copyLayout, home, resetLayout, touch } from '../../lib/home.js'
import { settings } from '../../lib/settings.js'

const props = defineProps({ open: Boolean, device: { type: String, required: true } })
defineEmits(['close'])

const otherDevice = computed(() => (props.device === 'phone' ? 'desktop' : 'phone'))
const other = computed(() => DEVICES[otherDevice.value].label)
const entries = computed(() => home[props.device].tiles.filter((entry) => !TILES[entry.id].feature || settings.features[TILES[entry.id].feature]))

function toggle(entry) {
  entry.visible = !entry.visible
  touch()
}
function copy() {
  if (confirm(`Replace the ${DEVICES[props.device].label.toLowerCase()} layout with the ${other.value.toLowerCase()} one? Sizes are fitted to this screen.`)) copyLayout(otherDevice.value, props.device)
}
function reset() {
  if (confirm(`Reset the ${DEVICES[props.device].label.toLowerCase()} layout to the default?`)) resetLayout(props.device)
}
</script>

<style>
.library-actions {
  margin-top: 14px;
  gap: 8px;
}
</style>
