<template>
  <div>
    <PageHeader title="Accounts">
      <button class="btn small" @click="editing = { account: null }"><Icon name="plus" :size="18" /> Add</button>
    </PageHeader>

    <div class="seg kind-seg">
      <button v-for="option in KINDS" :key="option.value" :class="{ on: kind === option.value }" @click="kind = option.value">{{ option.label }}</button>
    </div>

    <div v-if="!store.loaded.accounts" class="loading"><span class="spinner" /></div>

    <template v-else-if="kind === 'own'">
      <section class="card hero accounts-hero">
        <div class="hero-label">Net worth</div>
        <div class="hero-value">{{ money(netWorth.total) }}</div>
        <div class="kpis totals">
          <div v-for="group in ownGroups" :key="group.key">
            <div class="kpi-label">{{ group.title }}</div>
            <div class="kpi-value num" :class="{ neg: group.total < 0 }">{{ money(group.total) }}</div>
          </div>
        </div>
      </section>

      <template v-for="group in ownGroups" :key="group.key">
        <h2 v-if="group.accounts.length" class="section-title">{{ group.title }}</h2>
        <div v-if="group.accounts.length" class="card flush">
          <RouterLink v-for="account in group.accounts" :key="account.id" :to="`/accounts/${account.id}`" class="item">
            <span class="avatar">{{ account.attributes.currency_code === 'VWCE' ? '📈' : initials(account.attributes.name) }}</span>
            <span class="grow">
              <span class="item-title ellipsis" style="display: block">{{ account.attributes.name }}</span>
              <span class="item-sub ellipsis" style="display: block">{{ subtitle(account) }}</span>
            </span>
            <span class="item-end">
              <span class="num item-title" :class="{ bad: balanceOf(account) < 0 && group.key === 'asset' }">{{ money(balanceOf(account), account.attributes.currency_code) }}</span>
              <span v-if="account.attributes.currency_code !== 'EUR'" class="item-sub num" style="display: block">{{ eurText(account) }}</span>
            </span>
            <Icon name="chevron" class="chev" :size="18" />
          </RouterLink>
        </div>
      </template>
    </template>

    <template v-else>
      <label class="search counter-search">
        <Icon name="search" :size="18" />
        <input v-model="search" class="input" type="search" :placeholder="`Search ${kind === 'expense' ? 'payees' : 'payers'}`" />
      </label>
      <div class="card flush">
        <RouterLink v-for="account in counterparties" :key="account.id" :to="`/accounts/${account.id}`" class="item">
          <span class="avatar">{{ initials(account.attributes.name) }}</span>
          <span class="grow item-title ellipsis">{{ account.attributes.name }}</span>
          <span class="item-end num muted">{{ money(Math.abs(balanceOf(account)), account.attributes.currency_code) }}</span>
        </RouterLink>
        <div v-if="!counterparties.length" class="empty">Nothing found</div>
      </div>
    </template>

    <AccountSheet :open="Boolean(editing)" :account="editing?.account" @close="editing = null" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import AccountSheet from '../components/AccountSheet.vue'
import { accountsOfType, balanceOf, load, netWorthEur, store, toEur, vwcePrice } from '../lib/data.js'
import { initials, money } from '../lib/format.js'

const KINDS = [
  { value: 'own', label: 'My accounts' },
  { value: 'expense', label: 'Payees' },
  { value: 'revenue', label: 'Payers' },
]
const ROLES = { defaultAsset: 'Current account', savingAsset: 'Savings', sharedAsset: 'Shared', ccAsset: 'Credit card', cashWalletAsset: 'Cash' }

const kind = ref('own')
const search = ref('')
const editing = ref(null)

const netWorth = computed(() => netWorthEur())
const sumEur = (accounts) => accounts.reduce((total, account) => total + (toEur(balanceOf(account), account.attributes.currency_code) ?? 0), 0)
const ownGroups = computed(() => {
  const active = (type) => accountsOfType(type).filter((account) => account.attributes.active)
  const assets = active('asset')
  const investments = assets.filter((account) => account.attributes.currency_code === 'VWCE')
  const cash = assets.filter((account) => account.attributes.currency_code !== 'VWCE')
  const liabilities = [...active('liabilities'), ...active('liability')]
  return [
    { key: 'asset', title: 'Cash & bank', accounts: cash, total: sumEur(cash) },
    { key: 'invest', title: 'Investments', accounts: investments, total: sumEur(investments) },
    { key: 'liabilities', title: 'Liabilities', accounts: liabilities, total: sumEur(liabilities) },
  ].filter((group) => group.accounts.length)
})
const counterparties = computed(() =>
  accountsOfType(kind.value)
    .filter((account) => !search.value || account.attributes.name.toLowerCase().includes(search.value.toLowerCase()))
    .sort((a, b) => Math.abs(balanceOf(b)) - Math.abs(balanceOf(a))),
)

const subtitle = (account) => {
  const { attributes } = account
  if (attributes.currency_code === 'VWCE') return `Vanguard FTSE All-World · ${vwcePrice() ? `${money(vwcePrice())} a share` : 'price unknown'}`
  return [ROLES[attributes.account_role] ?? attributes.liability_type, attributes.iban ? `${attributes.iban.slice(0, 4)} …${attributes.iban.slice(-4)}` : null].filter(Boolean).join(' · ')
}
const eurText = (account) => {
  const eur = toEur(balanceOf(account), account.attributes.currency_code)
  return eur === null ? 'no rate' : `≈ ${money(eur)}`
}

onMounted(() => {
  load('accounts', { force: true })
  load('rates')
})
</script>

<style>
.kind-seg {
  margin-bottom: 14px;
}
.accounts-hero .totals {
  margin-top: 14px;
  grid-template-columns: repeat(3, minmax(0, 1fr));
}
.accounts-hero .kpi-value {
  font-size: 16px;
}
.counter-search {
  margin-bottom: 12px;
}
</style>
