<template>
  <div>
    <PageHeader title="Connect a bank" back="/bank-connections" />

    <div class="field-row new-bank-filters">
      <label class="field">
        <span>Country</span>
        <select v-model="country">
          <option v-for="item in countries" :key="item.code" :value="item.code">{{ item.name }}</option>
        </select>
      </label>
      <label class="field">
        <span>Search</span>
        <input v-model="search" type="search" placeholder="Bank name" />
      </label>
    </div>

    <div v-if="loading" class="loading"><span class="spinner" /></div>
    <div v-else-if="!filtered.length" class="card empty">No banks found</div>
    <div v-else class="card flush">
      <button v-for="bank in filtered" :key="bank.name" class="item" :class="{ selected: selected?.name === bank.name }" @click="select(bank)">
        <span class="avatar"><img v-if="bank.logo" :src="bank.logo" alt="" /></span>
        <span class="grow item-title">{{ bank.name }}</span>
        <Icon v-if="selected?.name === bank.name" name="check" class="good" />
      </button>
    </div>

    <div v-if="selected && selected.psu_types.length > 1" class="card psu">
      <div class="row">
        <span class="grow">Account type</span>
        <div class="seg">
          <button v-for="type in selected.psu_types" :key="type" :class="{ on: psuType === type }" @click="psuType = type">{{ type === 'business' ? 'Business' : 'Personal' }}</button>
        </div>
      </div>
    </div>

    <p class="small muted note">You confirm the access on your bank's own page. Pocket never sees your bank login.</p>
    <button class="btn primary block" :disabled="!selected || connecting" @click="connect">
      <span v-if="connecting" class="spinner" />
      <span v-else>Continue to {{ selected?.name ?? 'the bank' }}</span>
    </button>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import { get, post } from '../lib/api.js'

// Countries Enable Banking has banks in (EEA and the UK)
const CODES = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IS', 'IE', 'IT', 'LV', 'LI', 'LT', 'LU', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'CH', 'GB']
const regionNames = new Intl.DisplayNames(undefined, { type: 'region' })
const countries = CODES.map((code) => ({ code, name: regionNames.of(code) ?? code })).sort((a, b) => a.name.localeCompare(b.name))

const router = useRouter()
const browserCountry = navigator.language.split('-')[1]?.toUpperCase()
let saved = null
try {
  saved = localStorage.getItem('pocket.bankCountry')
} catch {
  saved = null
}
const country = ref(saved ?? (CODES.includes(browserCountry) ? browserCountry : 'SI'))
const banks = ref([])
const search = ref('')
const selected = ref(null)
const psuType = ref('personal')
const loading = ref(false)
const connecting = ref(false)

const filtered = computed(() => banks.value.filter((bank) => !search.value || bank.name.toLowerCase().includes(search.value.toLowerCase())))

watch(
  country,
  async (code) => {
    try {
      localStorage.setItem('pocket.bankCountry', code)
    } catch {
      // Not remembered, that is all
    }
    loading.value = true
    selected.value = null
    try {
      banks.value = (await get('bank-connections/banks', { country: code })).data
    } catch {
      banks.value = []
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)

const select = (bank) => {
  selected.value = bank
  psuType.value = bank.psu_types.includes('personal') || !bank.psu_types.length ? 'personal' : bank.psu_types[0]
}

// The bank may hand the user back to another browser; meanwhile ask whether the consent ended and carry on where it did
let pending = null
let timer = null
async function check() {
  try {
    const connection = (await get(`bank-connections/${pending}`, undefined, { quiet: true })).data
    if (connection.status === 'needs_mapping') {
      clearInterval(timer)
      router.push({ path: '/bank-connections/setup', query: { connection: connection.name } })
    }
  } catch {
    clearInterval(timer)
  }
}
onBeforeUnmount(() => clearInterval(timer))

async function connect() {
  connecting.value = true
  try {
    const { name, auth_url: url } = (await post('bank-connections', { country: country.value, bank: selected.value.name, psu_type: psuType.value })).data
    pending = name
    timer = setInterval(check, 3000)
    location.assign(url)
    setTimeout(() => (connecting.value = false), 5000)
  } catch {
    connecting.value = false
  }
}
</script>

<style>
.new-bank-filters {
  margin-bottom: 4px;
}
.item.selected {
  background: var(--faint);
}
.psu {
  margin-top: 12px;
}
.note {
  margin: 14px 6px;
}
</style>
