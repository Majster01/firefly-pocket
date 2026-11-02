<template>
  <div>
    <PageHeader :title="isNew ? 'New rule' : saved?.attributes.title ?? 'Rule'" back="/rules">
      <button v-if="form" class="btn small primary" :disabled="!valid || busy" @click="save">
        <span v-if="busy === 'save'" class="spinner" />
        <span v-else>Save</span>
      </button>
    </PageHeader>

    <div v-if="loadError" class="card empty">
      <template v-if="loadError.status === 404">
        <h3>This rule no longer exists</h3>
        <p>It may have been deleted on another device or in Firefly.</p>
      </template>
      <template v-else>
        <h3>Could not load the rule</h3>
        <p>{{ loadError.message }}</p>
        <button class="btn small" @click="reload">Retry</button>
      </template>
      <p><RouterLink to="/rules" class="small rule-back">Back to rules</RouterLink></p>
    </div>
    <div v-else-if="!form" class="loading"><span class="spinner" /></div>
    <div v-else-if="!groups.length" class="card empty">
      <h3>Add a group first</h3>
      <p>Every rule belongs to a group.</p>
      <RouterLink to="/rules" class="btn small">Back to rules</RouterLink>
    </div>

    <div v-else class="rule-edit">
      <div class="rule-edit-main">
        <section class="card">
          <label class="field"><span>Name</span><input v-model="form.title" maxlength="100" placeholder="Groceries, Tag business trips…" /></label>
          <div class="field-row rule-settings">
            <label class="field">
              <span>Group</span>
              <select v-model="form.rule_group_id">
                <option v-for="group in groups" :key="group.id" :value="String(group.id)">{{ group.attributes.title }}</option>
              </select>
            </label>
            <label class="field">
              <span>Runs</span>
              <select v-model="form.trigger">
                <option v-for="option in WHEN" :key="option.value" :value="option.value">{{ option.label }}</option>
              </select>
            </label>
          </div>
          <label class="field"><span>Description</span><textarea v-model="form.description" rows="2" /></label>
          <label class="row field-switch">
            <span class="grow">Active</span>
            <input v-model="form.active" type="checkbox" class="switch" />
          </label>
          <label class="row field-switch">
            <span class="grow">
              Stop after this rule
              <span class="tiny muted" style="display: block">When it matches, later rules in the group are skipped</span>
            </span>
            <input v-model="form.stop_processing" type="checkbox" class="switch" />
          </label>
        </section>

        <section class="card">
          <h2 class="card-title">
            If
            <span class="seg">
              <button :class="{ on: form.strict }" :aria-pressed="form.strict" @click="form.strict = true">All match</button>
              <button :class="{ on: !form.strict }" :aria-pressed="!form.strict" @click="form.strict = false">Any matches</button>
            </span>
          </h2>
          <div v-for="(item, index) in form.triggers" :key="item.key" class="rule-line" :class="{ off: !item.active }">
            <div class="rule-line-top">
              <select :value="item.type" class="input" :aria-label="`Condition ${index + 1}`" @change="setType(item, $event.target.value, conditionType)">
                <option v-if="conditionType(item.type).unknown" :value="item.type">{{ item.type }}</option>
                <optgroup v-for="group in conditionGroups" :key="group.group" :label="group.group">
                  <option v-for="option in group.items" :key="option.type" :value="option.type">{{ option.label }}</option>
                </optgroup>
              </select>
              <button class="btn small rule-not" :class="{ on: item.prohibited }" :aria-pressed="item.prohibited" title="Match when this is not true" @click="item.prohibited = !item.prohibited">Not</button>
              <button class="btn icon small" :aria-label="`Remove condition ${index + 1}`" :disabled="form.triggers.length === 1" @click="form.triggers.splice(index, 1)"><Icon name="x" :size="16" /></button>
            </div>
            <RuleValue v-model="item.value" :definition="conditionType(item.type)" :label="`Condition ${index + 1} value`" />
            <button v-if="!item.active" class="chip rule-off" @click="item.active = true">Off in Firefly · turn on</button>
          </div>
          <button class="btn small" @click="form.triggers.push(newLine('description_contains'))"><Icon name="plus" :size="16" /> Condition</button>
        </section>

        <section class="card">
          <h2 class="card-title">Then</h2>
          <div v-for="(item, index) in form.actions" :key="item.key" class="rule-line" :class="{ off: !item.active }">
            <div class="rule-line-top">
              <select :value="item.type" class="input" :aria-label="`Action ${index + 1}`" @change="setType(item, $event.target.value, actionType)">
                <option v-if="actionType(item.type).unknown" :value="item.type">{{ item.type }}</option>
                <optgroup v-for="group in actionGroups" :key="group.group" :label="group.group">
                  <option v-for="option in group.items" :key="option.type" :value="option.type">{{ option.label }}</option>
                </optgroup>
              </select>
              <button class="btn icon small" :aria-label="`Move action ${index + 1} up`" :disabled="index === 0" @click="form.actions.splice(index - 1, 0, ...form.actions.splice(index, 1))"><Icon name="down" class="up" :size="16" /></button>
              <button class="btn icon small" :aria-label="`Remove action ${index + 1}`" :disabled="form.actions.length === 1" @click="form.actions.splice(index, 1)"><Icon name="x" :size="16" /></button>
            </div>
            <RuleValue v-model="item.value" :definition="actionType(item.type)" :label="`Action ${index + 1} value`" />
            <button v-if="!item.active" class="chip rule-off" @click="item.active = true">Off in Firefly · turn on</button>
          </div>
          <button class="btn small" @click="form.actions.push(newLine('set_category'))"><Icon name="plus" :size="16" /> Action</button>
        </section>

        <p v-if="problem" class="small muted rule-problem" aria-live="polite"><Icon name="info" :size="15" /> {{ problem }}</p>
      </div>

      <div class="rule-edit-side">
        <section class="card">
          <h2 class="card-title">Existing transactions</h2>
          <div class="field-row">
            <label class="field"><span>From</span><input v-model="range.start" type="date" /></label>
            <label class="field"><span>To</span><input v-model="range.end" type="date" /></label>
          </div>
          <p v-if="conditionsProblem" class="tiny muted">{{ conditionsProblem }} to find matches.</p>
          <button class="btn block" :disabled="Boolean(busy) || !rangeValid || Boolean(conditionsProblem)" @click="test">
            <span v-if="busy === 'test'" class="spinner" />
            <template v-else><Icon name="search" :size="17" /> Find matches</template>
          </button>
          <p v-if="!isNew && dirty" class="tiny muted rule-secondary">Running uses the saved rule. Save your changes first.</p>
          <button v-if="!isNew" class="btn block rule-secondary" :disabled="Boolean(busy) || !rangeValid || dirty" @click="run">
            <span v-if="busy === 'run'" class="spinner" />
            <template v-else><Icon name="bolt" :size="17" /> Run on these dates</template>
          </button>
          <p v-if="matches" class="small rule-matches" aria-live="polite">
            {{ matches.length ? `${matchCount} match` : 'Nothing matches in these dates' }}
          </p>
        </section>
        <div v-if="matches?.length" class="card flush rule-match-list">
          <TransactionRow v-for="split in matches.slice(0, MAX_SHOWN)" :key="split.transaction_journal_id" :split="split" show-date />
          <div v-if="matches.length > MAX_SHOWN" class="item item-sub">and {{ matches.length - MAX_SHOWN }} more{{ truncated ? ' or more' : '' }}</div>
        </div>

        <button v-if="!isNew" class="btn block danger rule-secondary" :disabled="Boolean(busy)" @click="remove">
          <span v-if="busy === 'delete'" class="spinner" />
          <template v-else><Icon name="trash" :size="17" /> Delete rule</template>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import RuleValue from '../components/RuleValue.vue'
