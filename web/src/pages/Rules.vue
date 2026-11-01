<template>
  <div>
    <PageHeader title="Rules" eyebrow="Firefly's automation">
      <button v-if="groups?.some((group) => group.rules.length > 1) || groups?.length > 1" class="btn small" :aria-pressed="reordering" @click="reordering = !reordering">
        {{ reordering ? 'Done' : 'Reorder' }}
      </button>
      <button class="btn small" @click="openGroup(null)"><Icon name="plus" :size="18" /> Group</button>
    </PageHeader>

    <div v-if="loadError" class="card empty">
      <h3>Could not load the rules</h3>
      <p>{{ loadError }}</p>
      <button class="btn small" @click="reload">Retry</button>
    </div>
    <div v-else-if="!groups" class="loading"><span class="spinner" /></div>
    <div v-else-if="!groups.length" class="card empty">
      <h3>No rules yet</h3>
      <p>Rules live in groups. Firefly runs them, in order, on each new transaction: set a category, add a tag, rename a payee.</p>
      <button class="btn small primary" @click="openGroup(null)"><Icon name="plus" :size="18" /> Add a group</button>
    </div>

    <template v-else>
      <section v-for="(group, groupIndex) in groups" :key="group.id" class="rule-group" :class="{ inactive: !group.attributes.active }">
        <div class="rule-group-head">
          <h2 class="section-title grow">
            {{ group.attributes.title }}
            <span v-if="!group.attributes.active" class="chip">Off</span>
          </h2>
          <template v-if="reordering">
            <button class="btn icon small" :aria-label="`Move ${group.attributes.title} up`" :disabled="groupIndex === 0 || busy.order" @click="moveGroup(groupIndex, -1)"><Icon name="down" class="up" :size="16" /></button>
            <button class="btn icon small" :aria-label="`Move ${group.attributes.title} down`" :disabled="groupIndex === groups.length - 1 || busy.order" @click="moveGroup(groupIndex, 1)"><Icon name="down" :size="16" /></button>
          </template>
          <button v-else class="btn icon small" :aria-label="`Edit group ${group.attributes.title}`" @click="openGroup(group)"><Icon name="edit" :size="16" /></button>
        </div>
        <p v-if="group.attributes.description" class="small muted rule-group-text">{{ unescapeHtml(group.attributes.description) }}</p>
        <p v-if="isCategorization(group)" class="tiny muted rule-group-text">Approved suggestions add their conditions to the “Category: …” rules here and put new ones above Bills, Shopping and Other.</p>

        <div class="card flush">
          <div v-for="(rule, ruleIndex) in group.rules" :key="rule.id" class="item rule-row" :class="{ inactive: !rule.attributes.active }">
            <RouterLink :to="`/rules/${rule.id}`" class="grow rule-link">
              <span class="item-title" style="display: block">
                {{ rule.attributes.title }}
                <span v-if="rule.attributes.stop_processing" class="chip tiny-chip" title="Later rules in this group are skipped when this one matches">Stops</span>
                <span v-if="rule.attributes.trigger === 'manual-activation'" class="chip tiny-chip">By hand</span>
              </span>
              <span class="item-sub" style="display: block">{{ summary(rule, accountName) }}</span>
            </RouterLink>
            <span class="item-end row" style="gap: 6px">
              <template v-if="reordering">
                <button class="btn icon small" :aria-label="`Move ${rule.attributes.title} up`" :disabled="ruleIndex === 0 || busy.order" @click="moveRule(group, ruleIndex, -1)"><Icon name="down" class="up" :size="16" /></button>
                <button class="btn icon small" :aria-label="`Move ${rule.attributes.title} down`" :disabled="ruleIndex === group.rules.length - 1 || busy.order" @click="moveRule(group, ruleIndex, 1)"><Icon name="down" :size="16" /></button>
              </template>
              <input v-else type="checkbox" class="switch" :checked="rule.attributes.active" :disabled="busy[rule.id]" :aria-label="`${rule.attributes.title} active`" @change="toggleRule(rule, $event)" />
            </span>
          </div>
          <RouterLink v-if="!reordering" :to="{ path: '/rules/new', query: { group: group.id } }" class="item rule-add">
            <Icon name="plus" :size="18" /><span class="grow">Add rule</span>
          </RouterLink>
          <div v-else-if="!group.rules.length" class="item item-sub">No rules</div>
        </div>
      </section>
    </template>

    <Sheet :open="Boolean(editing)" :title="editing?.id ? 'Edit group' : 'New group'" @close="editing = null">
      <template #action>
        <button class="btn small primary" :disabled="!editing?.title.trim() || saving" @click="saveGroup">
          <span v-if="saving" class="spinner" />
          <span v-else>Save</span>
        </button>
      </template>
      <template v-if="editing">
        <label class="field"><span>Name</span><input v-model="editing.title" placeholder="Categorization, Tags, Clean-up…" /></label>
        <label class="field"><span>Description</span><textarea v-model="editing.description" rows="2" /></label>
        <label class="row field-switch">
          <span class="grow">
            Active
            <span class="tiny muted" style="display: block">Firefly skips every rule of a group that is off</span>
          </span>
          <input v-model="editing.active" type="checkbox" class="switch" />
        </label>
        <button v-if="editing.id" class="btn block danger" :disabled="saving" style="margin-top: 14px" @click="removeGroup">
          <Icon name="trash" :size="17" /> Delete group
        </button>
      </template>
    </Sheet>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import Sheet from '../components/Sheet.vue'
