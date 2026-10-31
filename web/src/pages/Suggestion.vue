<template>
  <div>
    <PageHeader :title="item ? name(item) : 'Suggestion'" back="/suggestions" />

    <div v-if="loadError" class="card empty">
      <template v-if="loadError.status === 404">
        <h3>This suggestion was already handled</h3>
        <p>It was approved, set or rejected, maybe on another device.</p>
      </template>
      <template v-else>
        <h3>Could not load the suggestion</h3>
        <p>{{ loadError.message }}</p>
        <button class="btn small" @click="reload">Retry</button>
      </template>
      <p><RouterLink to="/suggestions" class="small review-back">Back to suggestions</RouterLink></p>
    </div>
    <div v-else-if="!item" class="loading"><span class="spinner" /></div>
    <div v-else class="review">
      <div class="review-main">
        <section class="card">
          <div class="row wrap">
            <span class="chip">Suggests {{ item.attributes.category }}</span>
            <Confidence :value="item.attributes.confidence" :low="item.attributes.low_confidence" />
            <span class="chip">matched on {{ item.attributes.basis }}</span>
          </div>
          <p v-if="item.attributes.low_confidence" class="banner warn small review-banner">
            <Icon name="alert" :size="18" />
            <span>The suggester is unsure. Check the category; the condition matches this exact description only.</span>
          </p>
          <p class="review-reason"><Icon name="sparkles" :size="16" /> {{ item.attributes.reason }}</p>
          <p v-if="item.attributes.company" class="small muted">{{ item.attributes.company }}</p>
          <p class="tiny muted">{{ item.attributes.model }} · {{ shortDate(item.attributes.created) }}</p>
        </section>

        <h2 class="section-title">Transactions ({{ item.attributes.transactions.length }})</h2>
        <div class="card flush">
          <button v-for="transaction in item.attributes.transactions" :key="transaction.journal_id" class="item" :disabled="!transaction.date || opening === transaction.journal_id" @click="open(transaction)">
            <span class="avatar">{{ initials(transaction.destination_name ?? transaction.description) }}</span>
            <span class="grow">
              <span class="item-title ellipsis" style="display: block">{{ transaction.description ?? `#${transaction.transaction_id}` }}</span>
              <span class="item-sub" style="display: block">{{ transaction.date ? `${transaction.destination_name} · ${shortDate(transaction.date)}` : 'Deleted' }}</span>
            </span>
            <span v-if="opening === transaction.journal_id" class="spinner" style="width: 16px; height: 16px" />
            <span v-else class="item-end num">{{ transaction.amount ? money(signed(transaction), transaction.currency_code) : '' }}</span>
          </button>
        </div>
      </div>

      <div class="review-side">
        <section class="card">
          <h2 class="card-title">Category</h2>
          <div v-if="newCategory" class="new-category">
            <input ref="categoryInput" v-model="category" class="input" aria-label="New category name" placeholder="New category name" />
            <button class="btn small" @click="listCategories">List</button>
          </div>
          <select v-else :value="category" class="input" aria-label="Category" @change="chooseCategory($event.target.value)">
            <option v-for="option in store.categories" :key="option.id" :value="option.attributes.name">{{ option.attributes.name }}</option>
            <!-- The suggester may propose a category Firefly does not have yet; approving creates it -->
            <option v-if="suggestedIsNew" :value="item.attributes.category">{{ item.attributes.category }} (new)</option>
            <option :value="NEW_CATEGORY">New category…</option>
          </select>

          <h2 class="card-title conditions-title">Conditions <span class="tiny">any of them</span></h2>
          <div v-for="(trigger, index) in triggers" :key="index" class="trigger">
            <select :value="trigger.type" class="input" :aria-label="`Condition ${index + 1} type`" @change="setType(trigger, $event.target.value)">
              <option v-for="type in TRIGGER_TYPES" :key="type.value" :value="type.value">{{ type.label }}</option>
            </select>
            <select v-if="trigger.type === 'destination_account_id'" v-model="trigger.value" class="input" :aria-label="`Condition ${index + 1} payee account`">
              <option value="" disabled>Choose account…</option>
              <option v-for="account in accountsOfType('expense')" :key="account.id" :value="String(account.id)">{{ account.attributes.name }}</option>
            </select>
            <input v-else v-model="trigger.value" class="input" autocapitalize="off" :aria-label="`Condition ${index + 1} value`" />
            <button class="btn icon small" aria-label="Remove condition" :disabled="triggers.length === 1" @click="triggers.splice(index, 1)"><Icon name="x" :size="16" /></button>
          </div>
          <button class="btn small" @click="triggers.push({ type: 'description_contains', value: '' })"><Icon name="plus" :size="16" /> Condition</button>

          <p class="small preview" aria-live="polite">
            <Icon name="search" :size="15" />
            <span v-if="!triggersFilled" class="muted">Fill in every condition</span>
            <span v-else-if="preview === null" class="muted">Counting matches…</span>
            <span v-else-if="preview.error" class="bad">{{ preview.error }}</span>
            <span v-else>
              Matches <strong>{{ preview.uncategorized_count }}</strong> uncategorized
              <template v-if="preview.categorized_count"> · {{ preview.categorized_count }} already categorized stay as they are</template>
            </span>
          </p>

          <button class="btn primary block" :disabled="Boolean(busy) || !valid" @click="approve">
            <span v-if="busy === 'approve'" class="spinner" />
            <span v-else>Approve as rule</span>
          </button>
          <button class="btn block review-secondary" :disabled="Boolean(busy) || !category.trim()" @click="categorizeOnly">
            <span v-if="busy === 'categorize'" class="spinner" />
            <span v-else>Set category only</span>
          </button>
          <button class="btn block danger review-secondary" :disabled="Boolean(busy)" @click="reject">
            <span v-if="busy === 'reject'" class="spinner" />
            <template v-else><Icon name="x" :size="17" /> Reject</template>
          </button>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import Confidence from '../components/Confidence.vue'