import TransactionRow from '../components/TransactionRow.vue'
import { del, get, getAll, post, put } from '../lib/api.js'
import { accountById, flatten, loadAll } from '../lib/data.js'
import { isoDate, parseAmount } from '../lib/format.js'
import { composer } from '../lib/composer.js'
import { toast } from '../lib/toast.js'
import { ACTIONS, CONDITIONS, WHEN, actionType, conditionType, grouped, needsValue, unescapeHtml } from '../lib/rules.js'

const MAX_SHOWN = 50
const conditionGroups = grouped(CONDITIONS)
const actionGroups = grouped(ACTIONS)

const route = useRoute()
const router = useRouter()
const isNew = computed(() => route.name === 'rule-new')

const saved = ref(null) // the rule as Firefly last sent it
const groups = ref([])
const form = ref(null)
const snapshot = ref('') // the form as loaded or last saved, to tell unsaved changes
const loadError = ref(null)
const busy = ref(null)
const matches = ref(null)
const truncated = ref(false) // Pocket stopped looking: there are more than it lists
const matchCount = computed(() => `${matches.value.length}${truncated.value ? '+' : ''} ${matches.value.length === 1 && !truncated.value ? 'transaction' : 'transactions'}`)

const threeMonthsAgo = () => {
  const date = new Date()
  date.setMonth(date.getMonth() - 3)
  return isoDate(date)
}
const range = reactive({ start: threeMonthsAgo(), end: isoDate() })
const rangeValid = computed(() => Boolean(range.start && range.end && range.start <= range.end))

