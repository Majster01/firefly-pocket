<template>
  <div>
    <PageHeader title="Set up accounts" :eyebrow="connection?.bank.name" back="/bank-connections" />

    <div v-if="notFound" class="card empty"><h3>Connection not found</h3></div>
    <div v-else-if="!connection" class="loading"><span class="spinner" /></div>
    <div v-else-if="!connection.accounts.length" class="card empty">
      <h3>The bank shared no accounts</h3>
      <p>Renew the consent and tick at least one account on {{ connection.bank.name }}'s page.</p>
    </div>
    <template v-else>
      <p class="small muted intro">Choose the Firefly account each bank account feeds. Accounts you skip are not imported.</p>
      <div v-for="account in connection.accounts" :key="account.id" class="card setup-account">
        <div class="row">
          <span class="avatar"><Icon name="bank" :size="19" /></span>
          <div class="grow">
            <strong style="display: block">{{ account.name ?? account.iban_masked }}</strong>
            <span class="small muted">{{ [account.iban_masked, account.currency].filter(Boolean).join(' · ') }}</span>
          </div>
        </div>
        <label class="field setup-choice">
          <span>Firefly account</span>
          <select v-model="choices[account.id]">
            <option :value="undefined" disabled>Choose…</option>
            <option v-for="asset in assetAccounts()" :key="asset.id" :value="asset.id">{{ asset.attributes.name }} ({{ asset.attributes.currency_code }})</option>
            <option value="new">Create a new account</option>
            <option value="skip">Do not import</option>
          </select>
        </label>
      </div>

      <div class="card">
        <label class="field">
          <span>Import transactions since</span>
          <input v-model="importSince" type="date" :min="earliest" :max="today" />
        </label>
        <p class="tiny muted" style="margin: 0">Banks hand out older history only right after the consent, at most 90 days back.</p>
      </div>

      <button class="btn primary block save" :disabled="!canSave || saving" @click="save">
        <span v-if="saving" class="spinner" />
        <span v-else>Save and import</span>
      </button>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import { get, post, put } from '../lib/api.js'
import { assetAccounts, invalidate, load } from '../lib/data.js'
import { isoDate } from '../lib/format.js'
import { toast } from '../lib/toast.js'

const route = useRoute()
const router = useRouter()
const connection = ref(null)
const notFound = ref(false)
const choices = reactive({})
const importSince = ref(isoDate(Date.now() - 30 * 86400000))
const saving = ref(false)
const created = {} // bank account id → Firefly account made for it, so a retried save does not make it twice

const today = isoDate()
const earliest = isoDate(Date.now() - 90 * 86400000)
const canSave = computed(() => connection.value.accounts.every((account) => choices[account.id] !== undefined) && Object.values(choices).some((choice) => choice !== 'skip'))

// The importer shows only the first and last four characters of an IBAN
const maskIban = (iban) => (iban && iban.length >= 8 ? `${iban.slice(0, 4)}…${iban.slice(-4)}` : iban)

async function target(account) {
  const choice = choices[account.id]
  if (choice === 'skip') return null
  if (choice !== 'new') return Number(choice)
  created[account.id] ??= Number(
    (
      await post('accounts', {
        name: account.name ?? `${connection.value.bank.name} ${account.iban_masked ?? ''}`.trim(),
        type: 'asset',
        account_role: 'defaultAsset',
        currency_code: account.currency ?? 'EUR',
        include_net_worth: true,
      })
    ).data.id,
  )
  return created[account.id]
}

async function save() {
  saving.value = true
  try {
    const accounts = []
    for (const account of connection.value.accounts) accounts.push({ id: account.id, firefly_account_id: await target(account) })
    await put(`bank-connections/${connection.value.name}/accounts`, { accounts, import_since: importSince.value || null })
    invalidate('accounts')
    toast('Saved. The first import is running.')
    router.replace('/bank-connections')
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    connection.value = (await get(`bank-connections/${route.query.connection}`, undefined, { quiet: true })).data
  } catch {
    notFound.value = true
    return
  }
  await load('accounts', { force: true })
  for (const account of connection.value.accounts) {
    const match = account.iban_masked && assetAccounts().find((asset) => maskIban((asset.attributes.iban ?? '').replace(/\s/g, '')) === account.iban_masked)
    const existing = account.firefly_account_id ?? match?.id
    if (existing !== undefined && existing !== null) choices[account.id] = String(existing)
  }
})
</script>

<style>
.setup-account .setup-choice {
  margin: 12px 0 0;
}
.save {
  margin-top: 16px;
}
</style>
