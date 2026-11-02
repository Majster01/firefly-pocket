<template>
  <div>
    <PageHeader title="Templates">
      <button class="btn small" @click="open(null)"><Icon name="plus" :size="18" /> Add</button>
    </PageHeader>
    <p class="small muted intro">Transactions you enter often. They show up as chips when you add a transaction.</p>

    <div v-if="loadError" class="banner bad"><Icon name="alert" :size="18" /> {{ loadError }}</div>
    <div v-else-if="!templates" class="loading"><span class="spinner" /></div>
    <div v-else-if="!templates.length" class="card empty"><h3>No templates yet</h3></div>
    <div v-else class="card flush">
      <div v-for="template in templates" :key="template.id" class="item">
        <span class="avatar"><Icon name="template" :size="19" /></span>
        <button class="grow template-main" @click="open(template)">
          <span class="item-title" style="display: block">{{ template.name }}</span>
          <span class="item-sub" style="display: block">{{ describe(template) }}</span>
        </button>
        <span class="item-end num blurable">{{ template.amount ? money(template.amount) : '' }}</span>
        <button class="btn small primary" @click="use(template)">Use</button>
      </div>
    </div>

    <Sheet :open="Boolean(editing)" :title="editing?.id ? 'Edit template' : 'New template'" @close="editing = null">
      <template #action>
        <button class="btn small primary" :disabled="!editing?.name.trim() || saving" @click="save">
          <span v-if="saving" class="spinner" />
          <span v-else>Save</span>
        </button>
      </template>
      <template v-if="editing">
        <label class="field"><span>Name</span><input v-model="editing.name" placeholder="Coffee" /></label>
        <div class="seg block" style="margin-bottom: 12px">
          <button v-for="option in TYPES" :key="option.value" :class="{ on: editing.type === option.value }" :aria-pressed="editing.type === option.value" @click="editing.type = option.value">{{ option.label }}</button>
        </div>
        <div class="field-row">
          <label class="field"><span>Amount</span><input v-model="editing.amount" inputmode="decimal" placeholder="Any" /></label>
          <label class="field"><span>Description</span><input v-model="editing.description" /></label>
        </div>
        <div class="field-row">
          <label class="field">
            <span>From</span>
            <select v-model="editing.account_source_id">
              <option value="">Any</option>
              <option v-for="account in sourceAccounts" :key="account.id" :value="account.id">{{ account.attributes.name }}</option>
            </select>
          </label>
          <label class="field">
            <span>To</span>
            <select v-model="editing.account_destination_id">
              <option value="">Any</option>
              <option v-for="account in destinationAccounts" :key="account.id" :value="account.id">{{ account.attributes.name }}</option>
            </select>
          </label>
        </div>
        <div class="field-row">
          <label class="field">
            <span>Category</span>
            <select v-model="editing.category_id">
              <option value="">None</option>
              <option v-for="category in store.categories" :key="category.id" :value="category.id">{{ category.attributes.name }}</option>
            </select>
          </label>
          <label class="field">
            <span>Budget</span>
            <select v-model="editing.budget_id">
              <option value="">None</option>
              <option v-for="budget in store.budgets" :key="budget.id" :value="budget.id">{{ budget.attributes.name }}</option>
            </select>
          </label>
        </div>
        <label class="field"><span>Tags</span><input v-model="editing.tags" placeholder="comma, separated" /></label>
        <button v-if="editing.id" class="btn block danger" :disabled="saving" @click="remove">Delete template</button>
      </template>
    </Sheet>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import Sheet from '../components/Sheet.vue'
import { del, get, post, put } from '../lib/api.js'
import { accountById, accountsOfType, loadAll, ownAccounts, store } from '../lib/data.js'
import { money, parseAmount } from '../lib/format.js'
import { compose } from '../lib/composer.js'
import { toast, toastError } from '../lib/toast.js'

const TYPES = [
  { value: 'withdrawal', label: 'Expense' },
  { value: 'deposit', label: 'Income' },
  { value: 'transfer', label: 'Transfer' },
]
const templates = ref(null)
const editing = ref(null)
const saving = ref(false)
const loadError = ref('')

