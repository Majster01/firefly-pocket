<template>
  <Sheet :open="composer.open" :title="isEdit ? 'Edit transaction' : 'New transaction'" @close="closeComposer">
    <template #action>
      <button class="btn small primary" :disabled="!canSave || saving" @click="save">
        <span v-if="saving" class="spinner" />
        <span v-else>Save</span>
      </button>
    </template>

    <div v-if="!isEdit && templates.length && settings.features.templates" class="templates" aria-label="Templates">
      <button v-for="template in templates" :key="template.id" class="chip template-chip" @click="applyTemplate(template)">
        <Icon name="template" :size="14" /> {{ template.name }}
      </button>
    </div>

    <div class="seg block type-seg" role="tablist">
      <button v-for="option in TYPES" :key="option.value" role="tab" :aria-selected="form.type === option.value" :class="{ on: form.type === option.value }" @click="form.type = option.value">
        {{ option.label }}
      </button>
    </div>

    <label class="amount-field" :class="form.type">
      <span class="sr-only">Amount</span>
      <span class="amount-sign">{{ form.type === 'withdrawal' ? '−' : form.type === 'deposit' ? '+' : '' }}</span>
      <input ref="amountInput" v-model="form.amount" :style="{ width: `${Math.max(4, form.amount.length + 0.5)}ch` }" inputmode="decimal" autocomplete="off" placeholder="0.00" aria-label="Amount" @keydown.enter="save" />
      <span class="amount-code">{{ sourceCurrency }}</span>
    </label>

    <label class="field">
      <span>Description</span>
      <input v-model="form.description" list="pocket-descriptions" autocomplete="off" placeholder="What was it?" />
      <datalist id="pocket-descriptions">
        <option v-for="name in counterpartyNames" :key="name" :value="name" />
      </datalist>
    </label>

    <div class="field-row">
      <label class="field">
        <span>{{ form.type === 'deposit' ? 'From' : 'Account' }}</span>
        <select v-if="form.type !== 'deposit'" v-model="form.source_id">
          <option v-for="account in ownAccountList" :key="account.id" :value="account.id">{{ account.attributes.name }}</option>
        </select>
        <input v-else v-model="form.source_name" list="pocket-revenue" placeholder="Payer" autocomplete="off" />
      </label>
      <label class="field">
        <span>{{ form.type === 'withdrawal' ? 'Paid to' : 'To' }}</span>
        <select v-if="form.type !== 'withdrawal'" v-model="form.destination_id">
          <option v-for="account in ownAccountList" :key="account.id" :value="account.id">{{ account.attributes.name }}</option>
        </select>
        <input v-else v-model="form.destination_name" list="pocket-expense" placeholder="Payee" autocomplete="off" />
      </label>
    </div>
    <datalist id="pocket-expense">
      <option v-for="account in accountsOfType('expense')" :key="account.id" :value="account.attributes.name" />
    </datalist>
    <datalist id="pocket-revenue">
      <option v-for="account in accountsOfType('revenue')" :key="account.id" :value="account.attributes.name" />
    </datalist>

    <label v-if="needsForeign" class="field">
      <span>Amount in {{ destinationCurrency }}</span>
      <input v-model="form.foreign_amount" inputmode="decimal" :placeholder="foreignHint" autocomplete="off" />
    </label>

    <div class="field-row">
      <label class="field">
        <span>Date</span>
        <input v-model="form.date" type="date" required />
      </label>
      <label class="field">
        <span>Category</span>
        <select v-model="form.category_name">
          <option value="">None</option>
          <option v-for="category in store.categories" :key="category.id" :value="category.attributes.name">{{ category.attributes.name }}</option>
        </select>
      </label>
    </div>

    <div v-if="form.type === 'withdrawal' && settings.features.budgets" class="field-row">
      <label class="field">
        <span>Budget</span>
        <select v-model="form.budget_id">
          <option value="">None</option>
          <option v-for="budget in store.budgets" :key="budget.id" :value="budget.id">{{ budget.attributes.name }}</option>
        </select>
      </label>
      <label v-if="settings.features.tags" class="field">
        <span>Tags</span>
        <input v-model="form.tags" list="pocket-tags" placeholder="comma, separated" autocomplete="off" />
      </label>
    </div>
    <label v-else-if="settings.features.tags" class="field">
      <span>Tags</span>
      <input v-model="form.tags" list="pocket-tags" placeholder="comma, separated" autocomplete="off" />
    </label>
    <datalist id="pocket-tags">
      <option v-for="tag in store.tags" :key="tag.id" :value="tag.attributes.tag" />
    </datalist>

    <label class="field">
      <span>Notes</span>
      <textarea v-model="form.notes" rows="2" />
    </label>

    <p v-if="composer.split?.split_count > 1" class="small muted">This is one split of {{ composer.split.split_count }}. The others stay as they are.</p>

    <button v-if="isEdit" class="btn block danger delete" :disabled="saving" @click="remove"><Icon name="trash" :size="18" /> Delete transaction</button>
  </Sheet>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import Sheet from './Sheet.vue'
