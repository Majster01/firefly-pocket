<template>
  <div>
    <PageHeader title="Bank connections">
      <RefreshButton @click="reload" />
      <RouterLink to="/bank-connections/new" class="btn small"><Icon name="plus" :size="18" /> Connect</RouterLink>
    </PageHeader>

    <div v-if="loadError" class="banner bad error-text">
      <Icon name="alert" :size="18" /> <span class="grow">{{ loadError }}</span>
      <button class="btn small" @click="reload">Retry</button>
    </div>
    <div v-if="!connections && !loadError" class="loading"><span class="spinner" /></div>
    <div v-else-if="connections && !connections.length" class="card empty">
      <h3>No bank connected</h3>
      <p>Connect a bank and the importer brings your transactions into Firefly every night.</p>
      <RouterLink to="/bank-connections/new" class="btn primary">Connect a bank</RouterLink>
    </div>

    <div class="connection-grid">
      <article v-for="connection in connections ?? []" :id="`connection-${connection.name}`" :key="connection.name" class="card connection" :class="{ highlighted: highlighted === connection.name }">
        <header class="row">
          <span class="avatar"><img v-if="connection.bank.logo" :src="connection.bank.logo" alt="" /></span>
          <div class="grow">
            <strong style="display: block">{{ connection.bank.name }} · {{ connection.bank.country }}</strong>
            <span class="small muted">{{ expiry(connection) }}</span>
          </div>
          <span class="chip" :class="STATUS[connection.status]?.tone">{{ STATUS[connection.status]?.label ?? connection.status }}</span>
        </header>
        <p v-if="connection.last_error" class="small bad error-text">{{ connection.last_error }}</p>

        <ul class="list connection-accounts">
          <li v-for="account in connection.accounts" :key="account.id" class="row">
            <span class="grow account-name">
              <span style="display: block">{{ account.name ?? account.iban_masked }}</span>
              <span class="tiny muted">{{ [account.iban_masked, account.currency].filter(Boolean).join(' · ') }}</span>
            </span>
            <RouterLink v-if="account.firefly_account_id > 0" :to="`/accounts/${account.firefly_account_id}`" class="small target ellipsis">→ {{ accountName(account.firefly_account_id) }}</RouterLink>
            <span v-else class="small muted">{{ account.firefly_account_id === 0 ? 'New account' : 'Not imported' }}</span>
          </li>
        </ul>

        <div v-if="connection.last_import" class="small import-line error-text">
          <span :class="connection.last_import.ok ? 'good' : 'bad'">{{ connection.last_import.ok ? '✓' : '✗' }}</span>
          Last import {{ timeAgo(connection.last_import.at) }}
          <span v-if="connection.last_import.ok" class="muted">· {{ connection.last_import.summary?.messages ?? 0 }} new</span>
          <RouterLink v-else :to="errorsOf(connection, connection.last_import.at)">· See error</RouterLink>
        </div>
        <div v-if="connection.manual_import" class="small import-line error-text">
          <span v-if="IMPORT_ACTIVE.includes(connection.manual_import.status)" class="spinner" style="width: 14px; height: 14px" />
          <span :class="{ good: connection.manual_import.status === 'done', bad: connection.manual_import.status === 'failed' }">{{ IMPORT_STATUS[connection.manual_import.status] }}</span>
          <span class="muted">· {{ shortDate(connection.manual_import.date_from) }} – {{ shortDate(connection.manual_import.date_to) }}{{ manualDetail(connection.manual_import) }}</span>
          <RouterLink v-if="connection.manual_import.status === 'failed'" :to="errorsOf(connection, connection.manual_import.started_at)">· See error</RouterLink>
        </div>

        <footer class="row wrap connection-actions">
          <RouterLink v-if="connection.status === 'needs_mapping'" :to="{ path: '/bank-connections/setup', query: { connection: connection.name } }" class="btn small primary grow">Set up accounts</RouterLink>
          <button v-else class="btn small grow" :class="{ primary: ['expiring', 'expired', 'error'].includes(connection.status) }" :disabled="reauthenticating === connection.name" @click="reauthenticate(connection)">
            <span v-if="reauthenticating === connection.name" class="spinner" />
            <span v-else>Renew consent</span>
          </button>
          <button v-if="['active', 'expiring', 'error'].includes(connection.status)" class="btn small" :disabled="anyImportActive" @click="openImport(connection)">Import now</button>
          <button class="btn small icon danger" :aria-label="`Remove ${connection.bank.name}`" :disabled="removing === connection.name" @click="remove(connection)">
            <span v-if="removing === connection.name" class="spinner" style="width: 16px; height: 16px" />
            <Icon v-else name="trash" :size="16" />
          </button>
        </footer>
      </article>
    </div>

    <Sheet :open="Boolean(importing)" :title="`Import from ${importing?.bank.name ?? ''}`" @close="importing = null">
      <template v-if="importing">
        <div class="field-row">
          <label class="field"><span>From</span><input v-model="importFrom" type="date" :min="earliest" :max="importTo || today" /></label>
          <label class="field"><span>To</span><input v-model="importTo" type="date" :min="importFrom" :max="today" /></label>
        </div>
        <p v-if="importProblem" class="small bad">{{ importProblem }}</p>
        <p class="small muted">Imports with the connection's saved settings, then suggests categories for what came in. Transactions already in Firefly are skipped.</p>
        <button class="btn primary block" :disabled="Boolean(importProblem) || starting" @click="startImport">
          <span v-if="starting" class="spinner" />
          <span v-else>Start import</span>
        </button>
      </template>
    </Sheet>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import RefreshButton from '../components/RefreshButton.vue'
