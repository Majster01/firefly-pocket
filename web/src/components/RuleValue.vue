<template>
  <template v-if="definition.value === 'none'" />
  <select v-else-if="definition.value === 'type'" :value="modelValue" class="input" :aria-label="label" @change="emit('update:modelValue', $event.target.value)">
    <option value="" disabled>Choose type…</option>
    <option v-for="option in TRANSACTION_TYPES" :key="option.value" :value="option.value">{{ option.label }}</option>
  </select>
  <!-- One account; a list of ids made in Firefly's own pages stays editable as text -->
  <select v-else-if="definition.value === 'account' && !isIdList" :value="modelValue" class="input" :aria-label="label" @change="emit('update:modelValue', $event.target.value)">
    <option value="" disabled>Choose account…</option>
    <option v-if="modelValue && !accountById(modelValue)" :value="modelValue">Account #{{ modelValue }} (not found)</option>
    <optgroup v-for="group in accountGroups" :key="group.label" :label="group.label">
      <option v-for="account in group.accounts" :key="account.id" :value="String(account.id)">{{ account.attributes.name }}</option>
    </optgroup>
  </select>
  <input v-else-if="definition.value === 'date'" :value="modelValue" type="date" class="input" :aria-label="label" @input="emit('update:modelValue', $event.target.value)" />
  <input v-else-if="definition.value === 'amount'" :value="modelValue" inputmode="decimal" class="input" placeholder="0.00" :aria-label="label" @input="emit('update:modelValue', $event.target.value)" />
  <SuggestInput v-else-if="options.length" :model-value="modelValue" :options="options" class="input" autocapitalize="off" :placeholder="placeholder" :aria-label="label" @update:model-value="emit('update:modelValue', $event)" />
  <input v-else :value="modelValue" class="input" autocapitalize="off" :placeholder="placeholder" :aria-label="label" @input="emit('update:modelValue', $event.target.value)" />
</template>

<script setup>
import { computed } from 'vue'
import SuggestInput from './SuggestInput.vue'
import { ACCOUNT_TYPES, accountById, store } from '../lib/data.js'
import { TRANSACTION_TYPES } from '../lib/rules.js'

// The value of one rule condition or action, in the input that fits its kind (lib/rules.js)
const props = defineProps({
  definition: { type: Object, required: true },
  modelValue: { type: String, default: '' },
  label: { type: String, default: 'Value' },
})
const emit = defineEmits(['update:modelValue'])

const isIdList = computed(() => String(props.modelValue).includes(','))
const placeholder = computed(() => (props.definition.value === 'optional' ? 'Optional' : ''))

const accountGroups = computed(() =>
  Object.entries(ACCOUNT_TYPES)
    .map(([type, label]) => ({ label, accounts: store.accounts.filter((account) => account.attributes.type === type || (type === 'liabilities' && account.attributes.type === 'liability')) }))
    .filter((group) => group.accounts.length),
)

const unique = (list) => [...new Set(list.filter(Boolean))].sort((a, b) => a.localeCompare(b))
const options = computed(() => {
  switch (props.definition.value) {
    case 'category':
      return unique(store.categories.map((category) => category.attributes.name))
    case 'budget':
      return unique(store.budgets.filter((budget) => budget.attributes.active !== false).map((budget) => budget.attributes.name))
    case 'tag':
      return unique(store.tags.map((tag) => tag.attributes.tag))
    case 'currency':
      return unique(store.currencies.map((currency) => currency.attributes.code))
    case 'account-name':
      return unique(store.accounts.filter((account) => account.attributes.active !== false).map((account) => account.attributes.name))
    default:
      return []
  }
})
</script>
