<template>
  <div>
    <PageHeader title="System status" eyebrow="Importer and suggester">
      <button class="btn icon small" aria-label="Refresh" @click="reload"><Icon name="refresh" :size="18" /></button>
    </PageHeader>

    <div class="seg window-seg">
      <button v-for="option in WINDOWS" :key="option" :class="{ on: days === option }" :aria-pressed="days === option" @click="days = option">Last {{ option }} days</button>
    </div>

    <div v-if="error" class="banner bad status-error"><Icon name="alert" :size="18" /> <span>Unavailable: {{ error }}</span></div>
    <div v-if="!status && !error" class="loading"><span class="spinner" /></div>

    <div v-if="status" class="status">
      <section class="card">
        <h2 class="card-title">Nightly imports</h2>
        <div class="kpis status-kpis">
          <div><div class="kpi-label">Last import</div><div class="kpi-value small-kpi">{{ lastImport }}</div></div>
          <div><div class="kpi-label">New transactions</div><div class="kpi-value">{{ status.imports.totals.created }}</div></div>
          <div><div class="kpi-label">Failed</div><div class="kpi-value" :class="{ bad: status.imports.totals.failed }">{{ status.imports.totals.failed }}</div></div>
          <div><div class="kpi-label">Missed nights</div><div class="kpi-value" :class="{ warn: missed.length }">{{ missed.length }}</div></div>
        </div>
        <div class="nights" role="img" :aria-label="`Import nights: ${status.imports.days.filter((day) => day.ran && !day.failed).length} fine, ${status.imports.totals.failed} failed, ${missed.length} missed`">
          <span v-for="day in [...status.imports.days].reverse()" :key="day.date" class="night" :class="nightClass(day)" :title="`${shortDate(day.date)}: ${nightText(day)}`" />
        </div>
        <div class="row tiny muted legend">
          <span><i class="night" /> imported</span>
          <span><i class="night failed" /> failed</span>
          <span><i class="night missed" /> missed</span>
          <span class="grow" />
          <span v-if="status.imports.tracking_since">since {{ shortDate(status.imports.tracking_since) }}</span>
        </div>
        <ul class="list day-list">
          <li v-for="day in shownDays" :key="day.date" class="row">
            <span class="grow">{{ shortDate(day.date) }} <span v-if="day.ran" class="tiny muted">· {{ plural(day.connections, 'connection') }}{{ day.warnings ? ` · ${plural(day.warnings, 'warning')}` : '' }}</span></span>
            <span class="small" :class="nightClass(day) === 'failed' ? 'bad' : nightClass(day) === 'missed' ? 'bad' : nightClass(day) === 'ok' ? 'good' : 'muted'">{{ nightText(day) }}</span>
          </li>
        </ul>
        <button v-if="status.imports.days.length > 7" class="btn small" @click="allDays = !allDays">{{ allDays ? 'Show less' : 'Show all days' }}</button>
      </section>

      <section class="card">
        <h2 class="card-title">Connections</h2>
        <ul class="list">
          <li v-for="connection in status.connections" :key="connection.name" class="row status-connection">
            <span class="grow">
              <strong>{{ connection.bank }}</strong>
              <span class="tiny muted" style="display: block">
                {{ connection.last_import_at ? `${connection.last_import_ok ? '✓' : '✗'} ${timeAgo(connection.last_import_at)}` : 'never imported' }}{{ consentLeft(connection) }}
              </span>
              <span v-if="connection.last_error" class="tiny bad error-text" style="display: block">{{ connection.last_error }}</span>
            </span>
            <span class="chip" :class="STATUS[connection.status]?.tone">{{ STATUS[connection.status]?.label ?? connection.status }}</span>
          </li>
        </ul>
        <p v-if="status.manual.total" class="small muted">Imports by hand: {{ status.manual.done }} done, {{ status.manual.failed }} failed of {{ status.manual.total }}</p>
      </section>

      <section class="card">
        <h2 class="card-title">Category suggester</h2>
        <p v-if="!status.suggester.available" class="small bad error-text">{{ status.suggester.error }}</p>
        <div class="kpis status-kpis three">
          <div><div class="kpi-label">Runs</div><div class="kpi-value">{{ status.suggester.totals.runs }}</div></div>
          <div><div class="kpi-label">Model calls</div><div class="kpi-value">{{ status.suggester.totals.calls }}</div></div>
          <div><div class="kpi-label">Unsure</div><div class="kpi-value">{{ status.suggester.totals.low_confidence }}/{{ status.suggester.totals.suggestions }}</div></div>
          <div><div class="kpi-label">Web lookups</div><div class="kpi-value">{{ status.suggester.totals.searches }}</div></div>
          <div><div class="kpi-label">Tokens in / out</div><div class="kpi-value small-kpi">{{ compact(status.suggester.totals.input_tokens) }} / {{ compact(status.suggester.totals.output_tokens) }}</div></div>
          <div><div class="kpi-label">Errors</div><div class="kpi-value" :class="{ bad: suggesterErrors }">{{ suggesterErrors }}</div></div>
        </div>
        <div class="row outcomes">
          <RouterLink to="/suggestions" class="outcome"><strong>{{ status.suggester.suggestions.pending }}</strong><span class="tiny muted">pending</span></RouterLink>
          <div class="outcome"><strong class="good">{{ status.suggester.suggestions.resolved }}</strong><span class="tiny muted" title="Approved as a rule or set as the category">resolved</span></div>
          <div class="outcome"><strong>{{ status.suggester.suggestions.rejected }}</strong><span class="tiny muted">rejected</span></div>
        </div>
        <p v-if="status.suggester.last_run" class="tiny muted">Unsure below a confidence of {{ status.suggester.last_run.min_confidence }}</p>
        <ul class="list day-list">
          <li v-for="run in status.suggester.recent.slice(0, 7)" :key="run.id" class="row">
            <span class="grow">
              {{ shortDate(run.started) }} <span class="tiny muted">· {{ run.source }}</span>
              <span v-if="run.status === 'failed'" class="tiny bad error-text" style="display: block">{{ run.error }}</span>
              <span v-else class="tiny muted" style="display: block">{{ plural(run.calls, 'call') }} · {{ plural(run.suggestions, 'suggestion') }} · {{ run.low_confidence }} unsure</span>
            </span>
            <span :class="run.status === 'failed' ? 'bad' : 'good'">{{ run.status === 'failed' ? 'Failed' : '✓' }}</span>
          </li>
        </ul>
      </section>

      <section class="card">
        <h2 class="card-title">Bank activity <span v-if="status.events.recent.length" class="tiny" :class="{ bad: status.events.totals.error }">{{ plural(status.events.totals.error, 'error') }} · {{ plural(status.events.totals.warning, 'warning') }}</span></h2>
        <p v-if="!status.events.recent.length" class="small muted">Nothing happened.</p>
        <ul class="list day-list">
          <li v-for="event in shownEvents" :key="event.at + event.step + event.connection" class="row event">
            <i class="event-dot" :class="event.level" />
            <span class="grow">
              {{ event.bank ?? event.connection ?? 'Unknown consent' }} · {{ event.step }}{{ event.flow ? ` (${event.flow})` : '' }}
              <span class="tiny muted error-text" style="display: block">{{ [event.reason, event.message].filter(Boolean).join(' · ') }}</span>
            </span>
            <span class="tiny muted">{{ timeAgo(event.at) }}</span>
          </li>
        </ul>
        <button v-if="status.events.recent.length > 6" class="btn small" @click="allEvents = !allEvents">{{ allEvents ? 'Show less' : 'Show all' }}</button>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import { get } from '../lib/api.js'
