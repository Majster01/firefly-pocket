<template>
  <div>
    <PageHeader title="Piggy banks">
      <button class="btn small" @click="open(null)"><Icon name="plus" :size="18" /> Add</button>
    </PageHeader>

    <div v-if="loadError" class="banner bad"><Icon name="alert" :size="18" /> {{ loadError }}</div>
    <div v-else-if="!piggies" class="loading"><span class="spinner" /></div>
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
          <button class="btn small" :disabled="busy[piggy.id]" @click="move(piggy, -1)">Take out</button>
          <button class="btn small primary" :disabled="busy[piggy.id]" @click="move(piggy, 1)">Add</button>
        </div>
      </article>
    </div>

    <Sheet :open="Boolean(editing)" :title="editing?.id ? 'Edit piggy bank' : 'New piggy bank'" @close="editing = null">
      <template #action>
        <button class="btn small primary" :disabled="!editing?.name.trim() || !editing?.account_id || saving" @click="save">
          <span v-if="saving" class="spinner" />
          <span v-else>Save</span>
        </button>
      </template>
      <template v-if="editing">
        <label class="field"><span>Name</span><input v-model="editing.name" /></label>
        <label class="field">
          <span>Saved on</span>
          <select v-model="editing.account_id">
            <option v-for="account in assetAccounts()" :key="account.id" :value="account.id">{{ account.attributes.name }}</option>
          </select>
          <small v-if="editing.accounts.length > 1" class="muted">Also saved on {{ editing.accounts.slice(1).map((account) => `${account.name} (${money(account.current_amount)})`).join(', ') }}</small>
        </label>
        <div class="field-row">
          <label class="field"><span>Target</span><input v-model="editing.target_amount" inputmode="decimal" placeholder="No target" /></label>
          <label class="field"><span>By</span><input v-model="editing.target_date" type="date" /></label>
        </div>
        <label class="field"><span>Notes</span><textarea v-model="editing.notes" rows="2" /></label>
        <button v-if="editing.id" class="btn block danger" :disabled="saving" @click="remove">Delete piggy bank</button>
      </template>
    </Sheet>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import Sheet from '../components/Sheet.vue'
import { del, getAll, post, put } from '../lib/api.js'
import { accountById, assetAccounts, balanceOf, load } from '../lib/data.js'
import { isoDate, money, parseAmount, shortDate } from '../lib/format.js'
import { toast, toastError } from '../lib/toast.js'

const piggies = ref(null)
const editing = ref(null)
const saving = ref(false)
const loadError = ref('')
// Piggy banks with an add or take out on the way
const busy = reactive({})

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
  try {
    piggies.value = await getAll('piggy-banks')
    loadError.value = ''
  } catch (error) {
    if (!piggies.value) loadError.value = error.message
  }
}

// What other piggy banks already hold on an account: Firefly refuses more than the balance minus that
const heldElsewhere = (accountId, piggyId) =>
  (piggies.value ?? [])
    .filter((piggy) => String(piggy.id) !== String(piggyId))
    .flatMap((piggy) => piggy.attributes.accounts ?? [])
    .filter((account) => String(account.account_id) === String(accountId))
    .reduce((sum, account) => sum + Number(account.current_amount ?? 0), 0)

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

/**
 * The accounts to send, or undefined to leave them as Firefly has them (the same account: nothing to change).
 * A new account takes over what the old one held, as far as the new one has room; the others stay. Null: cancelled.
 */
function accountsFor(value) {
  const [first, ...others] = value.accounts
  if (!first) return [{ account_id: value.account_id, current_amount: '0' }]
  if (String(first.account_id) === String(value.account_id)) return undefined
  const saved = Number(first.current_amount ?? 0)
  const already = others.find((account) => String(account.account_id) === String(value.account_id))
  const free = Math.max(0, balanceOf(accountById(value.account_id) ?? { attributes: {} }) - heldElsewhere(value.account_id, value.id) - Number(already?.current_amount ?? 0))
  const carried = Math.min(saved, Math.floor(free * 100) / 100)
  if (carried < saved) {
    const name = accountById(value.account_id)?.attributes.name ?? 'That account'
    if (!confirm(`${name} has ${money(free)} not held by other piggy banks. Move ${money(carried)} of the ${money(saved)} saved there? The rest is no longer put aside.`)) return null
  }
  const rest = others.map((account) => ({ account_id: account.account_id, current_amount: account.current_amount }))
  if (already) {
    const merged = rest.find((account) => String(account.account_id) === String(value.account_id))
    merged.current_amount = (Number(merged.current_amount) + carried).toFixed(2)
    return rest
  }
  return [{ account_id: value.account_id, current_amount: carried.toFixed(2) }, ...rest]
}