import Sheet from '../components/Sheet.vue'
import { del, get, post } from '../lib/api.js'
import { accountById, load } from '../lib/data.js'
import { isoDate, shortDate, timeAgo } from '../lib/format.js'
import { toast, toastError } from '../lib/toast.js'
import { refreshBadges } from '../lib/badges.js'
import { FAILURE, IMPORT_ACTIVE, IMPORT_STATUS, STATUS } from '../lib/bank.js'
import { manualDetail } from '../lib/manualImport.js'

const POLL_MS = 3000
const route = useRoute()
const router = useRouter()
const connections = ref(null)
const loadError = ref('')
const reauthenticating = ref(null)
const importing = ref(null)
const importFrom = ref('')
const importTo = ref('')
const starting = ref(false)
const removing = ref(null)
const highlighted = ref(null)

const today = isoDate()
const earliest = isoDate(Date.now() - 90 * 86400000)
const anyImportActive = computed(() => (connections.value ?? []).some((connection) => IMPORT_ACTIVE.includes(connection.manual_import?.status)))

// The date inputs' min/max only limit the picker: typed dates still need checking
const importProblem = computed(() => {
  if (!importFrom.value || !importTo.value) return 'Choose both dates'
  if (importFrom.value < earliest) return `Banks share at most 90 days: from ${shortDate(earliest)} on`
  if (importTo.value > today) return 'The end cannot be in the future'
  if (importFrom.value > importTo.value) return 'The start is after the end'
  return ''
})

const plural = (count, word) => `${count} ${count === 1 ? word : `${word}s`}`
const accountName = (id) => accountById(id)?.attributes.name ?? `#${id}`
const expiry = (connection) => {
  if (!connection.expires_at) return 'Consent not given yet'
  if (connection.status === 'expired') return `Consent ended ${shortDate(connection.expires_at)}`
  if (connection.days_left <= 0) return `Consent ends today`
  return `Consent until ${shortDate(connection.expires_at)} · ${plural(connection.days_left, 'day')} left`
}
// The day is the date part of the importer's own timestamp: the errors page groups by the importer's days
const errorsOf = (connection, at) => ({ path: '/system-status/errors', query: { start: at.slice(0, 10), end: at.slice(0, 10), connection: connection.name } })

