<template>
  <div>
    <PageHeader title="Notifications" eyebrow="What Pocket told you">
      <RefreshButton @click="reload" />
      <button v-if="badges.notifications > 0" class="btn small" aria-label="Mark all read" :disabled="busy" @click="markAll"><Icon name="check" :size="17" /><span class="mark-all-label">Mark all read</span></button>
    </PageHeader>

    <div v-if="loadError" class="card empty">
      <h3>Could not load the notifications</h3>
      <p>{{ loadError }}</p>
      <button class="btn small" @click="reload">Retry</button>
    </div>
    <div v-else-if="!items" class="loading"><span class="spinner" /></div>
    <div v-else-if="!items.length" class="card empty">
      <h3>Nothing yet</h3>
      <p>Imports, problems and suggestions waiting are kept here, also when push notifications are off.</p>
    </div>
    <template v-else>
      <template v-for="group in groups" :key="group.day">
        <h2 class="section-title">{{ group.label }}</h2>
        <div class="card flush">
          <ul class="list">
            <li v-for="item in group.items" :key="item.id">
              <button class="item notification" :class="{ quiet: item.quiet }" @click="open(item)">
                <span class="avatar"><Icon :name="ICONS[item.kind] ?? 'bell'" :size="20" /></span>
                <span class="grow">
                  <span class="item-title">{{ item.title }}</span>
                  <span class="item-sub" style="display: block">{{ item.body }}</span>
                </span>
                <span class="item-end tiny muted">
                  {{ time(item.created_at) }}
                  <span v-if="isUnread(item)" class="dot unread-dot" role="img" aria-label="Unread" />
                </span>
              </button>
            </li>
          </ul>
        </div>
      </template>
      <button v-if="next" class="btn block more" :disabled="busy" @click="more">Show older</button>
    </template>

    <p class="small muted settings-link"><RouterLink to="/settings">Choose which ones also arrive as push notifications</RouterLink></p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import RefreshButton from '../components/RefreshButton.vue'
import { get, post } from '../lib/api.js'
import { badges, markNotificationRead, setNotificationCount } from '../lib/badges.js'
import { groupByDay } from '../lib/notifications.js'

const PAGE = 30
const ICONS = { alert: 'bell', 'import.success': 'bank', 'import.failure': 'bank', 'suggestions.digest': 'sparkles', 'upgrade.success': 'refresh' }

const router = useRouter()
const items = ref(null)
const next = ref(null)
const loadError = ref('')
const busy = ref(false)

const groups = computed(() => groupByDay(items.value ?? []))
// A routine import is listed, never counted: nothing asks for it to be read
const isUnread = (item) => !item.quiet && !item.read
const time = (value) => new Date(value).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })

function show(page, { append }) {
  items.value = append ? [...items.value, ...page.data] : page.data
  next.value = page.next
  setNotificationCount(page.unread)
}

async function reload() {
  items.value = null
  loadError.value = ''
  try {
    show(await get('notifications', { limit: PAGE }, { quiet: true }), { append: false })
  } catch (error) {
    loadError.value = error.message
  }
}

async function more() {
  busy.value = true
  try {
    show(await get('notifications', { limit: PAGE, before: next.value }), { append: true })
  } finally {
    busy.value = false
  }
}

async function open(item) {
  if (isUnread(item)) {
    item.read = true
    // Not waited for: the page opens at once, and it only fails quietly
    markNotificationRead(item.id)
  }
  // An alert has no page of its own (its path is Home): stay and read it here
  if (item.path !== '/') router.push(item.path)
}

async function markAll() {
  busy.value = true
  try {
    // Up to what is on screen: a notification that arrived while looking stays unread
    const { unread } = await post('notifications/read-all', { up_to: items.value[0]?.id })
    items.value.forEach((item) => (item.read = true))
    setNotificationCount(unread)
  } finally {
    busy.value = false
  }
}

onMounted(reload)
</script>

<style>
.notification.quiet .item-title,
.notification.quiet .avatar {
  color: var(--muted);
}
.notification.quiet .item-title {
  font-weight: 400;
}
.item-end .unread-dot {
  display: inline-block;
  margin-left: 6px;
}
.settings-link {
  margin-top: 16px;
}
/* As Home's Customize: on a phone the label would push "Notifications" to break mid-word, so only the check stays */
@media (max-width: 420px) {
  .mark-all-label {
    display: none;
  }
}
</style>
