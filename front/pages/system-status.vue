<template>
  <div class="app-form">
    <app-top-toolbar />

    <div class="display-flex gap-1 p-1">
      <van-button v-for="option in WINDOW_OPTIONS" :key="option" class="flex-1" size="small" :type="option === days ? 'primary' : 'default'" @click="onSelectDays(option)">
        {{ $t('system_status_page.last_days', { count: option }) }}
      </van-button>
    </div>

    <van-pull-refresh v-model="isRefreshing" @refresh="onRefresh">
      <div v-if="loadError" class="p-1 text-danger word-break-word">{{ $t('system_status_page.unavailable') }}: {{ loadError }}</div>

      <template v-if="status">
        <!-- ------------------------------------------------ nightly imports -->
        <van-cell-group inset>
          <div class="van-cell-group-title">{{ $t('system_status_page.imports.title') }}</div>
          <van-grid :column-num="2">
            <app-config-stat :icon="TablerIconConstants.systemStatus" :name="$t('system_status_page.imports.last_import')" :value="lastImportText" />
            <app-config-stat :icon="TablerIconConstants.transaction" :name="$t('system_status_page.imports.new_transactions')" :value="imports.totals.created" />
            <app-config-stat :icon="TablerIconConstants.bankConnection" :name="$t('system_status_page.imports.failed')" :value="imports.totals.failed" :muted="imports.totals.failed === 0" />
            <app-config-stat :icon="TablerIconConstants.lastSync" :name="$t('system_status_page.imports.missed')" :value="missedNights.length" :muted="missedNights.length === 0" />
          </van-grid>
          <div v-if="imports.tracking_since" class="px-1 pb-1 text-size-12 text-muted">{{ $t('system_status_page.history_since', { date: dayText(imports.tracking_since) }) }}</div>

          <van-cell v-for="day in shownDays" :key="day.date" :title="dayText(day.date)" :label="dayDetail(day)">
            <template #value>
              <span :class="dayClass(day)">{{ dayValue(day) }}</span>
            </template>
          </van-cell>
          <van-cell
            v-if="imports.days.length > COLLAPSED_DAYS"
            is-link
            :title="showAllDays ? $t('system_status_page.imports.show_less') : $t('system_status_page.imports.show_all')"
            @click="showAllDays = !showAllDays"
          />
        </van-cell-group>

        <van-cell-group v-if="imports.recent_failures.length" inset>
          <div class="van-cell-group-title">{{ $t('system_status_page.imports.recent_failures') }}</div>
          <van-cell v-for="failure in imports.recent_failures" :key="failure.at + failure.connection" :title="failure.connection" :value="timeAgo(failure.at)">
            <template #label>
              <span class="text-danger word-break-word">{{ failure.error }}</span>
            </template>
          </van-cell>
        </van-cell-group>

        <van-cell-group v-if="status.manual.total > 0" inset>
          <div class="van-cell-group-title">{{ $t('system_status_page.manual.title') }}</div>
          <van-cell :title="$t('system_status_page.manual.runs', { done: status.manual.done, failed: status.manual.failed, total: status.manual.total })" />
        </van-cell-group>

        <!-- --------------------------------------------- bank connections -->
        <van-cell-group inset>
          <div class="van-cell-group-title">{{ $t('system_status_page.connections.title') }}</div>
          <van-cell v-for="connection in status.connections" :key="connection.name">
            <template #title>
              <div class="flex-center-vertical gap-1">
                <div class="list-item-title flex-1-w">{{ connection.bank }}</div>
                <van-tag :type="statusTagType(connection.status)">{{ $t(`bank_connection_page.status.${connection.status}`) }}</van-tag>
              </div>
              <div class="list-item-subtitle text-size-12">{{ connectionDetail(connection) }}</div>
              <div v-if="connection.last_error" class="text-size-12 text-danger word-break-word">{{ connection.last_error }}</div>
            </template>
          </van-cell>
        </van-cell-group>

        <!-- ------------------------------------- bank connection activity -->
        <van-cell-group inset>
          <div class="van-cell-group-title">{{ $t('system_status_page.events.title') }}</div>
          <van-cell v-if="events.recent.length === 0" :title="$t('system_status_page.events.none')" />
          <template v-else>
            <div class="px-1 pb-1 text-size-12" :class="events.totals.error > 0 ? 'text-danger' : 'text-muted'">
              {{ $t('system_status_page.events.summary', { errors: events.totals.error, warnings: events.totals.warning }) }}
            </div>
            <van-cell v-for="event in shownEvents" :key="event.at + event.step + event.connection" :title="eventTitle(event)" :value="timeAgo(event.at)">
              <template #label>
                <span class="word-break-word" :class="eventClass(event)">{{ eventDetail(event) }}</span>
              </template>
            </van-cell>
            <van-cell
              v-if="events.recent.length > COLLAPSED_EVENTS"
              is-link
              :title="showAllEvents ? $t('system_status_page.events.show_less') : $t('system_status_page.events.show_all')"
              @click="showAllEvents = !showAllEvents"
            />
          </template>
        </van-cell-group>

        <!-- ------------------------------------------ category suggester -->
        <van-cell-group inset>
          <div class="van-cell-group-title">{{ $t('system_status_page.suggester.title') }}</div>
          <van-cell v-if="!suggester.available" :title="$t('system_status_page.unavailable')">
            <template #label>
              <span class="text-danger word-break-word">{{ suggester.error }}</span>
            </template>
          </van-cell>
          <template v-else>
            <van-grid :column-num="3">
              <app-config-stat :icon="TablerIconConstants.lastSync" :name="$t('system_status_page.suggester.runs')" :value="suggester.totals.runs" />
              <app-config-stat :icon="TablerIconConstants.assistant" :name="$t('system_status_page.suggester.calls')" :value="suggester.totals.calls" />
              <app-config-stat
                :icon="TablerIconConstants.ruleSuggestion"
                :name="$t('system_status_page.suggester.below_threshold')"
                :value="`${suggester.totals.low_confidence}/${suggester.totals.suggestions}`"
              />
              <app-config-stat :icon="TablerIconConstants.ruleSuggestionPreview" :name="$t('system_status_page.suggester.searches')" :value="suggester.totals.searches" />
              <app-config-stat
                :icon="TablerIconConstants.ruleSuggestionCompany"
                :name="$t('system_status_page.suggester.tokens')"
                :value="`${compact(suggester.totals.input_tokens)} / ${compact(suggester.totals.output_tokens)}`"
              />
              <app-config-stat :icon="TablerIconConstants.bankConnection" :name="$t('system_status_page.suggester.errors')" :value="suggesterErrors" :muted="suggesterErrors === 0" />
            </van-grid>
            <div v-if="suggester.last_run" class="px-1 pb-1 text-size-12 text-muted">{{ $t('system_status_page.suggester.threshold', { value: suggester.last_run.min_confidence }) }}</div>
            <div v-if="suggester.tracking_since" class="px-1 pb-1 text-size-12 text-muted">{{ $t('system_status_page.history_since', { date: dayText(suggester.tracking_since) }) }}</div>

            <div class="van-cell-group-title">{{ $t('system_status_page.suggester.outcomes') }}</div>
            <van-cell :title="$t('system_status_page.suggester.pending')" :value="suggester.suggestions.pending" />
            <van-cell :title="$t('system_status_page.suggester.approved')" :value="suggester.suggestions.resolved" />
            <van-cell :title="$t('system_status_page.suggester.rejected')" :value="suggester.suggestions.rejected" />

            <div v-if="suggester.recent.length" class="van-cell-group-title">{{ $t('system_status_page.suggester.recent_runs') }}</div>
            <van-cell v-for="run in suggester.recent.slice(0, COLLAPSED_DAYS)" :key="run.id" :title="`${dayText(run.started)} · ${$t(`system_status_page.suggester.source.${run.source}`)}`">
              <template #label>
                <span v-if="run.status === 'failed'" class="text-danger word-break-word">{{ run.error }}</span>
                <span v-else>{{ $t('system_status_page.suggester.run_detail', { calls: run.calls, suggestions: run.suggestions, low: run.low_confidence }) }}</span>
                <div v-for="detail in run.error_details ?? []" :key="detail" class="text-danger word-break-word">{{ detail }}</div>
              </template>
              <template #value>
                <span :class="run.status === 'failed' ? 'text-danger' : 'amount-income'">{{ run.status === 'failed' ? $t('system_status_page.suggester.failed') : '✓' }}</span>
              </template>
            </van-cell>
          </template>
        </van-cell-group>
      </template>
    </van-pull-refresh>
  </div>