// Always quiet: the banner says it, a toast on top would say it twice. A background refresh keeps the last list.
async function reload({ quiet = false } = {}) {
  try {
    connections.value = (await get('bank-connections', undefined, { quiet: true })).data
    loadError.value = ''
  } catch (error) {
    if (!quiet) loadError.value = error.message
  }
}

async function reauthenticate(connection) {
  reauthenticating.value = connection.name
  try {
    const { auth_url: url } = (await post(`bank-connections/${connection.name}/reauthenticate`, {})).data
    // The bank's consent page sends the browser back here via the importer
    location.assign(url)
  } catch {
    reauthenticating.value = null
  }
}

function openImport(connection) {
  importing.value = connection
  importFrom.value = isoDate(Date.now() - 30 * 86400000)
  importTo.value = today
}

async function startImport() {
  starting.value = true
  try {
    await post(`bank-connections/${importing.value.name}/import`, { date_from: importFrom.value, date_to: importTo.value })
    toast('Import started')
    importing.value = null
    await reload()
  } catch {
    // Toasted by the api; the sheet stays open to try again
  } finally {
    starting.value = false
  }
}

async function remove(connection) {
  if (removing.value || !confirm(`Remove the ${connection.bank.name} connection? Imported transactions stay in Firefly.`)) return
  removing.value = connection.name
  try {
    await del(`bank-connections/${connection.name}`)
    toast('Removed')
    await reload()
    refreshBadges()
  } catch {
    // Toasted by the api
  } finally {
    removing.value = null
  }
}

// Back from the setup page: show which connection was just saved
async function highlight(name) {
  highlighted.value = name
  await nextTick()
  document.getElementById(`connection-${name}`)?.scrollIntoView({ block: 'center', behavior: 'smooth' })
  setTimeout(() => highlighted.value === name && (highlighted.value = null), 2500)
}

// Follow a running import until it is over
let timer = null
watch(anyImportActive, (active, wasActive) => {
  clearInterval(timer)
  timer = active ? setInterval(() => reload({ quiet: true }), POLL_MS) : null
  if (wasActive && !active) refreshBadges()
})
onBeforeUnmount(() => clearInterval(timer))

// The bank may have finished the consent in another browser: pick it up when the app comes back
const onVisible = () => document.visibilityState === 'visible' && reload({ quiet: true })
document.addEventListener('visibilitychange', onVisible)
onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisible))

onMounted(async () => {
  // Back from the bank: ?connection=…&result=ok|failed&reason=…
  const { result, reason, connection, highlight: saved } = route.query
  if (result === 'ok') toast('The bank confirmed the consent', { timeout: 3000 })
  else if (result) toastError(FAILURE[reason] ?? FAILURE.unknown)
  if (result || saved) router.replace({ query: {} })
  load('accounts').catch(() => null)
  await reload()
  if (saved && connections.value?.some((item) => item.name === saved)) highlight(saved)
  const fresh = connections.value?.find((item) => item.name === connection && item.status === 'needs_mapping')
  if (result === 'ok' && fresh) router.push({ path: '/bank-connections/setup', query: { connection: fresh.name } })
})
</script>

<style>
.connection-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 380px), 1fr));
  gap: 12px;
}
.connection-grid .card + .card {
  margin-top: 0;
}
.connection {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.connection p {
  margin: 0;
}
.connection-accounts li {
  padding: 8px 0;
  border-top: 1px solid var(--line);
}
.connection-accounts .account-name {
  min-width: 0;
}
/* A long Firefly account name shrinks instead of pushing the row wider than the card */
.connection-accounts .target {
  font-weight: 600;
  min-width: 0;
  max-width: 55%;
}
.connection {
  transition: box-shadow 0.3s;
}
.connection.highlighted {
  box-shadow: 0 0 0 2px var(--accent), var(--card-shadow);
}
.error-text {
  overflow-wrap: anywhere;
}
.import-line {
  display: flex;
  gap: 6px;
  align-items: center;
  flex-wrap: wrap;
}
.connection-actions {
  margin-top: auto;
  gap: 8px;
}
</style>