import { del, get, post } from '../lib/api.js'
import { accountsOfType, flatten, invalidate, loadAll, store } from '../lib/data.js'
import { initials, money, shortDate } from '../lib/format.js'
import { composer, edit } from '../lib/composer.js'
import { toast } from '../lib/toast.js'
import { badges } from '../lib/badges.js'

// RuleSuggestionService::TRIGGER_TYPES
const TRIGGER_TYPES = [
  { value: 'description_contains', label: 'Description contains' },
  { value: 'destination_account_contains', label: 'Payee contains' },
  { value: 'destination_account_is', label: 'Payee is' },
  { value: 'destination_account_id', label: 'Payee account' },
]
const NEW_CATEGORY = '\u0000new' // the "New category…" choice, never a real name

const route = useRoute()
const router = useRouter()
const item = ref(null)
const loadError = ref(null)
const category = ref('')
const newCategory = ref(false)
const categoryInput = ref(null)
const triggers = ref([])
const preview = ref(null)
const busy = ref(null)
const opening = ref(null)

// Without a company (a person, an unknown shop) show the bank's own words, title-cased when they are all one case
const titleCase = (text) =>
  text === text.toUpperCase() || text === text.toLowerCase()
    ? text.replace(/\S+/g, (word) => (/\d/.test(word) ? word.toUpperCase() : word[0].toUpperCase() + word.slice(1).toLowerCase()))
    : text
const name = (suggestion) => suggestion.attributes.company?.split(' — ')[0] ?? titleCase(suggestion.attributes.transactions?.[0]?.description ?? suggestion.attributes.title)
// Money spent is negative, like the transaction lists
const signed = (transaction) => (transaction.type === 'deposit' ? 1 : -1) * Number(transaction.amount ?? 0)

const suggestedIsNew = computed(() => item.value && !store.categories.some((option) => option.attributes.name === item.value.attributes.category))
// A payee account condition must name an expense account that exists: anything else writes a rule that never matches
const triggerFilled = (trigger) =>
  trigger.type === 'destination_account_id' ? accountsOfType('expense').some((account) => String(account.id) === String(trigger.value)) : Boolean(String(trigger.value).trim())
const triggersFilled = computed(() => triggers.value.length > 0 && triggers.value.every(triggerFilled))
const valid = computed(() => Boolean(category.value.trim()) && triggersFilled.value)

function chooseCategory(value) {
  if (value !== NEW_CATEGORY) {
    category.value = value
    return
  }
  newCategory.value = true
  category.value = ''
  nextTick(() => categoryInput.value?.focus())
}
function listCategories() {
  newCategory.value = false
  category.value = item.value.attributes.category
}

// An account id and a text are not interchangeable: "hoodburger" as an account id would be a garbage condition
function setType(trigger, type) {
  if (type === 'destination_account_id' || trigger.type === 'destination_account_id') trigger.value = ''
  trigger.type = type
}

// Each count answers the conditions it was asked for; a slower earlier answer must not overwrite a newer one.
// A payee condition only counts as filled once the accounts are in, so count again when that happens too.
let previewTimer
let previewRequest = 0
watch(
  [triggers, triggersFilled],
  () => {
    clearTimeout(previewTimer)
    const request = ++previewRequest
    preview.value = null
    if (!triggersFilled.value) return
    previewTimer = setTimeout(async () => {
      try {
        const result = (await post('rule-suggestions/preview', { triggers: triggers.value }, { quiet: true })).data
        if (request === previewRequest) preview.value = result
      } catch (error) {
        if (request === previewRequest) preview.value = { error: error.message }
      }
    }, 400)
  },
  { deep: true },
)