import { isoDate, shortDate, timeAgo } from '../lib/format.js'
import { STATUS } from '../lib/bank.js'

const WINDOWS = [7, 30, 90]
const days = ref(30)
const status = ref(null)
const error = ref('')
const allDays = ref(false)
const allEvents = ref(false)
const today = isoDate()

const plural = (count, word) => `${count} ${count === 1 ? word : `${word}s`}`
const consentLeft = (connection) => {
  if (connection.days_left === null || connection.days_left === undefined) return ''
  if (connection.days_left < 0) return ' · consent ended'
  if (connection.days_left === 0) return ' · consent ends today'
  return ` · ${plural(connection.days_left, 'day')} of consent left`
}

const compactFormat = new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 })
const compact = (value) => compactFormat.format(value ?? 0)

const since = computed(() => (status.value?.imports.tracking_since ?? '').slice(0, 10))
const missed = computed(() => (status.value?.imports.days ?? []).filter((day) => !day.ran && day.date < today && since.value && day.date >= since.value))
const lastImport = computed(() => {
  const last = status.value.imports.days.find((day) => day.ran)
  return last ? `${last.failed ? '✗' : '✓'} ${shortDate(last.date)}` : 'none yet'
})
const shownDays = computed(() => (allDays.value ? status.value.imports.days : status.value.imports.days.slice(0, 7)))
const shownEvents = computed(() => (allEvents.value ? status.value.events.recent : status.value.events.recent.slice(0, 6)))
const suggesterErrors = computed(() => {
  const totals = status.value.suggester.totals
  return totals.group_errors + totals.write_errors + totals.failed_runs
})

