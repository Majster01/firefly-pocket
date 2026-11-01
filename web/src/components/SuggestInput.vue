<template>
  <div class="suggest">
    <input
      ref="input"
      :value="modelValue"
      v-bind="$attrs"
      autocomplete="off"
      role="combobox"
      aria-autocomplete="list"
      :aria-expanded="shown"
      :aria-controls="listId"
      :aria-activedescendant="shown && active >= 0 ? `${listId}-${active}` : undefined"
      @input="onInput"
      @focus="open = true"
      @blur="open = false"
      @keydown="onKey"
    />
    <!-- mousedown is cancelled so the input keeps focus: typing never stops while the list is up -->
    <ul v-if="shown" :id="listId" class="suggest-list" role="listbox">
      <li
        v-for="(item, index) in matches"
        :id="`${listId}-${index}`"
        :key="item"
        role="option"
        :aria-selected="index === active"
        :class="{ on: index === active }"
        @mousedown.prevent
        @click="pick(item)"
      >
        {{ item }}
      </li>
    </ul>
  </div>
</template>

<script>
let uid = 0
</script>

<script setup>
import { computed, ref, watch } from 'vue'

defineOptions({ inheritAttrs: false })

// A text input that offers values from a list. `multiple` treats the text as a comma separated list and suggests for
// the part being typed
const props = defineProps({
  modelValue: { type: String, default: '' },
  options: { type: Array, default: () => [] },
  multiple: Boolean,
  limit: { type: Number, default: 6 },
})
const emit = defineEmits(['update:modelValue'])

const listId = `suggest-${++uid}`
const input = ref(null)
const open = ref(false)
const active = ref(-1)

// For a list: the text before the part being typed, and the part itself
const split = computed(() => {
  if (!props.multiple) return { head: '', term: props.modelValue }
  const at = props.modelValue.lastIndexOf(',')
  return { head: props.modelValue.slice(0, at + 1), term: props.modelValue.slice(at + 1) }
})

const matches = computed(() => {
  const term = split.value.term.trim().toLowerCase()
  const taken = props.multiple ? new Set(split.value.head.split(',').map((tag) => tag.trim().toLowerCase())) : new Set()
  const found = []
  for (const option of props.options) {
    const lower = option.toLowerCase()
    if (taken.has(lower) || lower === term) continue
    if (!term || lower.includes(term)) found.push(option)
  }
  // Names starting with what was typed first
  if (term) found.sort((a, b) => Number(b.toLowerCase().startsWith(term)) - Number(a.toLowerCase().startsWith(term)))
  return found.slice(0, props.limit)
})
const shown = computed(() => open.value && matches.value.length > 0)

watch(matches, () => (active.value = -1))

function onInput(event) {
  open.value = true
  emit('update:modelValue', event.target.value)
}

function pick(item) {
  const { head } = split.value
  emit('update:modelValue', props.multiple ? `${head}${head ? ' ' : ''}${item}, ` : item)
  active.value = -1
  // A single value is done; a list goes on with the next tag
  if (!props.multiple) open.value = false
  input.value?.focus()
}

// Only the keys the list needs are handled, and only while it is showing: everything else reaches the input untouched
function onKey(event) {
  if (event.key === 'Escape' && shown.value) {
    // Close the list, not the sheet around it
    event.stopPropagation()
    open.value = false
    return
  }
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    if (!shown.value) {
      if (matches.value.length) open.value = true
      return
    }
    event.preventDefault()
    const n = matches.value.length
    active.value = event.key === 'ArrowDown' ? (active.value + 1) % n : (active.value - 1 + n) % n
    return
  }
  if (event.key === 'Enter' && shown.value && active.value >= 0) {
    event.preventDefault()
    event.stopPropagation()
    pick(matches.value[active.value])
  }
}
</script>

<style>
.suggest {
  position: relative;
}
.suggest-list {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  z-index: 5;
  margin: 0;
  padding: 4px;
  list-style: none;
  max-height: 220px;
  overflow-y: auto;
  border-radius: var(--radius-sm);
  border: 1px solid var(--glass-border);
  background: var(--glass-strong);
  -webkit-backdrop-filter: blur(24px);
  backdrop-filter: blur(24px);
  box-shadow: var(--float-shadow);
}
.suggest-list li {
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 15px;
  color: var(--text);
  cursor: pointer;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.suggest-list li.on,
.suggest-list li:hover {
  background: color-mix(in srgb, var(--accent) 14%, transparent);
}
</style>
