<template>
  <Sheet :open="composer.open" :title="readOnly ? readOnly.title : isEdit ? 'Edit transaction' : 'New transaction'" :cancel-label="readOnly ? 'Close' : 'Cancel'" @close="closeComposer">
    <template v-if="!readOnly" #action>
      <button class="btn small primary" :disabled="!canSave || saving" @click="save">
        <span v-if="saving" class="spinner" />
        <span v-else>Save</span>
      </button>
    </template>

    <!-- Opening balances and reconciliations are Firefly's bookkeeping: saved as an edit they would turn into income
         from a new "Initial balance for …" payer -->
    <template v-if="readOnly">
      <div class="readonly-amount num">{{ money(signedAmount(composer.split), composer.split.currency_code, { signed: true }) }}</div>
      <div class="card flush readonly-facts">
        <div class="item"><span class="grow muted">Account</span><span>{{ readOnly.account?.attributes.name ?? readOnly.accountName }}</span></div>
        <div class="item"><span class="grow muted">Date</span><span>{{ shortDate(composer.split.date) }}</span></div>
      </div>
      <p class="small muted">{{ readOnly.note }}</p>
      <button v-if="readOnly.account" class="btn block" @click="openAccount">Open {{ readOnly.account.attributes.name }}</button>
    </template>

    <template v-else>
      <div v-if="!isEdit && templates.length && settings.features.templates" class="templates" aria-label="Templates">
        <button v-for="template in templates" :key="template.id" class="chip template-chip" @click="applyTemplate(template)">
          <Icon name="template" :size="14" /> {{ template.name }}
        </button>
      </div>

      <!-- Firefly wants every split of a group to have the same type, so a split keeps its own -->
      <div class="seg block type-seg" role="group" aria-label="Type">
        <button
          v-for="option in TYPES"
          :key="option.value"
          :aria-pressed="form.type === option.value"
          :class="{ on: form.type === option.value }"
          :disabled="isSplit && form.type !== option.value"
          @click="setType(option.value)"
        >
          {{ option.label }}
        </button>
      </div>

      <label class="amount-field" :class="form.type">
        <span class="sr-only">Amount</span>
        <span class="amount-sign">{{ form.type === 'withdrawal' ? '−' : form.type === 'deposit' ? '+' : '' }}</span>
        <input ref="amountInput" v-model="form.amount" :style="{ width: `${Math.max(4, form.amount.length + 1) * 0.65}em` }" inputmode="decimal" autocomplete="off" placeholder="0.00" aria-label="Amount" @keydown.enter="save" />
        <span class="amount-code">{{ sourceCurrency }}</span>
      </label>

      <label class="field">
        <span>Description</span>
        <SuggestInput v-model="form.description" :options="counterpartyNames" placeholder="What was it?" />
      </label>

      <div class="field-row">
        <label class="field">
          <span>{{ form.type === 'deposit' ? 'From' : 'Account' }}</span>
          <select v-if="form.type !== 'deposit'" v-model="form.source_id">
            <option v-for="account in ownAccountList" :key="account.id" :value="account.id">{{ account.attributes.name }}</option>
          </select>
          <SuggestInput v-else v-model="form.source_name" :options="revenueNames" placeholder="Payer" />
        </label>
        <label class="field">
          <span>{{ form.type === 'withdrawal' ? 'Paid to' : 'To' }}</span>
          <select v-if="form.type !== 'withdrawal'" v-model="form.destination_id">
            <option v-for="account in ownAccountList" :key="account.id" :value="account.id">{{ account.attributes.name }}</option>
          </select>
          <SuggestInput v-else v-model="form.destination_name" :options="expenseNames" placeholder="Payee" />
        </label>
      </div>

      <label v-if="needsForeign" class="field">
        <span>Amount in {{ destinationCurrency }}</span>
        <input v-model="form.foreign_amount" inputmode="decimal" :placeholder="foreignHint" autocomplete="off" />
      </label>

      <p v-if="blocker && form.amount" class="small muted blocker" role="status">{{ blocker }}</p>

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
          <SuggestInput v-model="form.tags" :options="tagNames" multiple placeholder="comma, separated" />
        </label>
      </div>
      <label v-else-if="settings.features.tags" class="field">
        <span>Tags</span>
        <SuggestInput v-model="form.tags" :options="tagNames" multiple placeholder="comma, separated" />
      </label>

      <label class="field">
        <span>Notes</span>
        <textarea v-model="form.notes" rows="2" />
      </label>

      <p v-if="isSplit" class="small muted">This is one split of {{ composer.split.split_count }}. The others stay as they are.</p>

      <button v-if="isEdit" class="btn block danger delete" :disabled="saving" @click="remove"><Icon name="trash" :size="18" /> {{ isSplit ? 'Delete this split' : 'Delete transaction' }}</button>
    </template>
  </Sheet>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Sheet from './Sheet.vue'
