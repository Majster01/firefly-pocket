<template>
  <div>
    <PageHeader title="Accounts">
      <button class="btn small" @click="editing = { account: null }"><Icon name="plus" :size="18" /> Add</button>
    </PageHeader>

    <div ref="kindSeg" class="seg kind-seg" role="group" aria-label="Show">
      <button v-for="option in KINDS" :key="option.value" :aria-pressed="kind === option.value" :class="{ on: kind === option.value }" @click="setKind(option.value)">{{ option.label }}</button>
    </div>

    <div v-if="!store.loaded.accounts" class="loading"><span class="spinner" /></div>

    <template v-else-if="kind === 'own'">
      <section class="card hero accounts-hero">
        <div class="hero-label">Net worth</div>
        <div class="hero-value blurable">{{ money(netWorth.total) }}</div>
        <div class="kpis totals">
          <div v-for="group in ownGroups" :key="group.key">
            <div class="kpi-label">{{ group.title }}</div>
            <div class="kpi-value num blurable" :class="{ neg: group.total < 0 }">{{ money(group.total) }}</div>
          </div>
        </div>
        <p v-if="excludedCount" class="small muted excluded-note">{{ excludedCount === 1 ? 'One account is' : `${excludedCount} accounts are` }} not in net worth or these totals.</p>
      </section>

      <template v-for="group in ownGroups" :key="group.key">
        <h2 v-if="group.accounts.length" class="section-title">{{ group.title }}</h2>
        <div v-if="group.accounts.length" class="card flush">
          <RouterLink v-for="account in group.accounts" :key="account.id" :to="`/accounts/${account.id}`" class="item">
            <span class="avatar">{{ account.attributes.currency_code === 'VWCE' ? '📈' : initials(account.attributes.name) }}</span>
            <span class="grow">
              <span class="item-title ellipsis" style="display: block">{{ account.attributes.name }}</span>
              <!-- The masked IBAN does not shrink, so its last four digits stay readable -->
              <span class="item-sub account-sub">
                <span class="ellipsis">{{ subtitle(account) }}</span>
                <span v-if="account.attributes.iban && account.attributes.currency_code !== 'VWCE'" class="iban-tail">{{ subtitle(account) ? '· ' : '' }}{{ maskedIban(account.attributes.iban) }}</span>
              </span>
            </span>
            <span class="item-end">
              <span class="num item-title blurable" :class="{ bad: balanceOf(account) < 0 && group.key === 'asset' }">{{ money(balanceOf(account), account.attributes.currency_code) }}</span>
              <span v-if="account.attributes.currency_code !== 'EUR'" class="item-sub num blurable" style="display: block">{{ eurText(account) }}</span>
              <span v-if="!inNetWorth(account)" class="item-sub" style="display: block">not in net worth</span>
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
          <span class="item-end num muted blurable">{{ money(Math.abs(balanceOf(account)), account.attributes.currency_code) }}</span>
        </RouterLink>
        <div v-if="!counterparties.length" class="empty">Nothing found</div>
      </div>
    </template>

    <AccountSheet :open="Boolean(editing)" :account="editing?.account" @close="editing = null" />
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import AccountSheet from '../components/AccountSheet.vue'
import { accountKind, accountsOfType, balanceOf, load, netWorthEur, store, toEur, vwcePrice } from '../lib/data.js'
import { initials, money } from '../lib/format.js'

const KINDS = [
  { value: 'own', label: 'My accounts' },
  { value: 'expense', label: 'Payees' },
  { value: 'revenue', label: 'Payers' },
]

const route = useRoute()
const router = useRouter()
// The tab is in the URL, so Back from a payee returns to Payees
const kindOf = (query) => (KINDS.some((option) => option.value === query.kind) ? query.kind : 'own')
const kind = computed(() => kindOf(route.query))
const search = ref('')
const editing = ref(null)
const kindSeg = ref(null)

function setKind(value) {
  if (value === kind.value) return
  router.replace({ query: value === 'own' ? {} : { kind: value } })
}
watch(kind, () => {
  search.value = ''
  // On a phone the tabs scroll sideways: keep the chosen one in view
  nextTick(() => kindSeg.value?.querySelector('.on')?.scrollIntoView({ block: 'nearest', inline: 'nearest' }))
})

const netWorth = computed(() => netWorthEur())
// Only what net worth counts, so the groups add up to it
const inNetWorth = (account) => account.attributes.include_net_worth !== false
const sumEur = (accounts) => accounts.filter(inNetWorth).reduce((total, account) => total + (toEur(balanceOf(account), account.attributes.currency_code) ?? 0), 0)
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
const excludedCount = computed(() => ownGroups.value.flatMap((group) => group.accounts).filter((account) => !inNetWorth(account)).length)
const counterparties = computed(() =>
  accountsOfType(kind.value)
    .filter((account) => !search.value || account.attributes.name.toLowerCase().includes(search.value.toLowerCase()))
    .sort((a, b) => Math.abs(balanceOf(b)) - Math.abs(balanceOf(a))),
)

const subtitle = (account) => {
  if (account.attributes.currency_code === 'VWCE') return `Vanguard FTSE All-World · ${vwcePrice() ? `${money(vwcePrice())} a share` : 'price unknown'}`
  return accountKind(account) ?? ''
}
// The last four digits are what people know their account by
const maskedIban = (iban) => `…${iban.replace(/\s/g, '').slice(-4)}`
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
  /* Two columns and a second row on a narrow phone, where three euro amounts run into each other */
  grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
}
.accounts-hero .kpi-value {
  font-size: 16px;
}
.excluded-note {
  margin: 10px 0 0;
}
.account-sub {
  display: flex;
  align-items: center;
  gap: 4px;
  min-width: 0;
  overflow: hidden;
}
.iban-tail {
  flex: none;
  white-space: nowrap;
}
.counter-search {
  margin-bottom: 12px;
}
</style>