const plural = (count, word) => `${count} ${count === 1 ? word : `${word}s`}`

let keys = 0
const newLine = (type) => ({ key: ++keys, type, value: '', active: true, prohibited: false, stop_processing: false })
const toLine = (item) => ({ key: ++keys, type: item.type, value: item.value ?? '', active: item.active !== false, prohibited: Boolean(item.prohibited), stop_processing: Boolean(item.stop_processing) })

function toForm(rule) {
  const attributes = rule.attributes
  return {
    title: attributes.title ?? '',
    description: unescapeHtml(attributes.description),
    rule_group_id: String(attributes.rule_group_id),
    trigger: attributes.trigger ?? 'store-journal',
    active: attributes.active !== false,
    strict: attributes.strict !== false,
    stop_processing: Boolean(attributes.stop_processing),
    triggers: (attributes.triggers ?? []).map(toLine),
    actions: (attributes.actions ?? []).map(toLine),
  }
}

// What is compared for unsaved changes: the form without the keys of its lines
const comparable = (value) => JSON.stringify({ ...value, triggers: value.triggers.map(({ key, ...line }) => line), actions: value.actions.map(({ key, ...line }) => line) })
const dirty = computed(() => Boolean(form.value) && comparable(form.value) !== snapshot.value)

// A value of the wrong kind (an account id as a text, a date as an amount) would be a garbage condition: clear it
function setType(item, type, definitionOf) {
  if (definitionOf(item.type).value !== definitionOf(type).value) item.value = ''
  item.type = type
}

function lineProblem(item, definition) {
  if (!needsValue(definition)) return ''
  const value = String(item.value ?? '').trim()
  if (!value) return `“${definition.label}” needs a value`
  if (definition.value === 'amount' && Number.isNaN(parseAmount(value))) return `“${definition.label}” needs an amount`
  if (definition.value === 'account' && !value.includes(',') && !accountById(value)) return `“${definition.label}” needs an account`
  return ''
}

// What stops the conditions from being tested or saved, said in words
const conditionsProblem = computed(() => {
  const value = form.value
  if (!value) return ''
  if (!value.triggers.some((item) => item.active)) return 'Add a condition that is on'
  for (const item of value.triggers) {
    const text = lineProblem(item, conditionType(item.type))
    if (text) return text
  }
  return ''
})