function done(message) {
  toast(message)
  badges.suggestions = Math.max(0, badges.suggestions - 1)
  router.replace('/suggestions')
}

// The api already toasted the error; a suggestion handled elsewhere in the meantime shows the "already handled" card
function failed(error) {
  if (error?.status === 404) loadError.value = error
}

const plural = (count, word) => `${count} ${count === 1 ? word : `${word}s`}`

async function approve() {
  if (busy.value) return
  busy.value = 'approve'
  try {
    const result = (await post(`rule-suggestions/${item.value.id}/approve`, { category: category.value.trim(), triggers: triggers.value })).data
    invalidate('categories')
    const rule = `Category: ${category.value.trim()}`
    const added = result.is_rule_created ? `Rule ${rule} created` : result.triggers_added_count ? `${plural(result.triggers_added_count, 'condition')} added to ${rule}` : `${rule} already had these conditions`
    done(`${added} · ${plural(result.transactions_count, 'transaction')} categorized`)
  } catch (error) {
    failed(error)
  } finally {
    busy.value = null
  }
}

async function categorizeOnly() {
  if (busy.value) return
  busy.value = 'categorize'
  try {
    const result = (await post(`rule-suggestions/${item.value.id}/categorize`, { category: category.value.trim() })).data
    invalidate('categories')
    done(`${plural(result.transactions_count, 'transaction')} categorized as ${category.value.trim()}`)
  } catch (error) {
    failed(error)
  } finally {
    busy.value = null
  }
}

async function reject() {
  if (busy.value || !confirm('Reject this suggestion? The suggester will not propose it again.')) return
  busy.value = 'reject'
  try {
    await del(`rule-suggestions/${item.value.id}`)
    done('Rejected. The suggester will not propose it again.')
  } catch (error) {
    failed(error)
  } finally {
    busy.value = null
  }
}

// The composer edits a whole split: the suggestion only carries a few of its fields
async function open(transaction) {
  if (opening.value) return
  opening.value = transaction.journal_id
  try {
    const splits = flatten([(await get(`transactions/${transaction.transaction_id}`)).data])
    const split = splits.find((candidate) => String(candidate.transaction_journal_id) === String(transaction.journal_id))
    if (split) edit(split)
  } catch {
    // Toasted by the api
  } finally {
    opening.value = null
  }
}

async function reload() {
  loadError.value = null
  try {
    item.value = (await get(`rule-suggestions/${route.params.id}`, undefined, { quiet: true })).data
  } catch (error) {
    loadError.value = error
    return
  }
  category.value = item.value.attributes.category
  triggers.value = item.value.attributes.triggers.map((trigger) => ({ ...trigger }))
}

// After an edit in the composer the transactions may read differently; the form keeps what the user chose
watch(
  () => composer.version,
  async () => {
    try {
      item.value = (await get(`rule-suggestions/${route.params.id}`, undefined, { quiet: true })).data
    } catch (error) {
      failed(error)
    }
  },
)

onMounted(() => {
  loadAll('categories', 'accounts').catch(() => null)
  reload()
})
</script>

<style>
.review {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 12px;
}
.review-main,
.review-side {
  min-width: 0;
}
.review .card + .card {
  margin-top: 0;
}
.review-banner {
  margin: 12px 0 0;
}
.review-reason {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  margin: 14px 0 6px;
  font-size: 16px;
}
.review-reason svg {
  flex-shrink: 0;
  margin-top: 3px;
  color: var(--accent);
}
.conditions-title {
  margin-top: 18px;
}
.trigger {
  display: grid;
  grid-template-columns: 1fr;
  gap: 6px;
  padding: 10px 0;
  border-bottom: 1px solid var(--line);
  margin-bottom: 10px;
  position: relative;
}
.trigger .btn {
  position: absolute;
  top: 10px;
  right: 0;
}
.trigger select:first-child {
  width: calc(100% - 44px);
}
.new-category {
  display: flex;
  gap: 8px;
  align-items: center;
}
.new-category .input {
  flex: 1;
  min-width: 0;
}
.review-back {
  color: var(--accent);
  font-weight: 600;
}
.preview {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  margin: 14px 0;
}
.preview svg {
  flex-shrink: 0;
  margin-top: 2px;
}
.review-secondary {
  margin-top: 10px;
}
@media (min-width: 900px) {
  .review {
    grid-template-columns: minmax(0, 1.4fr) minmax(320px, 1fr);
    align-items: start;
    gap: 16px;
  }
  .review-side {
    position: sticky;
    top: 90px;
  }
}
</style>