import Icon from './Icon.vue'
import SuggestInput from './SuggestInput.vue'
import { closeComposer, composer, saved } from '../lib/composer.js'
import { accountById, accountsOfType, assetAccounts, invalidate, isBookkeeping, load, loadAll, ownAccounts, signedAmount, store, vwcePrice } from '../lib/data.js'
import { del, get, post, put } from '../lib/api.js'
import { isoDate, money, parseAmount, shortDate } from '../lib/format.js'
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

const router = useRouter()
const isEdit = computed(() => Boolean(composer.split))
const isSplit = computed(() => composer.split?.split_count > 1)
const ownAccountList = computed(() => ownAccounts())
const isOwnId = (id) => Boolean(id) && ownAccountList.value.some((account) => String(account.id) === String(id))

const readOnly = computed(() => {
  const split = composer.split
  if (!split || !isBookkeeping(split)) return null
  // The user's account is the side that is not Firefly's initial balance / reconciliation account
  const mine = ['Initial balance account', 'Reconciliation account'].includes(split.source_type) ? split.destination_id : split.source_id
  const opening = split.type === 'opening balance'
  return {
    title: opening ? 'Opening balance' : 'Reconciliation',
    account: accountById(mine),
    accountName: String(mine) === String(split.destination_id) ? split.destination_name : split.source_name,
    note: opening ? 'Edit the opening balance from the account: open it, then Edit.' : 'Reconciliations are made in Firefly III and cannot be changed here.',
  }
})
const openAccount = () => {
  router.push(`/accounts/${readOnly.value.account.id}`)
  closeComposer()
}
const namesOf = (type) => [...new Set(accountsOfType(type).map((account) => account.attributes.name))]
const expenseNames = computed(() => namesOf('expense'))
const revenueNames = computed(() => namesOf('revenue'))
const counterpartyNames = computed(() => (form.type === 'deposit' ? revenueNames.value : expenseNames.value))
const tagNames = computed(() => store.tags.map((tag) => tag.attributes.tag))

const sourceCurrency = computed(() => accountById(form.type === 'deposit' ? form.destination_id : form.source_id)?.attributes.currency_code ?? 'EUR')
const destinationCurrency = computed(() => (form.type === 'transfer' ? accountById(form.destination_id)?.attributes.currency_code : sourceCurrency.value) ?? 'EUR')
const needsForeign = computed(() => form.type === 'transfer' && sourceCurrency.value !== destinationCurrency.value)
// VWCE is counted in shares to 4 places: "1.8169" is a share and a bit, not 18,169
const amountValue = computed(() => parseAmount(form.amount, { maxDecimals: sourceCurrency.value === 'VWCE' ? 4 : 2 }))
const foreignValue = computed(() => parseAmount(form.foreign_amount, { maxDecimals: destinationCurrency.value === 'VWCE' ? 4 : 2 }))
const foreignHint = computed(() => {
  const price = vwcePrice()
  const amount = amountValue.value
  if (!amount || !price) return ''
  if (destinationCurrency.value === 'VWCE' && sourceCurrency.value === 'EUR') return `≈ ${(amount / price).toFixed(4)} shares`
  if (sourceCurrency.value === 'VWCE' && destinationCurrency.value === 'EUR') return `≈ ${(amount * price).toFixed(2)}`
  return ''
})

