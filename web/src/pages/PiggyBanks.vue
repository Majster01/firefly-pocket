<template>
  <div>
    <PageHeader title="Piggy banks">
      <button class="btn small" @click="open(null)"><Icon name="plus" :size="18" /> Add</button>
    </PageHeader>

    <div v-if="!piggies" class="loading"><span class="spinner" /></div>
    <div v-else-if="!piggies.length" class="card empty"><h3>No piggy banks</h3><p>Put money aside on a savings account for something you want.</p></div>
    <div v-else class="piggy-grid">
      <article v-for="piggy in piggies" :key="piggy.id" class="card piggy">
        <div class="row">
          <span class="avatar"><Icon name="piggy" :size="20" /></span>
          <div class="grow">
            <strong class="ellipsis" style="display: block">{{ piggy.attributes.name }}</strong>
            <span class="small muted">{{ accountNames(piggy) }}</span>
          </div>
          <button class="btn icon small" aria-label="Edit" @click="open(piggy)"><Icon name="edit" :size="17" /></button>
        </div>
        <div class="piggy-amount num">
          {{ money(current(piggy), piggy.attributes.currency_code) }}
          <span v-if="target(piggy)" class="muted">/ {{ money(target(piggy), piggy.attributes.currency_code) }}</span>
        </div>
        <div v-if="target(piggy)" class="track"><i class="ink" :style="{ width: `${Math.min(100, (current(piggy) / target(piggy)) * 100)}%` }" /></div>
        <div class="row small muted piggy-foot">
          <span class="grow">{{ footnote(piggy) }}</span>
          <button class="btn small" @click="move(piggy, -1)">Take out</button>
          <button class="btn small primary" @click="move(piggy, 1)">Add</button>
        </div>
      </article>
    </div>

    <Sheet :open="Boolean(editing)" :title="editing?.id ? 'Edit piggy bank' : 'New piggy bank'" @close="editing = null">
      <template #action><button class="btn small primary" :disabled="!editing?.name.trim() || !editing?.account_id" @click="save">Save</button></template>
      <template v-if="editing">
        <label class="field"><span>Name</span><input v-model="editing.name" /></label>
        <label class="field">
          <span>Saved on</span>
          <select v-model="editing.account_id">
            <option v-for="account in assetAccounts()" :key="account.id" :value="account.id">{{ account.attributes.name }}</option>
          </select>
        </label>
        <div class="field-row">
          <label class="field"><span>Target</span><input v-model="editing.target_amount" inputmode="decimal" placeholder="No target" /></label>
          <label class="field"><span>By</span><input v-model="editing.target_date" type="date" /></label>
        </div>
        <label class="field"><span>Notes</span><textarea v-model="editing.notes" rows="2" /></label>
        <button v-if="editing.id" class="btn block danger" @click="remove">Delete piggy bank</button>
      </template>
    </Sheet>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import Sheet from '../components/Sheet.vue'
import { del, getAll, post, put } from '../lib/api.js'
import { assetAccounts, load } from '../lib/data.js'
import { isoDate, money, shortDate } from '../lib/format.js'
import { toast } from '../lib/toast.js'

const piggies = ref(null)
const editing = ref(null)

const current = (piggy) => Number(piggy.attributes.current_amount ?? 0)
const target = (piggy) => Number(piggy.attributes.target_amount ?? 0)
const accountNames = (piggy) => (piggy.attributes.accounts ?? []).map((account) => account.name).join(', ')
const footnote = (piggy) => {
  const left = target(piggy) - current(piggy)
  if (target(piggy) && left <= 0) return 'Done 🎉'
  const parts = []
  if (target(piggy)) parts.push(`${money(left, piggy.attributes.currency_code)} to go`)
  if (piggy.attributes.target_date) parts.push(`by ${shortDate(piggy.attributes.target_date)}`)
  if (piggy.attributes.save_per_month && Number(piggy.attributes.save_per_month) > 0) parts.push(`${money(piggy.attributes.save_per_month)} a month`)
  return parts.join(' · ')
}

async function reload() {
  piggies.value = await getAll('piggy-banks')
}

const open = (piggy) =>
  (editing.value = piggy
    ? {
        id: piggy.id,
        name: piggy.attributes.name,
        account_id: piggy.attributes.accounts?.[0]?.account_id ?? assetAccounts()[0]?.id,
        target_amount: piggy.attributes.target_amount ? String(Number(piggy.attributes.target_amount)) : '',
        target_date: piggy.attributes.target_date?.slice(0, 10) ?? '',
        notes: piggy.attributes.notes ?? '',
        accounts: piggy.attributes.accounts ?? [],
      }
    : { id: null, name: '', account_id: assetAccounts().find((account) => account.attributes.account_role === 'savingAsset')?.id ?? assetAccounts()[0]?.id, target_amount: '', target_date: '', notes: '', accounts: [] })

async function save() {
  const value = editing.value
  const kept = value.accounts.find((account) => String(account.account_id) === String(value.account_id))
  const body = {
    name: value.name.trim(),
    target_amount: value.target_amount ? Number(value.target_amount.replace(',', '.')).toFixed(2) : null,
    target_date: value.target_date || null,
    notes: value.notes || null,
    accounts: [{ account_id: value.account_id, current_amount: kept?.current_amount ?? '0' }],
  }
  if (value.id) await put(`piggy-banks/${value.id}`, body)
  else await post('piggy-banks', { ...body, start_date: isoDate(), transaction_currency_code: 'EUR' })
  toast('Saved')
  editing.value = null
  reload()
}

async function move(piggy, direction) {
  const input = prompt(direction > 0 ? `Add how much to ${piggy.attributes.name}?` : `Take how much out of ${piggy.attributes.name}?`)
  const amount = Number(String(input ?? '').replace(',', '.'))
  if (!(amount > 0)) return
  const accounts = piggy.attributes.accounts ?? []
  const first = accounts[0]
  if (!first) return
  const next = Math.max(0, Number(first.current_amount) + direction * amount)
  await put(`piggy-banks/${piggy.id}`, { accounts: [{ account_id: first.account_id, current_amount: next.toFixed(2) }, ...accounts.slice(1).map((account) => ({ account_id: account.account_id, current_amount: account.current_amount }))] })
  toast(direction > 0 ? 'Added' : 'Taken out')
  reload()
}

async function remove() {
  if (!confirm(`Delete ${editing.value.name}?`)) return
  await del(`piggy-banks/${editing.value.id}`)
  editing.value = null
  reload()
}

onMounted(() => {
  load('accounts')
  reload()
})
</script>

<style>
.piggy-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr));
  gap: 12px;
}
.piggy-grid .card + .card {
  margin-top: 0;
}
.piggy-amount {
  margin: 14px 0 8px;
  font-size: 24px;
  font-weight: 700;
}
.piggy-amount .muted {
  font-size: 15px;
  font-weight: 500;
}
.piggy-foot {
  margin-top: 12px;
}
</style>
