<template>
  <div>
    <PageHeader :title="account?.attributes.name ?? 'Account'" back="/accounts">
      <button v-if="account" class="btn small" @click="isEditing = true"><Icon name="edit" :size="17" /> Edit</button>
    </PageHeader>

    <div v-if="!account" class="loading"><span class="spinner" /></div>
    <template v-else>
      <section class="card hero">
        <div class="hero-label">{{ isOwn ? 'Balance' : account.attributes.type === 'expense' ? 'Paid in total' : 'Received in total' }}</div>
        <div class="hero-value">{{ money(isOwn ? balanceOf(account) : Math.abs(balanceOf(account)), account.attributes.currency_code) }}</div>
        <div v-if="account.attributes.currency_code !== 'EUR'" class="muted">
          <template v-if="account.attributes.currency_code === 'VWCE'">{{ shares(balanceOf(account)) }} shares × {{ money(vwcePrice()) }} = </template>
          <strong>{{ eur === null ? 'no exchange rate' : money(eur) }}</strong>
        </div>
        <div class="row wrap account-meta small muted">
          <span v-if="account.attributes.iban" class="chip">{{ account.attributes.iban }}</span>
          <span v-if="account.attributes.account_role" class="chip">{{ account.attributes.account_role.replace('Asset', '') }}</span>
          <span v-if="account.attributes.include_net_worth === false" class="chip">Not in net worth</span>
        </div>
        <p v-if="account.attributes.notes" class="small muted">{{ account.attributes.notes }}</p>
      </section>

      <h2 class="section-title">Transactions</h2>
      <div v-if="loading && !splits.length" class="loading"><span class="spinner" /></div>
      <div v-else-if="!splits.length" class="card empty">No transactions yet</div>
      <TransactionFeed v-else :splits="splits" :account-id="account.id" />
      <div v-if="page < totalPages" class="more">
        <button class="btn" :disabled="loading" @click="loadPage(page + 1)">Load more</button>
      </div>
    </template>

    <AccountSheet :open="isEditing" :account="account" @close="isEditing = false" @saved="reload" @deleted="router.replace('/accounts')" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import TransactionFeed from '../components/TransactionFeed.vue'
import AccountSheet from '../components/AccountSheet.vue'
import { get } from '../lib/api.js'
import { balanceOf, flatten, load, toEur, vwcePrice } from '../lib/data.js'
import { money, shares } from '../lib/format.js'
import { composer } from '../lib/composer.js'

const route = useRoute()
const router = useRouter()
const account = ref(null)
const splits = ref([])
const page = ref(1)
const totalPages = ref(1)
const loading = ref(false)
const isEditing = ref(false)

const isOwn = computed(() => ['asset', 'liabilities', 'liability'].includes(account.value?.attributes.type))
const eur = computed(() => toEur(balanceOf(account.value), account.value.attributes.currency_code))

async function loadPage(number) {
  loading.value = true
  try {
    const json = await get(`accounts/${route.params.id}/transactions`, { page: number, limit: 50 })
    const rows = flatten(json.data ?? [])
    splits.value = number === 1 ? rows : [...splits.value, ...rows]
    page.value = number
    totalPages.value = json.meta?.pagination?.total_pages ?? 1
  } finally {
    loading.value = false
  }
}

async function reload() {
  account.value = (await get(`accounts/${route.params.id}`)).data
  loadPage(1)
}

watch(() => composer.version, reload)
onMounted(() => {
  load('rates')
  load('accounts')
  reload()
})
</script>

<style>
.account-meta {
  margin-top: 12px;
}
</style>