import { del, getAll, post, put } from '../lib/api.js'
import { accountById, load } from '../lib/data.js'
import { settings } from '../lib/settings.js'
import { summary, unescapeHtml } from '../lib/rules.js'
import { toast } from '../lib/toast.js'

// Each group with its rules, both in Firefly's order. Pocket leaves out the suggester's group (server RuleController)
const groups = ref(null)
const loadError = ref('')
const reordering = ref(false)
const editing = ref(null)
const saving = ref(false)
// Rules with a switch on the way, and `order` while a move is: their controls wait for it
const busy = reactive({})

const byOrder = (a, b) => Number(a.attributes.order ?? 0) - Number(b.attributes.order ?? 0)
const accountName = (id) => accountById(id)?.attributes.name ?? `account #${id}`
// The group approved suggestions write to (RULE_GROUP_CATEGORIZATION on the server; Suggestions.vue names it too)
const isCategorization = (group) => settings.features.ruleSuggestions && group.attributes.title === 'Categorization'

// Toggles, moves and saves each reload: an older answer must not overwrite a newer one
let request = 0

async function reload() {
  const mine = ++request
  try {
    const list = (await getAll('rule-groups')).sort(byOrder)
    const rules = await Promise.all(list.map((group) => getAll(`rule-groups/${group.id}/rules`)))
    if (mine !== request) return
    groups.value = list.map((group, index) => ({ ...group, rules: rules[index].sort(byOrder) }))
    loadError.value = ''
  } catch (error) {
    if (mine === request && !groups.value) loadError.value = error.message
  }
}

async function toggleRule(rule, event) {
  const wanted = event.target.checked
  busy[rule.id] = true
  try {
    await put(`rules/${rule.id}`, { active: wanted })
    toast(wanted ? `${rule.attributes.title} is on` : `${rule.attributes.title} is off`)
  } catch {
    // Toasted by api(); the reload puts the switch back where Firefly has it
    event.target.checked = rule.attributes.active
  } finally {
    await reload()
    delete busy[rule.id]
  }
}

// Firefly numbers the rules of a group 1, 2, 3… before it moves one, and shifts the others to make room
async function moveRule(group, index, step) {
  busy.order = true
  try {
    await put(`rules/${group.rules[index].id}`, { order: index + step + 1 })
  } catch {
    // Toasted by api()
  } finally {
    await reload()
    busy.order = false
  }
}

// Groups take the place of their neighbour: Firefly counts the hidden suggestions group too, so its numbers are used
async function moveGroup(index, step) {
  const neighbour = groups.value[index + step]
  busy.order = true
  try {
    await put(`rule-groups/${groups.value[index].id}`, { order: Number(neighbour.attributes.order) })
  } catch {
    // Toasted by api()
  } finally {
    await reload()
    busy.order = false
  }
}

function openGroup(group) {
  editing.value = group
    ? { id: group.id, title: group.attributes.title, description: unescapeHtml(group.attributes.description), active: group.attributes.active !== false, count: group.rules.length }
    : { id: null, title: '', description: '', active: true, count: 0 }
}

async function saveGroup() {
  const value = editing.value
  if (!value?.title.trim() || saving.value) return
  saving.value = true
  const body = { title: value.title.trim(), description: value.description.trim() || null, active: value.active }
  try {
    if (value.id) await put(`rule-groups/${value.id}`, body)
    else await post('rule-groups', body)
    toast('Saved')
    editing.value = null
  } catch {
    // Toasted by api()
  } finally {
    saving.value = false
    reload()
  }
}

async function removeGroup() {
  const value = editing.value
  const rules = value.count ? ` and its ${value.count === 1 ? 'rule' : `${value.count} rules`}` : ''
  if (!confirm(`Delete the group ${value.title}${rules}? Transactions stay as they are.`)) return
  saving.value = true
  try {
    await del(`rule-groups/${value.id}`)
    toast('Deleted')
    editing.value = null
  } catch {
    // Toasted by api()
  } finally {
    saving.value = false
    reload()
  }
}

onMounted(() => {
  load('accounts').catch(() => null)
  reload()
})
</script>

<style>
.rule-group-head {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 22px 0 8px;
}
.rule-group-head .section-title {
  margin: 0 4px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.rule-group-text {
  margin: -2px 4px 8px;
}
.rule-group.inactive .card,
.rule-row.inactive .rule-link {
  opacity: 0.55;
}
.rule-link {
  min-width: 0;
  color: inherit;
}
.rule-add {
  color: var(--accent);
  font-weight: 600;
  min-height: 48px;
}
.tiny-chip {
  margin-left: 6px;
  padding: 1px 7px;
  font-size: 11px;
  vertical-align: 1px;
}
.icon.up,
svg.up {
  transform: rotate(180deg);
}
.field-switch {
  gap: 12px;
  padding: 6px 0;
}
</style>
