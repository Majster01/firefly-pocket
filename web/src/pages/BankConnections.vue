<template>
  <div>
    <PageHeader title="Bank connections">
      <button class="btn icon small" aria-label="Refresh" @click="reload"><Icon name="refresh" :size="18" /></button>
      <RouterLink to="/bank-connections/new" class="btn small"><Icon name="plus" :size="18" /> Connect</RouterLink>
    </PageHeader>

    <div v-if="loadError" class="banner bad"><Icon name="alert" :size="18" /> {{ loadError }}</div>
    <div v-if="!connections && !loadError" class="loading"><span class="spinner" /></div>
    <div v-else-if="connections && !connections.length" class="card empty">
      <h3>No bank connected</h3>
      <p>Connect a bank and the importer brings your transactions into Firefly every night.</p>
      <RouterLink to="/bank-connections/new" class="btn primary">Connect a bank</RouterLink>
    </div>

    <div class="connection-grid">
      <article v-for="connection in connections ?? []" :key="connection.name" class="card connection">
        <header class="row">
          <span class="avatar"><img v-if="connection.bank.logo" :src="connection.bank.logo" alt="" /></span>
          <div class="grow">
            <strong style="display: block">{{ connection.bank.name }} · {{ connection.bank.country }}</strong>
            <span class="small muted">{{ expiry(connection) }}</span>
          </div>
          <span class="chip" :class="STATUS[connection.status]?.tone">{{ STATUS[connection.status]?.label ?? connection.status }}</span>
        </header>
        <p v-if="connection.last_error" class="small bad">{{ connection.last_error }}</p>

        <ul class="list connection-accounts">
          <li v-for="account in connection.accounts" :key="account.id" class="row">
            <span class="grow">
              <span style="display: block">{{ account.name ?? account.iban_masked }}</span>
              <span class="tiny muted">{{ [account.iban_masked, account.currency].filter(Boolean).join(' · ') }}</span>
            </span>
            <RouterLink v-if="account.firefly_account_id > 0" :to="`/accounts/${account.firefly_account_id}`" class="small target">→ {{ accountName(account.firefly_account_id) }}</RouterLink>
            <span v-else class="small muted">{{ account.firefly_account_id === 0 ? 'New account' : 'Not imported' }}</span>
          </li>
        </ul>

        <div v-if="connection.last_import" class="small import-line">
          <span :class="connection.last_import.ok ? 'good' : 'bad'">{{ connection.last_import.ok ? '✓' : '✗' }}</span>
          Last import {{ timeAgo(connection.last_import.at) }}
          <span class="muted">· {{ connection.last_import.ok ? `${connection.last_import.summary?.messages ?? 0} new` : connection.last_import.summary?.first_error }}</span>
        </div>
        <div v-if="connection.manual_import" class="small import-line">
          <span v-if="IMPORT_ACTIVE.includes(connection.manual_import.status)" class="spinner" style="width: 14px; height: 14px" />
          <span :class="{ good: connection.manual_import.status === 'done', bad: connection.manual_import.status === 'failed' }">{{ IMPORT_STATUS[connection.manual_import.status] }}</span>
          <span class="muted">· {{ shortDate(connection.manual_import.date_from) }} – {{ shortDate(connection.manual_import.date_to) }}{{ manualDetail(connection.manual_import) }}</span>
        </div>
        <RouterLink v-if="connection.manual_import?.suggestions?.written > 0 && settings.features.ruleSuggestions" to="/suggestions" class="small review-link">
          <Icon name="sparkles" :size="15" /> Review {{ connection.manual_import.suggestions.written }} new suggestions
        </RouterLink>

        <footer class="row wrap connection-actions">
          <RouterLink v-if="connection.status === 'needs_mapping'" :to="{ path: '/bank-connections/setup', query: { connection: connection.name } }" class="btn small primary grow">Set up accounts</RouterLink>
          <button v-else class="btn small grow" :class="{ primary: ['expiring', 'expired', 'error'].includes(connection.status) }" :disabled="reauthenticating === connection.name" @click="reauthenticate(connection)">
            <span v-if="reauthenticating === connection.name" class="spinner" />
            <span v-else>Renew consent</span>
          </button>
          <button v-if="['active', 'expiring', 'error'].includes(connection.status)" class="btn small" :disabled="anyImportActive" @click="openImport(connection)">Import now</button>
          <button class="btn small icon danger" aria-label="Remove" @click="remove(connection)"><Icon name="trash" :size="16" /></button>
        </footer>
      </article>
    </div>

    <Sheet :open="Boolean(importing)" :title="`Import from ${importing?.bank.name ?? ''}`" @close="importing = null">
      <template v-if="importing">
        <div class="field-row">
          <label class="field"><span>From</span><input v-model="importFrom" type="date" :min="earliest" :max="importTo || today" /></label>
          <label class="field"><span>To</span><input v-model="importTo" type="date" :min="importFrom" :max="today" /></label>
        </div>
        <p class="small muted">Imports with the connection's saved settings, then suggests categories for what came in. Transactions already in Firefly are skipped.</p>
        <button class="btn primary block" :disabled="!importFrom || !importTo || importFrom > importTo || starting" @click="startImport">
          <span v-if="starting" class="spinner" />
          <span v-else>Start import</span>
        </button>
      </template>
    </Sheet>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import Sheet from '../components/Sheet.vue'
