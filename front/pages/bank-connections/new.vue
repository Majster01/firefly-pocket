<template>
  <div class="app-form">
    <app-top-toolbar />

    <van-cell-group inset>
      <van-field :model-value="countryName(country)" readonly is-link :label="$t('bank_connection_page.new.country')" @click="isCountryVisible = true" />
    </van-cell-group>
    <van-action-sheet v-model:show="isCountryVisible" :actions="countryActions" close-on-click-action @select="onSelectCountry" />

    <van-search v-model="search" :placeholder="$t('bank_connection_page.new.search_banks')" />

    <van-loading v-if="isLoading" class="flex-center p-2" />
    <empty-list v-else-if="filteredBanks.length === 0" :title="$t('bank_connection_page.new.no_banks')" />
    <van-cell-group v-else inset>
      <van-cell v-for="bank in filteredBanks" :key="bank.name" :title="bank.name" clickable :class="{ active: bank.name === selected?.name }" @click="onSelectBank(bank)">
        <template #icon>
          <img v-if="bank.logo" :src="bank.logo" :alt="bank.name" class="bank-logo" />
          <app-icon v-else :icon="TablerIconConstants.bankConnection" :size="28" class="bank-logo" />
        </template>
        <template v-if="bank.name === selected?.name" #right-icon>
          <van-icon name="success" color="var(--van-primary-color)" />
        </template>
      </van-cell>
    </van-cell-group>

    <van-cell-group v-if="selected && selected.psu_types.length > 1" inset class="mt-10">
      <van-cell center :title="$t('bank_connection_page.new.account_type')">
        <template #right-icon>
          <van-radio-group v-model="psuType" direction="horizontal">
            <van-radio v-for="type in selected.psu_types" :key="type" :name="type">{{ $t(`bank_connection_page.new.${type}`) }}</van-radio>
          </van-radio-group>
        </template>
      </van-cell>
    </van-cell-group>

    <div style="margin: 16px">
      <van-button block type="primary" :disabled="!selected" :loading="isConnecting" @click="onConnect">{{ $t('bank_connection_page.new.continue') }}</van-button>
    </div>
  </div>
</template>

<script setup>
import { useIntervalFn, useLocalStorage } from '@vueuse/core'
import RouteConstants from '~/constants/RouteConstants'
import TablerIconConstants from '~/constants/TablerIconConstants'
import BankConnectionRepository from '~/repository/BankConnectionRepository'
import { useToolbar } from '~/composables/useToolbar'

// Countries Enable Banking has banks in (EEA and the UK)
const COUNTRIES = [
  'AT',
  'BE',
  'BG',
  'HR',
  'CY',
  'CZ',
  'DK',
  'EE',
  'FI',
  'FR',
  'DE',
  'GR',
  'HU',
  'IS',
  'IE',
  'IT',
  'LV',
  'LI',
  'LT',
  'LU',
  'MT',
  'NL',
  'NO',
  'PL',
  'PT',
  'RO',
  'SK',
  'SI',
  'ES',
  'SE',
  'CH',
  'GB',
]

const { t, locale } = useI18n()

// Last used, else the region of the browser language (sl-SI → SI)
const browserCountry = (globalThis.navigator?.language ?? '').split('-')[1]?.toUpperCase()
const country = useLocalStorage('bankConnectionCountry', COUNTRIES.includes(browserCountry) ? browserCountry : COUNTRIES[0])
const banks = ref([])
const search = ref('')
const selected = ref(null)
const psuType = ref('personal')
const isLoading = ref(false)
const isConnecting = ref(false)
const isCountryVisible = ref(false)
const pendingName = ref(null) // the connection whose consent is open at the bank

const countryName = (code) => new Intl.DisplayNames([locale.value], { type: 'region' }).of(code) ?? code
const countryActions = computed(() => COUNTRIES.map((code) => ({ name: countryName(code), code })).sort((a, b) => a.name.localeCompare(b.name, locale.value)))
const filteredBanks = computed(() => banks.value.filter((bank) => !search.value || bank.name.toLowerCase().includes(search.value.toLowerCase())))

const loadBanks = async () => {
  isLoading.value = true
  selected.value = null
  try {
    banks.value = await new BankConnectionRepository().getBanks(country.value)
  } catch {
    // The error toast comes from the axios interceptor
    banks.value = []
  } finally {
    isLoading.value = false
  }
}

const onSelectCountry = (action) => {
  country.value = action.code
}

const onSelectBank = (bank) => {
  selected.value = bank
  psuType.value = bank.psu_types.includes('personal') || bank.psu_types.length === 0 ? 'personal' : bank.psu_types[0]
}

const onConnect = async () => {
  isConnecting.value = true
  try {
    const { name, authUrl } = await new BankConnectionRepository().create({ country: country.value, bank: selected.value.name, psuType: psuType.value })
    pendingName.value = name
    startChecking()
    // The bank's own consent page; it sends the user to the account setup via the importer
    window.location.assign(authUrl)
    // Long enough to stop a second tap while the page leaves; if it stays (an overlay), the user is not stuck with a spinner
    setTimeout(() => (isConnecting.value = false), 5000)
  } catch {
    isConnecting.value = false
  }
}

watch(country, loadBanks, { immediate: true })

// The bank may hand the user back to a browser instead of this app, which gives this page no event to react to. While a consent is
// open at the bank, ask the importer whether it ended, and carry on where it did.
const checkPending = async () => {
  try {
    const connection = await new BankConnectionRepository().getOneConnection(pendingName.value, { showErrorToast: false })
    if (connection.status === 'needs_mapping') {
      stopChecking()
      await navigateTo({ path: RouteConstants.ROUTE_BANK_CONNECTION_SETUP, query: { connection: connection.name } })
    }
  } catch {
    // The importer drops a consent the bank refused or that shared no accounts: let the user try again
    stopChecking()
  }
}
const { pause: stopChecking, resume: startChecking } = useIntervalFn(checkPending, 3000, { immediate: false })
onBeforeUnmount(stopChecking)

// -----

const toolbar = useToolbar()

toolbar.init({
  title: t('bank_connection_page.new.title'),
  backRoute: RouteConstants.ROUTE_BANK_CONNECTION_LIST,
})
</script>

<style scoped>
.bank-logo {
  width: 28px;
  height: 28px;
  margin-right: 12px;
  object-fit: contain;
}
</style>
