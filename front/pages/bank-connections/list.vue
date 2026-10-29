<template>
  <div :class="formClass">
    <app-top-toolbar>
      <template #right>
        <app-button-list-add @click="navigateTo(RouteConstants.ROUTE_BANK_CONNECTION_NEW)" />
      </template>
    </app-top-toolbar>

    <empty-list v-if="isEmpty" :title="$t('bank_connection_page.list_empty')" :subtitle="$t('bank_connection_page.list_empty_subtitle')" />

    <van-pull-refresh v-model="isRefreshing" @refresh="onRefresh">
      <van-cell-group v-for="connection in bankConnectionStore.bankConnectionList" :key="connection.name" inset class="bank-connection">
        <van-cell>
          <template #title>
            <div class="flex-center-vertical gap-1">
              <app-icon :icon="TablerIconConstants.bankConnection" :size="20" />
              <div class="list-item-title flex-1-w">{{ connection.bank.name }} · {{ connection.bank.country }}</div>
              <van-tag :type="statusTagType(connection.status)">{{ $t(`bank_connection_page.status.${connection.status}`) }}</van-tag>
            </div>
            <div class="list-item-subtitle text-size-12">{{ expiryText(connection) }}</div>
            <div v-if="connection.last_error" class="text-size-12 text-danger word-break-word">{{ connection.last_error }}</div>
          </template>
        </van-cell>

        <van-cell
          v-for="account in connection.accounts"
          :key="account.identification_hash ?? account.iban_masked"
          :title="account.name ?? account.iban_masked ?? $t('bank_connection_page.unknown_account')"
          :label="account.iban_masked"
          :value="fireflyAccountName(account)"
          :is-link="account.firefly_account_id > 0"
          @click="onGoToAccount(account)"
        />

        <van-cell v-if="connection.last_import" :title="$t('bank_connection_page.last_import')" :label="lastImportDetail(connection)">
          <template #value>
            <span :class="connection.last_import.ok ? 'amount-income' : 'text-danger'">{{ lastImportText(connection) }}</span>
          </template>
        </van-cell>

        <van-cell v-if="connection.manual_import" :title="$t('bank_connection_page.import.manual')" :label="manualImportDetail(connection.manual_import)">
          <template #value>
            <div class="flex-center-vertical gap-1" style="justify-content: flex-end">
              <van-loading v-if="isImportActive(connection.manual_import)" size="14" />
              <span :class="manualImportClass(connection.manual_import)">{{ manualImportText(connection.manual_import) }}</span>
            </div>
          </template>
        </van-cell>
        <van-cell
          v-if="manualImportSuggestions(connection.manual_import) > 0 && profileStore.ruleSuggestionsEnabled"
          is-link
          :title="$t('bank_connection_page.import.review')"
          @click="navigateTo(RouteConstants.ROUTE_RULE_SUGGESTION_LIST)"
        />

        <div class="display-flex gap-1 p-1">
          <van-button v-if="connection.status === 'needs_mapping'" class="flex-1" size="small" type="primary" @click="onSetUp(connection)">
            {{ $t('bank_connection_page.set_up_accounts') }}
          </van-button>
          <van-button
            v-else
            class="flex-1"
            size="small"
            :type="needsAttention(connection) ? 'primary' : 'default'"
            :loading="reauthenticating === connection.name"
            @click="onReauthenticate(connection)"
          >
            {{ $t('bank_connection_page.reauthenticate') }}
          </van-button>
          <van-button v-if="canImport(connection)" size="small" :disabled="isAnyImportActive" @click="onOpenImport(connection)">{{ $t('bank_connection_page.import_now') }}</van-button>
          <van-button size="small" type="danger" plain @click="onRemove(connection)">{{ $t('bank_connection_page.remove') }}</van-button>
        </div>
      </van-cell-group>
    </van-pull-refresh>

    <van-popup v-model:show="isImportVisible" round position="bottom" class="p-1">
      <div class="text-center font-weight-600 text-size-18 mb-1">{{ $t('bank_connection_page.import.title', { bank: importing?.bank.name }) }}</div>
      <van-cell-group inset>
        <van-field :label="$t('bank_connection_page.import.from')" input-align="right">
          <template #input>
            <input v-model="importFrom" type="date" :max="importTo || today" class="date-input" />
          </template>
        </van-field>
        <van-field :label="$t('bank_connection_page.import.to')" input-align="right">
          <template #input>
            <input v-model="importTo" type="date" :min="importFrom" :max="today" class="date-input" />
          </template>
        </van-field>
      </van-cell-group>
      <div class="p-1 text-size-12 text-muted">{{ $t('bank_connection_page.import.hint') }}</div>
      <div style="margin: 0 16px 16px">
        <van-button block type="primary" :disabled="!importFrom || !importTo || importFrom > importTo" :loading="isStartingImport" @click="onStartImport">
          {{ $t('bank_connection_page.import.start') }}
        </van-button>
      </div>
    </van-popup>
  </div>