async function save() {
  if (saving.value) return
  const value = editing.value
  const text = String(value.target_amount).trim()
  const targetAmount = text ? parseAmount(text) : null
  if (text && !(targetAmount > 0)) {
    toastError('Enter the target as an amount, like 1500 or 1.500,00. Leave it empty for no target.')
    return
  }
  const saved = value.accounts.reduce((sum, account) => sum + Number(account.current_amount ?? 0), 0)
  // Firefly trims the saved money down to a lower target without a word
  if (value.id && targetAmount !== null && targetAmount < saved && !confirm(`${money(saved)} is saved. A target of ${money(targetAmount)} lowers the saved amount to it. Go on?`)) return
  const accounts = value.id ? accountsFor(value) : [{ account_id: value.account_id, current_amount: '0' }]
  if (accounts === null) return
  const body = {
    name: value.name.trim(),
    target_amount: targetAmount === null ? null : targetAmount.toFixed(2),
    target_date: value.target_date || null,
    notes: value.notes || null,
    ...(accounts ? { accounts } : {}),
  }
  saving.value = true
  try {
    if (value.id) await put(`piggy-banks/${value.id}`, body)
    else await post('piggy-banks', { ...body, start_date: isoDate(), transaction_currency_code: 'EUR' })
    toast('Saved')
    editing.value = null
  } catch {
    // The error toast comes from api()
  } finally {
    saving.value = false
    reload()
  }
}

async function move(piggy, direction) {
  const name = piggy.attributes.name
  const input = prompt(direction > 0 ? `Add how much to ${name}?` : `Take how much out of ${name}?`)
  if (input === null || !input.trim()) return
  const amount = parseAmount(input)
  if (!(amount > 0)) return toastError(`“${input.trim()}” is not an amount. Try 25 or 25,50.`)
  const accounts = piggy.attributes.accounts ?? []
  if (!accounts.length) return toastError(`${name} is not saved on any account. Edit it to pick one.`)
  const code = piggy.attributes.currency_code
  let next
  if (direction > 0) {
    const left = target(piggy) - current(piggy)
    if (target(piggy) && amount > left + 0.001) return toastError(`That is more than the target: ${money(Math.max(0, left), code)} to go.`)
    // Added to the first account; the others stay as they are
    next = accounts.map((account, index) => ({ account_id: account.account_id, current_amount: (Number(account.current_amount) + (index === 0 ? amount : 0)).toFixed(2) }))
  } else {
    if (amount > current(piggy) + 0.001) return toastError(`Only ${money(current(piggy), code)} is saved in ${name}.`)
    // Taken from the first account, then the next ones
    let rest = amount
    next = accounts.map((account) => {
      const take = Math.min(Number(account.current_amount), rest)
      rest -= take
      return { account_id: account.account_id, current_amount: (Number(account.current_amount) - take).toFixed(2) }
    })
  }
  busy[piggy.id] = true
  try {
    await put(`piggy-banks/${piggy.id}`, { accounts: next })
    toast(direction > 0 ? 'Added' : 'Taken out')
  } catch {
    // Toasted by api(), e.g. when the account does not have that much free
  } finally {
    await reload()
    delete busy[piggy.id]
  }
}

async function remove() {
  if (!confirm(`Delete ${editing.value.name}?`)) return
  saving.value = true
  try {
    await del(`piggy-banks/${editing.value.id}`)
    editing.value = null
  } catch {
    // Toasted by api(); one deleted elsewhere is gone after the reload
  } finally {
    saving.value = false
    reload()
  }
}

onMounted(() => {
  load('accounts').catch(() => null)
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