</template>

<script setup>
import { get } from 'lodash-es'
import { format } from 'date-fns'
import { formatTimeAgo } from '@vueuse/core'
import RouteConstants from '~/constants/RouteConstants'
import TablerIconConstants from '~/constants/TablerIconConstants'
import SystemStatusRepository from '~/repository/SystemStatusRepository'
import AppConfigStat from '~/components/settings/app-config-stat.vue'
import DateUtils from '~/utils/DateUtils'
import { useToolbar } from '~/composables/useToolbar'

// The time frames offered, in days
const WINDOW_OPTIONS = [7, 30, 90]
// Days (and runs) listed before "show all"
const COLLAPSED_DAYS = 14
// Bank connection events listed before "show all"
const COLLAPSED_EVENTS = 8

const { t, te } = useI18n()
const repository = new SystemStatusRepository()

const days = ref(30)
const status = ref(null)
const loadError = ref('')
const isRefreshing = ref(false)
const showAllDays = ref(false)
const showAllEvents = ref(false)

const today = format(new Date(), 'yyyy-MM-dd')
const compactNumber = new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 })
const compact = (value) => compactNumber.format(value ?? 0)

const imports = computed(() => status.value.imports)
const suggester = computed(() => status.value.suggester)
// An importer from before the events existed has no such key
const events = computed(() => status.value.events ?? { totals: { error: 0, warning: 0 }, recent: [] })
const shownEvents = computed(() => (showAllEvents.value ? events.value.recent : events.value.recent.slice(0, COLLAPSED_EVENTS)))
const shownDays = computed(() => (showAllDays.value ? imports.value.days : imports.value.days.slice(0, COLLAPSED_DAYS)))
const suggesterErrors = computed(() => suggester.value.totals.group_errors + suggester.value.totals.write_errors + suggester.value.totals.failed_runs)

