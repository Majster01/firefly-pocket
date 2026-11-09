<template>
  <div>
    <PageHeader title="Import errors" eyebrow="System status" back="/system-status">
      <RefreshButton @click="reload" />
    </PageHeader>

    <div class="row error-day-picker">
      <button class="btn icon small" aria-label="Previous day" @click="shift(-1)"><Icon name="back" :size="18" /></button>
      <input v-model="date" class="input grow" type="date" :max="today" aria-label="Day" />
      <button class="btn icon small" aria-label="Next day" :disabled="date >= today" @click="shift(1)"><Icon name="chevron" :size="18" /></button>
    </div>
    <label class="field error-filter">
      <span>Bank connection</span>
      <select v-model="connection">
        <option value="">All</option>
        <option v-for="option in connectionOptions" :key="option.name" :value="option.name">{{ option.bank ?? option.name }}</option>
      </select>
    </label>

    <div v-if="error" class="banner bad error-text"><Icon name="alert" :size="18" /> <span>Unavailable: {{ error }}</span></div>
    <div v-if="!report && !error" class="loading"><span class="spinner" /></div>

    <section v-if="report" class="card">
      <h2 class="card-title">{{ shortDate(report.date) }} <span class="tiny" :class="{ bad: report.count }">{{ plural(report.count, 'error') }}</span></h2>
      <p v-if="!report.items.length" class="small muted">No import errors on this day.</p>
      <ul class="list">
        <li v-for="(item, index) in report.items" :key="index" class="error-item">
          <div class="row">
            <strong class="grow">{{ item.bank ?? item.connection ?? 'Unknown bank' }}</strong>
            <span class="tiny muted">{{ item.source === 'manual' ? 'Import now' : 'Nightly' }} · {{ item.at.slice(11, 16) }}</span>
          </div>
          <p v-for="message in item.messages" :key="message.message" class="small bad error-text">
            {{ message.message }}<span v-if="message.count > 1" class="muted"> ×{{ message.count }}</span>
          </p>
          <p v-if="item.more" class="tiny muted">and {{ plural(item.more, 'more error') }} that are not kept</p>
        </li>
      </ul>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import RefreshButton from '../components/RefreshButton.vue'
import { get } from '../lib/api.js'
import { isoDate, shortDate } from '../lib/format.js'

const route = useRoute()
const router = useRouter()
const today = isoDate()
const DAY_PATTERN = /^\d{4}-\d{2}-\d{2}$/

const date = ref(DAY_PATTERN.test(route.query.date ?? '') ? route.query.date : today)
const connection = ref(typeof route.query.connection === 'string' ? route.query.connection : '')
const report = ref(null)
const error = ref('')
// The connections of the day, kept while one is filtered so the filter can be changed back
const known = ref([])
let asked = 0

const plural = (count, word) => `${count} ${count === 1 ? word : `${word}s`}`
// A connection named in the link that has no error on this day is still shown as the filter
const connectionOptions = computed(() => (connection.value && !known.value.some((item) => item.name === connection.value) ? [...known.value, { name: connection.value, bank: null }] : known.value))

function shift(offset) {
  const [year, month, day] = date.value.split('-').map(Number)
  const next = isoDate(new Date(year, month - 1, day + offset, 12))
  if (next <= today) date.value = next
}

async function reload() {
  const request = ++asked
  try {
    const result = (await get('system-status/errors', { date: date.value, connection: connection.value }, { quiet: true })).data
    if (request !== asked) return
    report.value = result
    known.value = result.connections
    error.value = ''
  } catch (caught) {
    if (request !== asked) return
    error.value = caught.message
  }
}

watch([date, connection], () => {
  if (!DAY_PATTERN.test(date.value)) return
  report.value = null
  router.replace({ query: { date: date.value, ...(connection.value ? { connection: connection.value } : {}) } })
  reload()
})
onMounted(reload)
</script>

<style>
.error-day-picker {
  margin-bottom: 12px;
}
.error-filter {
  margin-bottom: 14px;
}
.error-item {
  padding: 10px 0;
}
/* Importer errors carry URLs and ids without spaces: break them anywhere rather than widen the page */
.error-text {
  overflow-wrap: anywhere;
}
.error-item p {
  margin: 4px 0 0;
}
</style>