// The first thing that stops the rule from saving, said in words
const problem = computed(() => {
  const value = form.value
  if (!value) return ''
  if (!value.title.trim()) return 'Give the rule a name'
  if (conditionsProblem.value) return conditionsProblem.value
  if (!value.actions.some((item) => item.active)) return 'Add an action that is on'
  for (const item of value.actions) {
    const text = lineProblem(item, actionType(item.type))
    if (text) return text
  }
  return ''
})
const valid = computed(() => !problem.value)

// Conditions without a value carry "true", as Firefly's own pages send them; actions without one carry nothing
function toBody(value) {
  const line = (item, definition, empty) => ({
    type: item.type,
    value: definition.value === 'none' ? (String(item.value ?? '').trim() || empty) : definition.value === 'amount' ? String(parseAmount(item.value)) : String(item.value ?? '').trim(),
    active: item.active,
    stop_processing: item.stop_processing,
  })
  return {
    title: value.title.trim(),
    description: value.description.trim() || null,
    rule_group_id: value.rule_group_id,
    trigger: value.trigger,
    active: value.active,
    strict: value.strict,
    stop_processing: value.stop_processing,
    triggers: value.triggers.map((item) => ({ ...line(item, conditionType(item.type), 'true'), prohibited: item.prohibited })),
    actions: value.actions.map((item) => line(item, actionType(item.type), null)),
  }
}

async function save() {
  if (!valid.value || busy.value) return
  busy.value = 'save'
  try {
    const body = toBody(form.value)
    if (isNew.value) {
      saved.value = (await post('rules', body)).data
      form.value = toForm(saved.value)
      snapshot.value = comparable(form.value)
      toast('Rule created')
      router.replace(`/rules/${saved.value.id}`)
    } else {
      saved.value = (await put(`rules/${route.params.id}`, body)).data
      form.value = toForm(saved.value)
      snapshot.value = comparable(form.value)
      matches.value = null
      toast('Saved')
    }
  } catch (error) {
    if (error?.status === 404) loadError.value = error
  } finally {
    busy.value = null
  }
}

// What the conditions say, to tell an answer that no longer fits them
const conditionsKey = computed(() => (form.value ? comparable({ ...form.value, actions: [], title: '', description: '' }) : ''))

// The conditions as they are on the screen, saved or not: Firefly can only test a saved rule, as it was saved
async function test() {
  if (busy.value || conditionsProblem.value) return
  busy.value = 'test'
  const asked = conditionsKey.value
  try {
    const answer = await post('rules/test', { triggers: toBody(form.value).triggers, strict: form.value.strict, start: range.start, end: range.end })
    if (asked !== conditionsKey.value) return
    matches.value = flatten(answer?.data ?? [])
    truncated.value = Boolean(answer?.meta?.truncated)
  } catch {
    // The toast says why
  } finally {
    busy.value = null
  }
}

// Firefly runs this one rule on its own, without the rules before it in the group: a "stop if already categorized"
// rule above it does not hold it back
async function run() {
  if (busy.value) return
  const count = matches.value ? ` It matched ${plural(matches.value.length, 'transaction')} when last tested.` : ' Find matches first to see what it changes.'
  const overwrites = saved.value.attributes.actions?.some((item) => item.active !== false && /^(set|clear)_(category|budget)$/.test(item.type))
    ? ' It sets the category or budget even where one is already set.'
    : ''
  if (!confirm(`Run ${saved.value.attributes.title} on transactions from ${range.start} to ${range.end}?${count}${overwrites} This cannot be undone.`)) return
  busy.value = 'run'
  try {
    await post(`rules/${route.params.id}/trigger`, undefined, { query: { start: range.start, end: range.end } })
    toast('Rule ran on the transactions in these dates')
    matches.value = null
  } catch (error) {
    if (error?.status === 404) loadError.value = error
  } finally {
    busy.value = null
  }
}

async function remove() {
  if (busy.value || !confirm(`Delete the rule ${saved.value.attributes.title}? Transactions it changed stay as they are.`)) return
  busy.value = 'delete'
  try {
    await del(`rules/${route.params.id}`)
    snapshot.value = comparable(form.value)
    form.value = null
    toast('Rule deleted')
    router.replace('/rules')
  } catch (error) {
    if (error?.status === 404) loadError.value = error
  } finally {
    busy.value = null
  }
}