// A past day with no import, since the importer began recording: the nightly run did not happen (today may simply not have run yet)
const missedNights = computed(() => {
  const since = (imports.value.tracking_since ?? '').slice(0, 10)
  return since ? imports.value.days.filter((day) => !day.ran && day.date < today && day.date >= since) : []
})

const lastImportText = computed(() => {
  const last = imports.value.days.find((day) => day.ran)
  return last ? `${last.failed > 0 ? '✗' : '✓'} ${dayText(last.date)}` : t('system_status_page.imports.none_yet')
})

const dayText = (value) => DateUtils.dateToUI(DateUtils.autoToDate(value))
const timeAgo = (value) => formatTimeAgo(DateUtils.autoToDate(value))

const dayValue = (day) => {
  if (!day.ran) {
    return day.date === today ? t('system_status_page.imports.not_yet') : t('system_status_page.imports.no_import')
  }
  return day.failed > 0 ? `✗ ${day.failed}/${day.connections}` : `✓ ${t('system_status_page.imports.created', { count: day.created })}`
}
const dayClass = (day) => (day.ran ? (day.failed > 0 ? 'text-danger' : 'amount-income') : day.date === today ? 'text-muted' : 'text-danger')
const dayDetail = (day) => {
  if (!day.ran) {
    return ''
  }
  const parts = [t('system_status_page.imports.connections', { count: day.connections }, day.connections)]
  if (day.warnings > 0) {
    parts.push(t('system_status_page.imports.warnings', { count: day.warnings }, day.warnings))
  }
  return parts.join(' · ')
}

const translated = (key, fallback) => (te(key) ? t(key) : fallback)

const eventTitle = (event) => {
  const who = event.bank ?? event.connection ?? t('system_status_page.events.unknown_consent')
  const step = translated(`system_status_page.events.step.${event.step}`, event.step)
  const flow = event.flow ? ` (${translated(`system_status_page.events.flow.${event.flow}`, event.flow)})` : ''
  return `${who} · ${step}${flow}`
}
const eventDetail = (event) => [event.reason ? translated(`system_status_page.events.reason.${event.reason}`, event.reason) : null, event.message].filter(Boolean).join(' · ')
const eventClass = (event) => ({ 'text-danger': event.level === 'error', 'text-warning': event.level === 'warning', 'text-muted': event.level === 'info' })

const statusTagType = (value) => ({ active: 'success', expiring: 'warning', expired: 'danger', error: 'danger' })[value] ?? 'default'

const connectionDetail = (connection) => {
  const parts = []
  if (connection.last_import_at) {
    parts.push(`${connection.last_import_ok ? '✓' : '✗'} ${timeAgo(connection.last_import_at)}`)
  } else {
    parts.push(t('system_status_page.connections.never_imported'))
  }
  if (connection.days_left !== null) {
    parts.push(t('system_status_page.connections.days_left', { count: connection.days_left }, connection.days_left))
  }
  return parts.join(' · ')
}

const load = async ({ showLoading = true } = {}) => {
  try {
    status.value = await repository.getStatus(days.value, { showLoading })
    loadError.value = ''
  } catch (error) {
    loadError.value = get(error, 'response.data.message') ?? get(error, 'message', '')
  }
}

const onSelectDays = async (value) => {
  days.value = value
  await load()
}

const onRefresh = async () => {
  await load({ showLoading: false })
  isRefreshing.value = false
}

const toolbar = useToolbar()
toolbar.init({ title: t('system_status'), backRoute: RouteConstants.ROUTE_EXTRAS })

onMounted(load)
</script>