</template>

<script setup>
import { capitalize, get } from 'lodash-es'
import { addDays, format } from 'date-fns'
import { formatTimeAgo, useDocumentVisibility, useIntervalFn } from '@vueuse/core'
import RouteConstants from '~/constants/RouteConstants'
import TablerIconConstants from '~/constants/TablerIconConstants'
import BankConnectionRepository from '~/repository/BankConnectionRepository'
import Account from '~/models/Account'
import DateUtils from '~/utils/DateUtils'
import UIUtils from '~/utils/UIUtils'
import { useToolbar } from '~/composables/useToolbar'
import { useProfileStore } from '~/stores/profileStore'

// How often the progress of a running import is fetched
const IMPORT_POLL_MS = 4000
// The time frame offered when the import popup opens
const DEFAULT_IMPORT_DAYS = 30
const IMPORT_ACTIVE_STATUSES = ['queued', 'importing', 'suggesting']

const bankConnectionStore = useBankConnectionStore()
const accountStore = useAccountStore()
const profileStore = useProfileStore()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const isRefreshing = ref(false)
const reauthenticating = ref(null)
const importing = ref(null) // the connection the import popup is open for
const isImportVisible = ref(false)
const isStartingImport = ref(false)
const importFrom = ref('')
const importTo = ref('')

const today = format(new Date(), 'yyyy-MM-dd')

const isEmpty = computed(() => !bankConnectionStore.isLoadingBankConnections && bankConnectionStore.bankConnectionList.length === 0)
const formClass = computed(() => ({ 'app-form': true, empty: isEmpty.value }))

const needsAttention = (connection) => ['expiring', 'expired', 'error'].includes(connection.status)

const statusTagType = (status) =>
  ({
    active: 'success',
    expiring: 'warning',
    expired: 'danger',
    error: 'danger',
  })[status] ?? 'default'

const expiryText = (connection) => {
  if (!connection.expires_at) {
    return t('bank_connection_page.expiry_unknown')
  }
  const date = DateUtils.dateToUI(DateUtils.autoToDate(connection.expires_at))
  return connection.status === 'expired' ? t('bank_connection_page.expired_on', { date }) : t('bank_connection_page.expires_on', { date, count: connection.days_left }, connection.days_left)
}

const fireflyAccountName = (account) => {
  if (account.firefly_account_id === null) {
    return t('bank_connection_page.not_imported')
  }
  if (account.firefly_account_id === 0) {
    return t('bank_connection_page.new_account')
  }
  return Account.getDisplayName(accountStore.accountDictionary[account.firefly_account_id]) ?? `#${account.firefly_account_id}`
}

const lastImportText = (connection) => {
  const at = DateUtils.autoToDate(connection.last_import.at)
  return `${connection.last_import.ok ? '✓' : '✗'} ${at ? capitalize(formatTimeAgo(at)) : ''}`
}

const lastImportDetail = (connection) => {
  const summary = get(connection, 'last_import.summary') ?? {}
  return connection.last_import.ok ? t('bank_connection_page.import_messages', summary.messages ?? 0) : summary.first_error
}

// Only a connection the nightly import would also use can be imported by hand
const canImport = (connection) => ['active', 'expiring', 'error'].includes(connection.status)
const isImportActive = (manualImport) => IMPORT_ACTIVE_STATUSES.includes(manualImport?.status)
// The importer runs one manual import at a time, for all connections
const isAnyImportActive = computed(() => bankConnectionStore.bankConnectionList.some((item) => isImportActive(item.manual_import)))

const manualImportSuggestions = (manualImport) => get(manualImport, 'suggestions.written') ?? 0

const manualImportText = (manualImport) => t(`bank_connection_page.import.${manualImport.status}`)
const manualImportClass = (manualImport) => (manualImport.status === 'failed' ? 'text-danger' : manualImport.status === 'done' ? 'amount-income' : '')