const sourceAccounts = computed(() => (editing.value?.type === 'deposit' ? accountsOfType('revenue') : ownAccounts()))
const destinationAccounts = computed(() => (editing.value?.type === 'withdrawal' ? accountsOfType('expense') : ownAccounts()))
const name = (id) => (id ? accountById(id)?.attributes.name : null)
const describe = (template) =>
  [template.description, name(template.account_source_id) && name(template.account_destination_id) ? `${name(template.account_source_id)} → ${name(template.account_destination_id)}` : name(template.account_destination_id) ?? name(template.account_source_id), store.categories.find((category) => String(category.id) === String(template.category_id))?.attributes.name]
    .filter(Boolean)
    .join(' · ')

// Switching the type swaps the account lists: an account the new lists do not show would be saved unseen
watch(
  () => editing.value?.type,
  (type, previous) => {
    const value = editing.value
    // Only a switch in the open sheet: opening a template keeps what it has
    if (!value || !previous || !store.loaded.accounts) return
    const listed = (list, id) => list.some((account) => String(account.id) === String(id))
    if (value.account_source_id && !listed(sourceAccounts.value, value.account_source_id)) value.account_source_id = ''
    if (value.account_destination_id && !listed(destinationAccounts.value, value.account_destination_id)) value.account_destination_id = ''
  },
)

async function reload() {
  try {
    templates.value = (await get('transaction-templates', { limit: 100 }))?.data ?? []
    loadError.value = ''
  } catch (error) {
    if (!templates.value) loadError.value = error.message
  }
}

const open = (template) =>
  (editing.value = template
    ? { ...template, amount: template.amount ? String(template.amount) : '', account_source_id: template.account_source_id ?? '', account_destination_id: template.account_destination_id ?? '', category_id: template.category_id ?? '', budget_id: template.budget_id ?? '', tags: (template.tags ?? []).map((tag) => tag.tag_id).join(', ') }
    : { id: null, name: '', type: 'withdrawal', amount: '', description: '', account_source_id: '', account_destination_id: '', category_id: '', budget_id: '', notes: '', tags: '' })

async function save() {
  if (saving.value) return
  const value = editing.value
  const text = String(value.amount ?? '').trim()
  const amount = text ? parseAmount(text) : null
  if (text && !(amount > 0)) {
    toastError('Enter the amount like 4,50, or leave it empty for any amount.')
    return
  }
  const body = {
    name: value.name.trim(),
    type: value.type,
    amount,
    description: value.description || null,
    account_source_id: value.account_source_id || null,
    account_destination_id: value.account_destination_id || null,
    category_id: value.category_id || null,
    budget_id: value.budget_id || null,
    notes: value.notes || null,
    tags: value.tags
      .split(',')
      .map((tag) => tag.trim())
      .filter(Boolean),
  }
  saving.value = true
  try {
    if (value.id) await put(`transaction-templates/${value.id}`, body)
    else await post('transaction-templates', body)
    toast('Saved')
    editing.value = null
  } catch {
    // The error toast comes from api()
  } finally {
    saving.value = false
    reload()
  }
}

async function remove() {
  if (!confirm(`Delete the template ${editing.value.name}?`)) return
  saving.value = true
  try {
    await del(`transaction-templates/${editing.value.id}`)
    editing.value = null
  } catch {
    // Toasted by api()
  } finally {
    saving.value = false
    reload()
  }
}

function use(template) {
  const own = (id) => (id && ['asset', 'liabilities'].includes(accountById(id)?.attributes.type) ? String(id) : undefined)
  compose({
    type: template.type ?? 'withdrawal',
    amount: template.amount ? Number(template.amount).toFixed(2) : '',
    description: template.description || template.name,
    ...(own(template.account_source_id) ? { source_id: own(template.account_source_id) } : {}),
    ...(own(template.account_destination_id) ? { destination_id: own(template.account_destination_id) } : {}),
    destination_name: template.type === 'withdrawal' ? (name(template.account_destination_id) ?? '') : '',
    source_name: template.type === 'deposit' ? (name(template.account_source_id) ?? '') : '',
    category_name: store.categories.find((category) => String(category.id) === String(template.category_id))?.attributes.name ?? '',
    budget_id: template.budget_id ? String(template.budget_id) : '',
    tags: (template.tags ?? []).map((tag) => tag.tag_id).join(', '),
  })
}

onMounted(() => {
  loadAll('accounts', 'categories', 'budgets').catch(() => null)
  reload()
})
</script>

<style>
.intro {
  margin: -4px 4px 14px;
}
.template-main {
  border: 0;
  background: transparent;
  text-align: left;
  padding: 0;
  min-width: 0;
}
</style>