async function reload() {
  loadError.value = null
  form.value = null
  matches.value = null
  try {
    const [groupList, rule] = await Promise.all([
      getAll('rule-groups', {}),
      isNew.value ? null : get(`rules/${route.params.id}`, undefined, { quiet: true }).then((json) => json.data),
    ])
    groups.value = groupList.sort((a, b) => Number(a.attributes.order ?? 0) - Number(b.attributes.order ?? 0))
    saved.value = rule
    if (rule) {
      form.value = toForm(rule)
    } else {
      const wanted = groups.value.find((group) => String(group.id) === String(route.query.group)) ?? groups.value[0]
      form.value = {
        title: '', description: '', rule_group_id: wanted ? String(wanted.id) : '', trigger: 'store-journal', active: true, strict: true, stop_processing: false,
        triggers: [newLine('description_contains')], actions: [newLine('set_category')],
      }
    }
    snapshot.value = comparable(form.value)
  } catch (error) {
    loadError.value = error
  }
}

onBeforeRouteLeave(() => {
  if (dirty.value && !confirm('Leave without saving your changes?')) return false
})

// Between rules, or back to a new one: load what the address names. Not after the first save, which has the rule
watch(
  () => route.fullPath,
  () => {
    if (!['rule', 'rule-new'].includes(route.name)) return
    if (route.name === 'rule' && String(saved.value?.id) === String(route.params.id)) return
    reload()
  },
)

// Matches found for other conditions say nothing about these
watch(conditionsKey, () => {
  matches.value = null
})

// A transaction edited from the matches may no longer match
watch(() => composer.version, () => {
  if (matches.value) test()
})

onMounted(() => {
  loadAll('accounts', 'categories', 'budgets', 'tags', 'currencies').catch(() => null)
  reload()
})
</script>

<style>
.rule-edit {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 12px;
}
.rule-edit-main,
.rule-edit-side {
  min-width: 0;
}
.rule-edit .card + .card {
  margin-top: 12px;
}
.rule-edit-side > .card:first-child {
  margin-top: 0;
}
/* Two selects side by side cut "When a transaction is created" short on a phone */
.field-row.rule-settings {
  grid-template-columns: 1fr;
}
@media (min-width: 600px) {
  .field-row.rule-settings {
    grid-template-columns: 1fr 1fr;
  }
}
.rule-line {
  display: grid;
  gap: 6px;
  padding: 10px 0;
  border-bottom: 1px solid var(--line);
  margin-bottom: 10px;
}
.rule-line.off > :not(.rule-off) {
  opacity: 0.55;
}
.rule-line-top {
  display: flex;
  gap: 6px;
  align-items: center;
}
.rule-line-top select {
  flex: 1;
  min-width: 0;
}
.rule-not.on {
  background: var(--ink);
  color: var(--on-ink);
  border-color: var(--ink);
}
.rule-off {
  justify-self: start;
  cursor: pointer;
}
.rule-problem {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  margin: 12px 4px;
}
.rule-problem svg {
  flex-shrink: 0;
  margin-top: 2px;
}
.rule-secondary {
  margin-top: 10px;
}
.rule-matches {
  margin: 12px 0 0;
}
.rule-match-list {
  margin-top: 12px;
}
.rule-back {
  color: var(--accent);
  font-weight: 600;
}
.card-title .seg {
  text-transform: none;
  letter-spacing: 0;
  font-weight: 500;
}
.field-switch {
  gap: 12px;
  padding: 6px 0;
}
@media (min-width: 900px) {
  .rule-edit {
    grid-template-columns: minmax(0, 1.4fr) minmax(320px, 1fr);
    align-items: start;
    gap: 16px;
  }
  .rule-edit-side {
    position: sticky;
    top: 90px;
  }
}
</style>