// Why Save is off, in a few words ('' when it is on). Account ids must be in the lists shown: after switching type
// an id can still point at a payee
const blocker = computed(() => {
  if (!(amountValue.value > 0)) return Number.isNaN(amountValue.value) ? 'The amount does not read as a number' : 'Enter an amount above zero'
  if (!form.description.trim()) return 'Add a description'
  if (!form.date) return 'Pick a date'
  if (form.type !== 'deposit' && !isOwnId(form.source_id)) return 'Pick the account'
  if (form.type !== 'withdrawal' && !isOwnId(form.destination_id)) return 'Pick the account'
  if (form.type === 'withdrawal' && !form.destination_name.trim()) return 'Who was it paid to?'
  if (form.type === 'deposit' && !form.source_name.trim()) return 'Who paid it?'
  if (form.type === 'transfer' && String(form.source_id) === String(form.destination_id)) return 'Pick two different accounts'
  if (needsForeign.value && !(foreignValue.value > 0)) return `Enter the amount in ${destinationCurrency.value}`
  return ''
})
const canSave = computed(() => !blocker.value)

// Switching type moves the own account and the payee/payer to the fields the new type shows
function setType(type) {
  const old = form.type
  if (type === old) return
  const own = old === 'deposit' ? form.destination_id : form.source_id
  const name = old === 'withdrawal' ? form.destination_name : old === 'deposit' ? form.source_name : ''
  form.type = type
  if (type === 'deposit') {
    form.destination_id = isOwnId(own) ? own : ''
    form.source_name = name || form.source_name
  } else {
    form.source_id = isOwnId(own) ? own : ''
    if (type === 'withdrawal') form.destination_name = name || form.destination_name
    // A transfer goes to another own account
    else if (!isOwnId(form.destination_id) || String(form.destination_id) === String(form.source_id)) {
      form.destination_id = [...assetAccounts(), ...ownAccountList.value].find((account) => String(account.id) !== String(form.source_id))?.id ?? ''
    }
  }
  form.source_id ||= defaultAccount()
  form.destination_id ||= defaultAccount()
}
// An everyday account by default, not a loan
const defaultAccount = () => (assetAccounts()[0] ?? ownAccounts()[0])?.id ?? ''

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
    form.source_id ||= defaultAccount()
    form.destination_id ||= defaultAccount()
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
    amount: amountValue.value.toFixed(4),
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
      Object.assign(split, { foreign_amount: foreignValue.value.toFixed(4), ...(destination ? { foreign_currency_id: destination.id } : { foreign_currency_code: destinationCurrency.value }) })
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
      // Firefly refuses a group of several splits without a title
      const title = transactions.length > 1 ? { group_title: group.attributes.group_title || split.description } : {}
      await put(`transactions/${current.group_id}`, { apply_rules: false, ...title, transactions })
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
  const split = composer.split
  // One split of several: delete just that journal, the rest of the group stays
  const others = split.split_count - 1
  const question = others > 0 ? `Delete this split? The other ${others === 1 ? 'split stays' : `${others} splits stay`}. This cannot be undone.` : 'Delete this transaction? This cannot be undone.'
  if (!confirm(question)) return
  saving.value = true
  try {
    await del(others > 0 ? `transaction-journals/${split.transaction_journal_id}` : `transactions/${split.group_id}`)
    toast('Deleted')
    invalidate('accounts')
    saved()
  } catch {
    // The error toast comes from api()
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
  padding: 0;
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
.blocker {
  margin: -4px 2px 12px;
}
.readonly-amount {
  padding: 14px 0 18px;
  text-align: center;
  font: 700 40px/1.1 var(--font-display);
  letter-spacing: -0.03em;
}
.readonly-facts {
  margin-bottom: 12px;
}
</style>
