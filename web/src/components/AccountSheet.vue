<template>
  <Sheet :open="open" :title="account ? 'Edit account' : 'New account'" @close="$emit('close')">
    <template #action>
      <button class="btn small primary" :disabled="!form.name.trim() || saving" @click="save">Save</button>
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
          <option value="defaultAsset">Current account</option>
          <option value="savingAsset">Savings</option>
          <option value="sharedAsset">Shared</option>
          <option value="ccAsset">Credit card</option>
          <option value="cashWalletAsset">Cash</option>
        </select>
      </label>
      <label v-else-if="form.type === 'liabilities'" class="field">
        <span>Kind</span>
        <select v-model="form.liability_type">
          <option value="loan">Loan</option>
          <option value="debt">Debt</option>
          <option value="mortgage">Mortgage</option>
        </select>
      </label>
    </div>
    <template v-if="['asset', 'liabilities'].includes(form.type)">
      <div class="field-row">
        <label class="field">
          <span>Currency</span>
          <select v-model="form.currency_id">
            <option v-for="currency in store.currencies" :key="currency.id" :value="currency.id">{{ currency.attributes.code }} · {{ currency.attributes.name }}</option>
          </select>
        </label>
        <label v-if="!account" class="field"><span>Opening balance</span><input v-model="form.opening_balance" inputmode="decimal" placeholder="0.00" /></label>
      </div>
      <label class="field"><span>IBAN</span><input v-model="form.iban" autocomplete="off" autocapitalize="characters" /></label>
      <label class="row field"><span class="grow" style="margin: 0">Include in net worth</span><input v-model="form.include_net_worth" type="checkbox" class="switch" /></label>
    </template>
    <label class="field"><span>Notes</span><textarea v-model="form.notes" rows="2" /></label>
    <button v-if="account" class="btn block danger" @click="remove"><Icon name="trash" :size="18" /> Delete account</button>
  </Sheet>
</template>

<script setup>
import { reactive, ref, watch } from 'vue'
import Sheet from './Sheet.vue'
import Icon from './Icon.vue'
import { del, post, put } from '../lib/api.js'
import { invalidate, load, store } from '../lib/data.js'
import { isoDate } from '../lib/format.js'
import { toast } from '../lib/toast.js'

const props = defineProps({ open: Boolean, account: { type: Object, default: null } })
const emit = defineEmits(['close', 'saved', 'deleted'])
const saving = ref(false)
const form = reactive({})

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    await load('currencies')
    const attributes = props.account?.attributes ?? {}
    Object.assign(form, {
      name: attributes.name ?? '',
      type: attributes.type ?? 'asset',
      account_role: attributes.account_role ?? 'defaultAsset',
      liability_type: attributes.liability_type ?? 'loan',
      currency_id: attributes.currency_id ?? store.currencies.find((currency) => currency.attributes.code === 'EUR')?.id,
      opening_balance: '',
      iban: attributes.iban ?? '',
      include_net_worth: attributes.include_net_worth ?? true,
      notes: attributes.notes ?? '',
    })
  },
)

async function save() {
  saving.value = true
  try {
    const body = { name: form.name.trim(), notes: form.notes || null }
    if (['asset', 'liabilities'].includes(form.type)) {
      Object.assign(body, { currency_id: form.currency_id, iban: form.iban.replace(/\s/g, '') || null, include_net_worth: form.include_net_worth })
      if (form.type === 'asset') body.account_role = form.account_role
      if (form.type === 'liabilities') Object.assign(body, { liability_type: form.liability_type, liability_direction: 'debit', interest: '0', interest_period: 'monthly' })
    }
    if (props.account) {
      await put(`accounts/${props.account.id}`, body)
    } else {
      body.type = form.type
      if (form.opening_balance) Object.assign(body, { opening_balance: String(Number(form.opening_balance.replace(',', '.'))), opening_balance_date: isoDate() })
      await post('accounts', body)
    }
    toast('Saved')
    invalidate('accounts')
    await load('accounts')
    emit('saved')
    emit('close')
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!confirm(`Delete ${props.account.attributes.name} and all its transactions?`)) return
  await del(`accounts/${props.account.id}`)
  invalidate('accounts')
  toast('Deleted')
  emit('deleted')
  emit('close')
}
</script>
