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
          <van-button size="small" type="danger" plain @click="onRemove(connection)">{{ $t('bank_connection_page.remove') }}</van-button>
        </div>
      </van-cell-group>
    </van-pull-refresh>
  </div>
</template>

<script setup>
import { capitalize, get } from 'lodash-es'
import { formatTimeAgo, useDocumentVisibility } from '@vueuse/core'
import RouteConstants from '~/constants/RouteConstants'
import TablerIconConstants from '~/constants/TablerIconConstants'
import BankConnectionRepository from '~/repository/BankConnectionRepository'
import Account from '~/models/Account'
import DateUtils from '~/utils/DateUtils'
import UIUtils from '~/utils/UIUtils'
import { useToolbar } from '~/composables/useToolbar'

const bankConnectionStore = useBankConnectionStore()
const accountStore = useAccountStore()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const isRefreshing = ref(false)
const reauthenticating = ref(null)

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
</style>
