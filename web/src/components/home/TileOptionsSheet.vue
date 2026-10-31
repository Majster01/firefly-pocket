<template>
  <Sheet :open="Boolean(entry)" :title="entry ? TILES[entry.id].title : ''" cancel-label="Done" @close="$emit('close')">
    <template v-if="entry">
      <p class="small muted sheet-lead">Settings of this tile in the {{ DEVICES[device].label.toLowerCase() }} layout.</p>
      <div class="card">
        <template v-for="(option, key) in TILES[entry.id].options" :key="key">
          <div v-if="!option.when || option.when(values)" class="option-row">
            <template v-if="option.type === 'switch'">
              <label class="row">
                <span class="grow">{{ option.label }}</span>
                <input type="checkbox" class="switch" :checked="values[key]" @change="set(key, $event.target.checked)" />
              </label>
            </template>
            <template v-else>
              <div :id="`option-${key}`" class="option-label">{{ option.label }}</div>
              <div class="seg block" role="group" :aria-labelledby="`option-${key}`">
                <button v-for="[value, label] in option.choices" :key="value" :aria-pressed="String(values[key]) === String(value)" :class="{ on: String(values[key]) === String(value) }" @click="set(key, value)">
                  {{ label }}
                </button>
              </div>
            </template>
          </div>
        </template>
      </div>
    </template>
  </Sheet>
</template>

<script setup>
import { computed } from 'vue'
import Sheet from '../Sheet.vue'
import { DEVICES, TILES, optionsOf, touch } from '../../lib/home.js'

const props = defineProps({ entry: { type: Object, default: null }, device: { type: String, required: true } })
defineEmits(['close'])

const values = computed(() => (props.entry ? optionsOf(props.entry) : {}))
function set(key, value) {
  props.entry.options = { ...props.entry.options, [key]: value }
  touch()
}
</script>

<style>
.option-row + .option-row {
  margin-top: 16px;
}
.option-label {
  margin: 0 4px 8px;
  font-size: 13px;
  font-weight: 600;
  color: var(--muted);
}
</style>
