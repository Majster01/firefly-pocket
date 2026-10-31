<template>
  <Sheet :open="open" :title="account ? 'Edit account' : 'New account'" @close="$emit('close')">
    <template #action>
      <button class="btn small primary" :disabled="!canSave || saving" @click="save">Save</button>
    </template>
    <label class="field"><span>Name</span><input v-model="form.name" autocomplete="off" /></label>
    <div class="field-row">
      <label class="field">
        <span>Type</span>
        <select v-model="form.type" :disabled="Boolean(account)">
          <option value="asset">Asset</option>
          <option value="liabilities">Liability</option>
          <option value="expense">Expense account</option>
          <option value="revenue">Revenue account</option>
        </select>
      </label>
      <label v-if="form.type === 'asset'" class="field">
        <span>Role</span>
        <select v-model="form.account_role">
          <option v-for="(label, value) in ROLES" :key="value" :value="value">{{ label }}</option>
        </select>
      </label>
      <label v-else-if="form.type === 'liabilities'" class="field">
        <span>Kind</span>
        <select v-model="form.liability_type">
          <option v-for="(label, value) in LIABILITY_KINDS" :key="value" :value="value">{{ label }}</option>
        </select>
      </label>
    </div>
    <template v-if="['asset', 'liabilities'].includes(form.type)">
      <div class="field-row">
        <label class="field">
          <span>Currency</span>
          <select v-model="form.currency_id">
            <option v-for="currency in currencyList" :key="currency.id" :value="currency.id">{{ currency.attributes.code }} · {{ currency.attributes.name }}</option>
          </select>
        </label>
        <label class="field">
          <span>{{ form.type === 'liabilities' ? 'Owed at the start' : 'Opening balance' }}</span>
          <input v-model="form.opening_balance" inputmode="decimal" :placeholder="isShares ? '0.0000' : '0.00'" />
        </label>
      </div>
      <!-- Sent back as they are: an edit must not reset the interest or flip which way the debt goes -->
      <div v-if="form.type === 'liabilities'" class="field-row">
        <label class="field"><span>Interest %</span><input v-model="form.interest" inputmode="decimal" placeholder="0" /></label>
        <label class="field">
          <span>Per</span>
          <select v-model="form.interest_period">
            <option value="daily">Day</option>
            <option value="monthly">Month</option>
            <option value="yearly">Year</option>
          </select>
        </label>
      </div>
      <p v-if="openingInvalid" class="small bad">The opening balance does not read as a number.</p>
      <label class="field"><span>IBAN</span><input v-model="form.iban" autocomplete="off" autocapitalize="characters" /></label>
      <label class="row field"><span class="grow" style="margin: 0">Include in net worth</span><input v-model="form.include_net_worth" type="checkbox" class="switch" /></label>
    </template>
    <label class="field"><span>Notes</span><textarea v-model="form.notes" rows="2" /></label>
    <button v-if="account" class="btn block danger" @click="remove"><Icon name="trash" :size="18" /> Delete account</button>
  </Sheet>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import Sheet from './Sheet.vue'
import Icon from './Icon.vue'
import { del, post, put } from '../lib/api.js'
import { invalidate, LIABILITY_KINDS, load, ROLES, store } from '../lib/data.js'
import { isoDate, parseAmount } from '../lib/format.js'
import { toast } from '../lib/toast.js'

const props = defineProps({ open: Boolean, account: { type: Object, default: null } })
const emit = defineEmits(['close', 'saved', 'deleted'])
const saving = ref(false)

// A loan's opening balance comes back negative (the debt); the field shows what was owed
const openingText = (attributes) => {
  const value = Number(attributes.opening_balance ?? 0)
  if (!value) return ''
  return String(attributes.type === 'liabilities' ? Math.abs(value) : value)
}
const fromAccount = (attributes = {}) => ({
  name: attributes.name ?? '',
  type: attributes.type ?? 'asset',
  account_role: attributes.account_role ?? 'defaultAsset',
  liability_type: attributes.liability_type ?? 'loan',
  liability_direction: attributes.liability_direction ?? 'debit',
  interest: attributes.interest ? String(Number(attributes.interest)) : '',
  interest_period: attributes.interest_period ?? 'monthly',
  currency_id: attributes.currency_id ?? store.currencies.find((currency) => currency.attributes.code === 'EUR')?.id ?? '',
  opening_balance: openingText(attributes),
  iban: attributes.iban ?? '',
  include_net_worth: attributes.include_net_worth ?? true,
  notes: attributes.notes ?? '',
})
// Filled before anything awaits: the template reads form.name on first render
const form = reactive(fromAccount())
let initialOpening = ''

const currencyList = computed(() => {
  // An account in a currency switched off since still lists its own
  const list = store.currencies
  const own = props.account?.attributes
  if (!own?.currency_id || list.some((currency) => String(currency.id) === String(own.currency_id))) return list
  return [...list, { id: own.currency_id, attributes: { code: own.currency_code, name: own.currency_name } }]
})
const isShares = computed(() => currencyList.value.find((currency) => String(currency.id) === String(form.currency_id))?.attributes.code === 'VWCE')
const openingValue = computed(() => (form.opening_balance.trim() ? parseAmount(form.opening_balance, { maxDecimals: isShares.value ? 4 : 2 }) : null))
const openingInvalid = computed(() => openingValue.value !== null && !Number.isFinite(openingValue.value))
const interestValue = computed(() => (form.interest.trim() ? parseAmount(form.interest, { maxDecimals: 4 }) : 0))
const canSave = computed(() => Boolean(form.name.trim()) && !openingInvalid.value && Number.isFinite(interestValue.value))

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    Object.assign(form, fromAccount(props.account?.attributes))
    initialOpening = form.opening_balance
    await load('currencies')
    form.currency_id ||= store.currencies.find((currency) => currency.attributes.code === 'EUR')?.id ?? ''
  },
)

async function save() {
  if (!canSave.value || saving.value) return
  saving.value = true
  try {
    const body = { name: form.name.trim(), notes: form.notes || null }
    if (['asset', 'liabilities'].includes(form.type)) {
      Object.assign(body, { currency_id: form.currency_id, iban: form.iban.replace(/\s/g, '') || null, include_net_worth: form.include_net_worth })
      if (form.type === 'asset') body.account_role = form.account_role
      // Firefly needs the direction with every liability update that touches the opening balance
      if (form.type === 'liabilities') Object.assign(body, { liability_type: form.liability_type, liability_direction: form.liability_direction, interest: String(interestValue.value), interest_period: form.interest_period })
      // Sent only when typed or changed; Firefly wants the date with it
      if (openingValue.value !== null && (!props.account || form.opening_balance !== initialOpening)) {
        const date = props.account?.attributes.opening_balance_date?.slice(0, 10) ?? isoDate()
        Object.assign(body, { opening_balance: String(openingValue.value), opening_balance_date: date })
      }
    }
    if (props.account) {
      await put(`accounts/${props.account.id}`, body)
    } else {
      body.type = form.type
      await post('accounts', body)
    }
    toast('Saved')
    invalidate('accounts')
    await load('accounts')
    emit('saved')
    emit('close')
  } catch {
    // The error toast comes from api()
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!confirm(`Delete ${props.account.attributes.name} and all its transactions?`)) return
  try {
    await del(`accounts/${props.account.id}`)
  } catch {
    return
  }
  invalidate('accounts')
  toast('Deleted')
  emit('deleted')
  emit('close')
}
</script>