const nightClass = (day) => {
  if (day.ran) return day.failed ? 'failed' : 'ok'
  if (day.date === today) return 'today'
  return since.value && day.date >= since.value ? 'missed' : 'untracked'
}
const nightText = (day) => {
  if (day.ran) return day.failed ? `✗ ${day.failed}/${day.connections} failed` : `✓ ${day.created} new`
  return day.date === today ? 'not yet' : nightClass(day) === 'missed' ? 'no import' : 'not tracked'
}

async function reload() {
  try {
    status.value = (await get('system-status', { days: days.value }, { quiet: true })).data
    error.value = ''
  } catch (caught) {
    error.value = caught.message
  }
}

watch(days, reload)
onMounted(reload)
</script>

<style>
.window-seg {
  margin-bottom: 14px;
}
.status {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 12px;
}
/* Importer errors carry URLs and ids without spaces: break them anywhere rather than widen the page */
.error-text,
.status-error {
  overflow-wrap: anywhere;
}
.status .card + .card {
  margin-top: 0;
}
.status-kpis {
  grid-template-columns: repeat(2, minmax(0, 1fr));
  margin-bottom: 14px;
}
.small-kpi {
  font-size: 16px;
}
.nights {
  display: flex;
  gap: 3px;
  flex-wrap: wrap;
}
.night {
  display: inline-block;
  width: 12px;
  height: 22px;
  border-radius: 4px;
  background: var(--good);
}
.legend .night {
  width: 9px;
  height: 9px;
  border-radius: 3px;
  vertical-align: -1px;
}
.night.failed {
  background: var(--bad);
}
.night.missed {
  background: transparent;
  box-shadow: inset 0 0 0 2px var(--bad);
}
.night.today,
.night.untracked {
  background: var(--track);
}
.legend {
  margin: 8px 0 4px;
  gap: 12px;
}
.day-list li {
  padding: 9px 0;
  border-top: 1px solid var(--line);
}
.day-list + .btn {
  margin-top: 8px;
}
.status-connection {
  padding: 10px 0;
  border-top: 1px solid var(--line);
}
.status-connection:first-child {
  border-top: 0;
}
.outcomes {
  margin: 4px 0 8px;
  gap: 10px;
}
.outcome {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 10px;
  border-radius: 16px;
  background: var(--faint);
}
.outcome strong {
  font-size: 22px;
}
.event-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  flex-shrink: 0;
  background: var(--muted);
}
.event-dot.error {
  background: var(--bad);
}
.event-dot.warning {
  background: var(--warn);
}
@media (min-width: 900px) {
  .status {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    align-items: start;
  }
  .status-kpis {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
  .status-kpis.three {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