import Icon from './Icon.vue'
import { closeComposer, composer, saved } from '../lib/composer.js'
import { accountById, accountsOfType, assetAccounts, invalidate, load, loadAll, ownAccounts, store, vwcePrice } from '../lib/data.js'
import { del, get, post, put } from '../lib/api.js'
import { isoDate } from '../lib/format.js'
import { settings } from '../lib/settings.js'
import { toast } from '../lib/toast.js'

const TYPES = [
  { value: 'withdrawal', label: 'Expense' },
  { value: 'deposit', label: 'Income' },
  { value: 'transfer', label: 'Transfer' },
]

const blank = () => ({
  type: 'withdrawal',
  amount: '',
  description: '',
  date: isoDate(),
  source_id: '',
  source_name: '',
  destination_id: '',
  destination_name: '',
  foreign_amount: '',
  category_name: '',
  budget_id: '',
  tags: '',
  notes: '',
})

const form = reactive(blank())
const saving = ref(false)
const templates = ref([])
const amountInput = ref(null)

const isEdit = computed(() => Boolean(composer.split))
const ownAccountList = computed(() => ownAccounts())
const parseAmount = (value) => Number(String(value ?? '').replace(/\s/g, '').replace(',', '.'))
const counterpartyNames = computed(() => [...new Set(accountsOfType(form.type === 'deposit' ? 'revenue' : 'expense').map((account) => account.attributes.name))].slice(0, 200))

const sourceCurrency = computed(() => accountById(form.type === 'deposit' ? form.destination_id : form.source_id)?.attributes.currency_code ?? 'EUR')
const destinationCurrency = computed(() => (form.type === 'transfer' ? accountById(form.destination_id)?.attributes.currency_code : sourceCurrency.value) ?? 'EUR')
const needsForeign = computed(() => form.type === 'transfer' && sourceCurrency.value !== destinationCurrency.value)
const foreignHint = computed(() => {
  const price = vwcePrice()
  const amount = parseAmount(form.amount)
  if (!amount || !price) return ''
  if (destinationCurrency.value === 'VWCE' && sourceCurrency.value === 'EUR') return `≈ ${(amount / price).toFixed(4)} shares`
  if (sourceCurrency.value === 'VWCE' && destinationCurrency.value === 'EUR') return `≈ ${(amount * price).toFixed(2)}`
  return ''
})

const canSave = computed(() => {
  const amount = parseAmount(form.amount)
  if (!(amount > 0) || !form.description.trim() || !form.date) return false
  if (form.type === 'withdrawal') return Boolean(form.source_id && form.destination_name.trim())
  if (form.type === 'deposit') return Boolean(form.destination_id && form.source_name.trim())
  return Boolean(form.source_id && form.destination_id && form.source_id !== form.destination_id && (!needsForeign.value || parseAmount(form.foreign_amount) > 0))
})

watch(
  () => composer.open,
  async (open) => {
    if (!open) return
    // Reset before anything awaits, so nothing typed while the lists load is thrown away
    Object.assign(form, blank())
    if (composer.split) fill(composer.split)
    else if (composer.preset) Object.assign(form, composer.preset)
    await loadAll('accounts', 'categories', 'budgets', 'tags')
    load('rates')
    // An everyday account by default, not a loan
    const defaultAccount = (assetAccounts()[0] ?? ownAccounts()[0])?.id ?? ''
    form.source_id ||= defaultAccount
    form.destination_id ||= defaultAccount
    if (!composer.split && settings.features.templates) {
      get('transaction-templates', { limit: 50 }, { quiet: true })
        .then((json) => (templates.value = json?.data ?? []))
        .catch(() => (templates.value = []))
    }
    await nextTick()
    if (!composer.split && matchMedia('(min-width: 900px)').matches) amountInput.value?.focus()
  },
)

function fill(split) {
  Object.assign(form, {
    type: split.type === 'opening balance' ? 'deposit' : split.type,
    amount: Number(split.amount).toFixed(split.currency_decimal_places ?? 2),
    description: split.description,
    date: split.date.slice(0, 10),
    source_id: split.source_id,
    source_name: split.source_name ?? '',
    destination_id: split.destination_id,
    destination_name: split.destination_name ?? '',
    foreign_amount: split.foreign_amount ? Number(split.foreign_amount).toString() : '',
    category_name: split.category_name ?? '',
    budget_id: split.budget_id ?? '',
    tags: (split.tags ?? []).join(', '),
    notes: split.notes ?? '',
  })
}

