<template>
  <div>
    <PageHeader :title="item?.attributes.company?.split(' — ')[0] ?? 'Suggestion'" back="/suggestions" />

    <div v-if="!item" class="loading"><span class="spinner" /></div>
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
          <div v-for="transaction in item.attributes.transactions" :key="transaction.journal_id" class="item">
            <span class="avatar">{{ initials(transaction.destination_name ?? transaction.description) }}</span>
            <span class="grow">
              <span class="item-title ellipsis" style="display: block">{{ transaction.description ?? `#${transaction.transaction_id}` }}</span>
              <span class="item-sub" style="display: block">{{ transaction.destination_name }} · {{ shortDate(transaction.date) }}</span>
            </span>
            <span class="item-end num">{{ transaction.amount ? money(-transaction.amount, transaction.currency_code) : '' }}</span>
          </div>
        </div>
      </div>

      <div class="review-side">
        <section class="card">
          <h2 class="card-title">Category</h2>
          <select v-model="category" class="input">
            <option v-for="option in store.categories" :key="option.id" :value="option.attributes.name">{{ option.attributes.name }}</option>
            <option v-if="category && !store.categories.some((option) => option.attributes.name === category)" :value="category">{{ category }} (new)</option>
          </select>

          <h2 class="card-title conditions-title">Conditions <span class="tiny">any of them</span></h2>
          <div v-for="(trigger, index) in triggers" :key="index" class="trigger">
            <select v-model="trigger.type" class="input">
              <option v-for="type in TRIGGER_TYPES" :key="type.value" :value="type.value">{{ type.label }}</option>
            </select>
            <select v-if="trigger.type === 'destination_account_id'" v-model="trigger.value" class="input">
              <option v-for="account in accountsOfType('expense')" :key="account.id" :value="String(account.id)">{{ account.attributes.name }}</option>
            </select>
            <input v-else v-model="trigger.value" class="input" autocapitalize="off" />
            <button class="btn icon small" aria-label="Remove condition" :disabled="triggers.length === 1" @click="triggers.splice(index, 1)"><Icon name="x" :size="16" /></button>
          </div>
          <button class="btn small" @click="triggers.push({ type: 'description_contains', value: '' })"><Icon name="plus" :size="16" /> Condition</button>

          <p class="small preview">
            <Icon name="search" :size="15" />
            <span v-if="preview === null" class="muted">Counting matches…</span>
            <span v-else-if="preview.error" class="bad">{{ preview.error }}</span>
            <span v-else>
              Matches <strong>{{ preview.uncategorized_count }}</strong> uncategorized
              <template v-if="preview.categorized_count"> · {{ preview.categorized_count }} already categorized stay as they are</template>
            </span>
          </p>

          <button class="btn primary block" :disabled="busy || !valid" @click="approve">
            <span v-if="busy === 'approve'" class="spinner" />
            <span v-else>Approve as rule</span>
          </button>
          <button class="btn block review-secondary" :disabled="busy || !category" @click="categorizeOnly">Set category only</button>
          <button class="btn block danger review-secondary" :disabled="busy" @click="reject"><Icon name="x" :size="17" /> Reject</button>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import Confidence from '../components/Confidence.vue'
import { del, get, post } from '../lib/api.js'
import { accountsOfType, loadAll, store } from '../lib/data.js'
import { initials, money, shortDate } from '../lib/format.js'
import { toast } from '../lib/toast.js'
import { badges } from '../lib/badges.js'

// RuleSuggestionService::TRIGGER_TYPES
const TRIGGER_TYPES = [
  { value: 'description_contains', label: 'Description contains' },
  { value: 'destination_account_contains', label: 'Payee contains' },
  { value: 'destination_account_is', label: 'Payee is' },
  { value: 'destination_account_id', label: 'Payee account' },
]

const route = useRoute()
const router = useRouter()
const item = ref(null)
const category = ref('')
const triggers = ref([])
const preview = ref(null)
const busy = ref(null)

const valid = computed(() => category.value && triggers.value.length && triggers.value.every((trigger) => String(trigger.value).trim()))

let previewTimer
watch(
  triggers,
  () => {
    clearTimeout(previewTimer)
    preview.value = null
    if (!triggers.value.every((trigger) => String(trigger.value).trim())) return
    previewTimer = setTimeout(async () => {
      try {
        preview.value = (await post('rule-suggestions/preview', { triggers: triggers.value }, { quiet: true })).data
      } catch (error) {
        preview.value = { error: error.message }
      }
    }, 400)
  },
  { deep: true },
)

async function done(message) {
  toast(message)
  badges.suggestions = Math.max(0, badges.suggestions - 1)
  router.replace('/suggestions')
}

async function approve() {
  busy.value = 'approve'
  try {
    const result = (await post(`rule-suggestions/${item.value.id}/approve`, { category: category.value, triggers: triggers.value })).data
    done(`${result.is_rule_created ? 'Rule created' : `${result.triggers_added_count} added to the rule`} · ${result.transactions_count} categorized`)
  } finally {
    busy.value = null
  }
}

async function categorizeOnly() {
  busy.value = 'categorize'
  try {
    const result = (await post(`rule-suggestions/${item.value.id}/categorize`, { category: category.value })).data
    done(`${result.transactions_count} categorized as ${category.value}`)
  } finally {
    busy.value = null
  }
}

async function reject() {
  busy.value = 'reject'
  try {
    await del(`rule-suggestions/${item.value.id}`)
    done('Rejected. The suggester will not propose it again.')
  } finally {
    busy.value = null
  }
}

onMounted(async () => {
  loadAll('categories', 'accounts')
  item.value = (await get(`rule-suggestions/${route.params.id}`)).data
  category.value = item.value.attributes.category
  triggers.value = item.value.attributes.triggers.map((trigger) => ({ ...trigger }))
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