import { del, get, post } from '../lib/api.js'
import { accountById, load } from '../lib/data.js'
import { isoDate, shortDate, timeAgo } from '../lib/format.js'
import { settings } from '../lib/settings.js'
import { toast, toastError } from '../lib/toast.js'
import { refreshBadges } from '../lib/badges.js'
import { FAILURE, IMPORT_ACTIVE, IMPORT_STATUS, STATUS } from '../lib/bank.js'

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

const today = isoDate()
const earliest = isoDate(Date.now() - 90 * 86400000)
const anyImportActive = computed(() => (connections.value ?? []).some((connection) => IMPORT_ACTIVE.includes(connection.manual_import?.status)))

const accountName = (id) => accountById(id)?.attributes.name ?? `#${id}`
const expiry = (connection) => {
  if (!connection.expires_at) return 'Consent not given yet'
  if (connection.status === 'expired') return `Consent ended ${shortDate(connection.expires_at)}`
  return `Consent until ${shortDate(connection.expires_at)} · ${connection.days_left} ${connection.days_left === 1 ? 'day' : 'days'} left`
}
const manualDetail = (manual) => {
  if (manual.status === 'failed') return ` · ${manual.error}`
  if (manual.status !== 'done') return ''
  if (manual.suggest_error) return ` · suggester failed: ${manual.suggest_error}`
  return ` · ${manual.summary?.messages ?? 0} new · ${manual.suggestions?.written ?? 0} suggestions`
}

async function reload({ quiet = false } = {}) {
  try {
    connections.value = (await get('bank-connections', undefined, { quiet })).data
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
  } finally {
    starting.value = false
  }
}

async function remove(connection) {
  if (!confirm(`Remove the ${connection.bank.name} connection? Imported transactions stay in Firefly.`)) return
  await del(`bank-connections/${connection.name}`)
  toast('Removed')
  reload()
  refreshBadges()
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
  const { result, reason, connection } = route.query
  if (result === 'ok') toast('The bank confirmed the consent', { timeout: 3000 })
  else if (result) toastError(FAILURE[reason] ?? FAILURE.unknown)
  if (result) router.replace({ query: {} })
  load('accounts')
  await reload()
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
.connection-accounts .target {
  font-weight: 600;
}
.import-line {
  display: flex;
  gap: 6px;
  align-items: center;
  flex-wrap: wrap;
}
.review-link {
  display: inline-flex;
  gap: 6px;
  align-items: center;
  color: var(--accent);
  font-weight: 600;
}
.connection-actions {
  margin-top: auto;
  gap: 8px;
}
</style>