function applyTemplate(template) {
  const own = (id) => (id && accountById(id) && ['asset', 'liabilities'].includes(accountById(id).attributes.type) ? String(id) : '')
  const name = (id) => (id ? (accountById(id)?.attributes.name ?? '') : '')
  form.type = template.type ?? 'withdrawal'
  if (template.amount) form.amount = Number(template.amount).toFixed(2)
  form.description = template.description || template.name
  form.source_id = own(template.account_source_id) || form.source_id
  form.destination_id = own(template.account_destination_id) || form.destination_id
  form.destination_name = name(template.account_destination_id) || form.destination_name
  form.source_name = name(template.account_source_id) || form.source_name
  form.category_name = store.categories.find((category) => String(category.id) === String(template.category_id))?.attributes.name ?? ''
  form.budget_id = template.budget_id ? String(template.budget_id) : ''
  form.tags = (template.tags ?? []).map((tag) => tag.tag_id).join(', ')
  form.notes = template.notes ?? ''
}

function toSplit() {
  const split = {
    type: form.type,
    amount: parseAmount(form.amount).toFixed(4),
    description: form.description.trim(),
    date: `${form.date}T12:00:00`,
    category_name: form.category_name || null,
    tags: form.tags
      .split(',')
      .map((tag) => tag.trim())
      .filter(Boolean),
    notes: form.notes || null,
  }
  if (form.type === 'withdrawal') Object.assign(split, { source_id: form.source_id, destination_name: form.destination_name.trim(), budget_id: form.budget_id || null })
  if (form.type === 'deposit') Object.assign(split, { source_name: form.source_name.trim(), destination_id: form.destination_id })
  if (form.type === 'transfer') {
    Object.assign(split, { source_id: form.source_id, destination_id: form.destination_id })
    if (needsForeign.value) {
      const destination = store.currencies.find((currency) => currency.attributes.code === destinationCurrency.value)
      Object.assign(split, { foreign_amount: parseAmount(form.foreign_amount).toFixed(4), ...(destination ? { foreign_currency_id: destination.id } : { foreign_currency_code: destinationCurrency.value }) })
    }
  }
  return split
}

async function save() {
  if (!canSave.value || saving.value) return
  saving.value = true
  try {
    if (needsForeign.value) await load('currencies')
    const split = toSplit()
    if (isEdit.value) {
      const current = composer.split
      // Firefly drops the splits an update leaves out: send the others by id
      const group = (await get(`transactions/${current.group_id}`)).data
      const transactions = group.attributes.transactions.map((other) =>
        String(other.transaction_journal_id) === String(current.transaction_journal_id) ? { transaction_journal_id: other.transaction_journal_id, ...split } : { transaction_journal_id: other.transaction_journal_id },
      )
      await put(`transactions/${current.group_id}`, { apply_rules: false, transactions })
      toast('Saved')
    } else {
      await post('transactions', { error_if_duplicate_hash: false, apply_rules: true, transactions: [split] })
      toast('Transaction added')
    }
    invalidate('accounts', 'tags')
    saved()
  } catch {
    // The error toast comes from api()
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!confirm('Delete this transaction? This cannot be undone.')) return
  saving.value = true
  try {
    await del(`transactions/${composer.split.group_id}`)
    toast('Deleted')
    invalidate('accounts')
    saved()
  } finally {
    saving.value = false
  }
}
</script>

<style>
.templates {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  scrollbar-width: none;
  margin: 0 -16px 12px;
  padding: 0 16px 2px;
}
.template-chip {
  font-size: 13px;
  padding: 6px 12px;
  cursor: pointer;
}
.type-seg {
  margin-bottom: 8px;
}
.amount-field {
  display: flex;
  align-items: baseline;
  justify-content: center;
  gap: 6px;
  padding: 14px 0 18px;
}
.amount-field input {
  min-width: 0;
  max-width: 70%;
  border: 0;
  background: transparent;
  color: var(--text);
  text-align: center;
  font: 700 44px/1.1 var(--font-display);
  letter-spacing: -0.03em;
  font-variant-numeric: tabular-nums;
  outline: none;
}
.amount-field input::placeholder {
  color: color-mix(in srgb, var(--muted) 55%, transparent);
}
.amount-sign {
  font: 600 32px var(--font-display);
  color: var(--muted);
}
.amount-field.deposit .amount-sign {
  color: var(--income);
}
.amount-code {
  font-weight: 600;
  color: var(--muted);
}
.delete {
  margin-top: 8px;
}
</style>
