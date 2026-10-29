<template>
  <div class="app-form">
    <app-top-toolbar />

    <empty-list v-if="notFound" :title="$t('bank_connection_page.setup.no_connection')" />

    <van-empty
      v-else-if="connection && connection.accounts.length === 0"
      image="error"
      :description="`${$t('bank_connection_page.setup.no_accounts')}. ${$t('bank_connection_page.setup.no_accounts_subtitle', { bank: connection.bank.name })}`"
    >
      <van-button type="primary" :loading="isReauthenticating" @click="onReauthenticate">{{ $t('bank_connection_page.reauthenticate') }}</van-button>
    </van-empty>

    <template v-else-if="connection">
      <div class="p-1 text-size-12 text-muted">{{ $t('bank_connection_page.setup.intro') }}</div>

      <van-cell-group v-for="account in connection.accounts" :key="account.id" inset class="bank-account">
        <van-cell :title="account.name ?? account.iban_masked ?? $t('bank_connection_page.unknown_account')" :label="[account.iban_masked, account.currency].filter(Boolean).join(' · ')" />
        <van-field :model-value="choiceName(account)" readonly is-link :label="$t('bank_connection_page.setup.firefly_account')" @click="onOpenChooser(account)" />
      </van-cell-group>

      <van-cell-group inset>
        <van-field :label="$t('bank_connection_page.setup.import_since')" input-align="right">
          <template #input>
            <input v-model="importSince" type="date" :min="earliest" :max="today" class="date-input" />
          </template>
        </van-field>
      </van-cell-group>
      <div class="p-1 text-size-12 text-muted">{{ $t('bank_connection_page.setup.import_since_hint') }}</div>

      <div style="margin: 16px">
        <van-button block type="primary" :disabled="!canSave" :loading="isSaving" @click="onSave">{{ $t('bank_connection_page.setup.save') }}</van-button>
      </div>
    </template>

    <van-action-sheet v-model:show="isChooserVisible" :actions="actions" close-on-click-action @select="onChoose" />
  </div>
</template>

<script setup>
import { get, isEqual } from 'lodash-es'
import { addDays, format } from 'date-fns'
import RouteConstants from '~/constants/RouteConstants'
import BankConnectionRepository from '~/repository/BankConnectionRepository'
import AccountRepository from '~/repository/AccountRepository'
import AccountTransformer from '~/transformers/AccountTransformer'
import Account from '~/models/Account'
import UIUtils from '~/utils/UIUtils'
import { useToolbar } from '~/composables/useToolbar'

// Banks hand out older history only right after consent, and not all of it; the importer refuses more than this
const MAX_HISTORY_DAYS = 90
const NEW = 'new'
const SKIP = 'skip'

const accountStore = useAccountStore()
const route = useRoute()
const { t } = useI18n()

const connection = ref(null)
const notFound = ref(false)
const choices = ref({}) // bank account id → Firefly account id, NEW or SKIP
const importSince = ref('')
const chooseFor = ref(null) // the bank account being chosen for; kept after the sheet closes, which happens before select
const isChooserVisible = ref(false)
const isSaving = ref(false)
const isReauthenticating = ref(false)
const createdIds = {} // bank account id → Firefly account created for it, kept so a retried save does not create it twice

const today = format(new Date(), 'yyyy-MM-dd')
const earliest = format(addDays(new Date(), -MAX_HISTORY_DAYS), 'yyyy-MM-dd')

const assetAccounts = computed(() => accountStore.accountList.filter((item) => isEqual(Account.getType(item), Account.types.asset)))
const actions = computed(() => [
  ...assetAccounts.value.map((item) => ({ name: Account.getDisplayName(item), value: item.id })),
  { name: t('bank_connection_page.setup.create_new'), value: NEW, color: 'var(--van-primary-color)' },
  { name: t('bank_connection_page.setup.skip'), value: SKIP },
])
const canSave = computed(() => connection.value.accounts.every((account) => choices.value[account.id] !== undefined) && Object.values(choices.value).some((choice) => choice !== SKIP))

const choiceName = (account) => {
  const choice = choices.value[account.id]
  if (choice === undefined) {
    return t('bank_connection_page.setup.choose')
  }
  if (choice === SKIP) {
    return t('bank_connection_page.setup.skip')
  }
  if (choice === NEW) {
    return t('bank_connection_page.setup.create_new')
  }
  return Account.getDisplayName(accountStore.accountDictionary[choice]) ?? `#${choice}`
}

const onOpenChooser = (account) => {
  chooseFor.value = account
  isChooserVisible.value = true
}

const onChoose = (action) => {
  choices.value = { ...choices.value, [chooseFor.value.id]: action.value }
}

// The importer only shows the first and last four characters of an IBAN
const maskIban = (iban) => (iban && iban.length >= 8 ? `${iban.slice(0, 4)}…${iban.slice(-4)}` : iban)

// An existing asset account with the same IBAN is the likely target
const preselect = (account) => {
  const match = account.iban_masked && assetAccounts.value.find((item) => maskIban((get(item, 'attributes.iban') ?? '').replace(/\s/g, '')) === account.iban_masked)
  return match ? match.id : undefined
}

const createFireflyAccount = async (account) => {
  const response = await new AccountRepository().insert({
    name: account.name ?? `${connection.value.bank.name} ${account.iban_masked ?? ''}`.trim(),
    type: Account.types.asset.fireflyCode,
    account_role: Account.roleAssets.default.fireflyCode,
    currency_code: account.currency ?? undefined,
    include_net_worth: true,
  })
  const created = AccountTransformer.transformFromApi(get(response, 'data.data'))
  accountStore.accountList = [created, ...accountStore.accountList]
  return Number(created.id)
}

const targetFor = async (account) => {
  const choice = choices.value[account.id]
  if (choice === SKIP) {
    return null
  }
  if (choice === NEW) {
    createdIds[account.id] ??= await createFireflyAccount(account)
    return createdIds[account.id]
  }
  return Number(choice)
}

const onSave = async () => {
  isSaving.value = true
  try {
    const accounts = []
    for (const account of connection.value.accounts) {
      accounts.push({ id: account.id, firefly_account_id: await targetFor(account) })
    }
    await new BankConnectionRepository().saveAccounts(connection.value.name, accounts, importSince.value || null)
    UIUtils.showToastSuccess(t('bank_connection_page.setup.saved'), 2500)
    await navigateTo(RouteConstants.ROUTE_BANK_CONNECTION_LIST)
  } catch {
    // The error toast comes from the axios interceptor
  } finally {
    isSaving.value = false
  }
}

// The bank shared no account (none was ticked on its consent screen): ask for the consent again
const onReauthenticate = async () => {
  isReauthenticating.value = true
  try {
    window.location.assign(await new BankConnectionRepository().reauthenticate(connection.value.name))
  } catch {
    // The error toast comes from the axios interceptor
    isReauthenticating.value = false
  }
}

onMounted(async () => {
  try {
    connection.value = await new BankConnectionRepository().getOneConnection(route.query.connection)
  } catch {
    notFound.value = true
    return
  }
  if (accountStore.accountList.length === 0) {
    await accountStore.fetchAccounts()
  }
  for (const account of connection.value.accounts) {
    const target = account.firefly_account_id ?? preselect(account)
    if (target !== undefined) {
      choices.value[account.id] = target
    }
  }
})

// -----

const toolbar = useToolbar()

toolbar.init({
  title: t('bank_connection_page.setup.title'),
  backRoute: RouteConstants.ROUTE_BANK_CONNECTION_LIST,
})
</script>

<style scoped>
.bank-account {
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