const manualImportDetail = (manualImport) => {
  const range = t('bank_connection_page.import.range', {
    from: DateUtils.dateToUI(DateUtils.autoToDate(manualImport.date_from)),
    to: DateUtils.dateToUI(DateUtils.autoToDate(manualImport.date_to)),
  })
  if (manualImport.status === 'failed') {
    return `${range} · ${manualImport.error}`
  }
  if (manualImport.status !== 'done') {
    return range
  }
  if (manualImport.suggest_error) {
    return `${range} · ${t('bank_connection_page.import.suggest_failed', { error: manualImport.suggest_error })}`
  }
  const messages = t('bank_connection_page.import.result', get(manualImport, 'summary.messages') ?? 0)
  return `${range} · ${messages} · ${t('bank_connection_page.import.suggestions', manualImportSuggestions(manualImport))}`
}

const onOpenImport = (connection) => {
  importing.value = connection
  importFrom.value = format(addDays(new Date(), -DEFAULT_IMPORT_DAYS), 'yyyy-MM-dd')
  importTo.value = today
  isImportVisible.value = true
}

const onStartImport = async () => {
  isStartingImport.value = true
  try {
    await new BankConnectionRepository().startImport(importing.value.name, importFrom.value, importTo.value)
    isImportVisible.value = false
    UIUtils.showToastSuccess(t('bank_connection_page.import.started'), 1500)
    await onRefresh()
  } catch {
    // The error toast comes from the axios interceptor
  } finally {
    isStartingImport.value = false
  }
}

const onGoToAccount = async (account) => {
  if (account.firefly_account_id > 0) {
    await navigateTo(`${RouteConstants.ROUTE_ACCOUNT_ID}/${account.firefly_account_id}`)
  }
}

const onSetUp = async (connection) => await navigateTo({ path: RouteConstants.ROUTE_BANK_CONNECTION_SETUP, query: { connection: connection.name } })

const onReauthenticate = async (connection) => {
  reauthenticating.value = connection.name
  try {
    const authUrl = await new BankConnectionRepository().reauthenticate(connection.name)
    // The bank's own consent page; it sends the user back to this page via the importer
    window.location.assign(authUrl)
  } catch {
    // The error toast comes from the axios interceptor
    reauthenticating.value = null
  }
}

const onRemove = async (connection) => {
  const confirmed = await UIUtils.showDeleteConfirmation(t('bank_connection_page.remove_title'), t('bank_connection_page.remove_message', { bank: connection.bank.name }))
  if (!confirmed) {
    return
  }
  await new BankConnectionRepository().delete(connection.name)
  UIUtils.showToastSuccess(t('bank_connection_page.removed'), 1500)
  await onRefresh()
}

const onRefresh = async () => {
  isRefreshing.value = true
  try {
    await bankConnectionStore.fetchBankConnections()
  } finally {
    isRefreshing.value = false
  }
}

// Back from the bank: the importer appends ?connection=…&result=…&reason=…
const showReturnResult = () => {
  const result = route.query.result
  if (!result) {
    return
  }
  if (result === 'ok') {
    UIUtils.showToastSuccess(t('bank_connection_page.reauthenticated'), 2500)
  } else {
    UIUtils.showToastError(t(`bank_connection_page.reauthenticate_failed.${route.query.reason ?? 'unknown'}`), 6000)
  }
  router.replace({ query: {} })
}

// Follow a running import until it is over
const { pause: stopPolling, resume: startPolling } = useIntervalFn(() => bankConnectionStore.fetchBankConnections({ showLoading: false, showErrorToast: false }), IMPORT_POLL_MS, { immediate: false })
watch(isAnyImportActive, (active) => (active ? startPolling() : stopPolling()), { immediate: true })

// A re-authentication may have finished in another browser: pick it up when the app is back
const visibility = useDocumentVisibility()
watch(visibility, (state) => state === 'visible' && onRefresh())

onMounted(async () => {
  showReturnResult()
  await onRefresh()
  if (accountStore.accountList.length === 0) {
    await accountStore.fetchAccounts()
  }
})

// -----

const toolbar = useToolbar()

toolbar.init({
  title: t('bank_connections'),
  backRoute: RouteConstants.ROUTE_EXTRAS,
})
</script>

<style scoped>
.bank-connection {
  margin-bottom: 12px;
}

.date-input {
  border: none;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: right;
}
</style>
