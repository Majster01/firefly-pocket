<template>
  <div>
    <PageHeader :title="greeting" :eyebrow="todayLabel">
      <button v-if="!editing" class="btn icon small" :aria-label="settings.dashboard.hideBalances ? 'Show amounts' : 'Hide amounts'" @click="settings.dashboard.hideBalances = !settings.dashboard.hideBalances">
        <Icon :name="settings.dashboard.hideBalances ? 'eyeOff' : 'eye'" :size="18" />
      </button>
      <button v-if="!editing" class="btn small" aria-label="Customize Home" @click="editing = true"><Icon name="grid" :size="17" /><span class="customize-label">Customize</span></button>
      <button v-else class="btn small primary" @click="finishEditing">Done</button>
    </PageHeader>

    <div class="period-bar">
      <div class="seg" role="group" aria-label="Period">
        <button :aria-pressed="view === 'month'" :class="{ on: view === 'month' }" @click="setView('month')">Month</button>
        <button :aria-pressed="view === 'year'" :class="{ on: view === 'year' }" @click="setView('year')">Year</button>
      </div>
      <div class="period-nav">
        <button class="btn icon small" :aria-label="`Previous ${view}`" @click="setOffset(offset - 1)"><Icon name="back" :size="18" /></button>
        <button class="period-label" :disabled="offset === 0" :title="offset === 0 ? '' : 'Back to now'" @click="setOffset(0)">{{ periodLabel(view, offset) }}</button>
        <button class="btn icon small" :aria-label="`Next ${view}`" :disabled="offset >= 0" @click="setOffset(offset + 1)"><Icon name="chevron" :size="18" /></button>
      </div>
    </div>

    <div v-if="editing" class="card edit-banner">
      <div class="grow">
        <strong>Customizing the {{ DEVICES[device].label.toLowerCase() }} layout</strong>
        <p class="small muted">Drag <Icon name="grid" :size="13" /> to move, tap the size to resize, <Icon name="eye" :size="13" /> to hide. The {{ device === 'phone' ? 'desktop' : 'phone' }} layout is separate.</p>
      </div>
      <button class="btn small" @click="libraryOpen = true"><Icon name="plus" :size="16" /> Tiles<span v-if="hiddenCount" class="lib-count">{{ hiddenCount }} hidden</span></button>
    </div>

    <TransitionGroup tag="div" name="tile-move" class="home-grid" :class="[`device-${device}`, { hidden: settings.dashboard.hideBalances, editing }]">
      <HomeTile
        v-for="(entry, index) in shownTiles"
        :key="entry.id"
        :entry="entry"
        :device="device"
        :editing="editing"
        :first="index === 0"
        :last="index === shownTiles.length - 1"
        :dragging="dragId === entry.id"
        @drag="startDrag(entry, $event)"
        @move="move(entry, $event)"
        @resize="resize(entry)"
        @options="optionsFor = entry"
        @toggle="toggle(entry)"
      >
        <component :is="COMPONENTS[entry.id]" :view="view" :offset="offset" :range="range" :options="tileOptions(entry)" :version="version" :editing="editing" />
      </HomeTile>
    </TransitionGroup>

    <div v-if="!editing && !shownTiles.length" class="card empty">
      <h3>Home is empty</h3>
      <p>Every tile is hidden in this layout.</p>
      <button class="btn primary" @click="editing = true">Customize</button>
    </div>

    <TileOptionsSheet :entry="optionsFor" :device="device" @close="optionsFor = null" />
    <TileLibrarySheet :open="libraryOpen" :device="device" @close="libraryOpen = false" />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import HomeTile from '../components/home/HomeTile.vue'
import TileOptionsSheet from '../components/home/TileOptionsSheet.vue'
import TileLibrarySheet from '../components/home/TileLibrarySheet.vue'
import NetWorthTile from '../components/home/tiles/NetWorthTile.vue'
import CashflowTile from '../components/home/tiles/CashflowTile.vue'
import CategoriesTile from '../components/home/tiles/CategoriesTile.vue'
import TrendTile from '../components/home/tiles/TrendTile.vue'
import BudgetsTile from '../components/home/tiles/BudgetsTile.vue'
import RecentTile from '../components/home/tiles/RecentTile.vue'
import AccountsTile from '../components/home/tiles/AccountsTile.vue'
import UncategorizedTile from '../components/home/tiles/UncategorizedTile.vue'
import PiggyTile from '../components/home/tiles/PiggyTile.vue'
import SuggestionsTile from '../components/home/tiles/SuggestionsTile.vue'
import ConnectionsTile from '../components/home/tiles/ConnectionsTile.vue'
import { DEVICES, TILES, device, home, optionsOf, periodLabel, periodRange, pullHome, touch } from '../lib/home.js'
import { load } from '../lib/data.js'
import { settings } from '../lib/settings.js'
import { badges, refreshBadges } from '../lib/badges.js'
import { composer } from '../lib/composer.js'

const COMPONENTS = {
  networth: NetWorthTile,
  cashflow: CashflowTile,
  categories: CategoriesTile,
  trend: TrendTile,
  budgets: BudgetsTile,
  recent: RecentTile,
  accounts: AccountsTile,
  uncategorized: UncategorizedTile,
  piggy: PiggyTile,
  suggestions: SuggestionsTile,
  connections: ConnectionsTile,
}

const route = useRoute()
const router = useRouter()

const now = new Date()
const hour = now.getHours()
const greeting = hour < 5 ? 'Good night' : hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'
const todayLabel = new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long' }).format(now)

// Settings → Customize Home lands here with ?customize=1
const editing = ref(route.query.customize === '1')
const optionsFor = ref(null)
const libraryOpen = ref(false)
const dragId = ref(null)
const version = ref(0)

// ------------------------------------------------------------------ period

// The period is in the URL, so Back from a category's transactions returns to the same month or year.
// Without one, Home opens on the view this layout last used.
const layout = computed(() => home[device.value])
const view = computed(() => (['month', 'year'].includes(route.query.view) ? route.query.view : layout.value.view))
const offset = computed(() => Math.min(0, Number.parseInt(route.query.offset ?? '0', 10) || 0))
const range = computed(() => periodRange(view.value, offset.value))

function setView(value) {
  if (layout.value.view !== value) {
    layout.value.view = value
    touch()
  }
  router.replace({ query: { view: value } })
}
watch(editing, (value) => {
  if (!value && route.query.customize) router.replace({ query: { ...route.query, customize: undefined } })
})
const setOffset = (value) => router.replace({ query: { view: view.value, ...(value ? { offset: String(value) } : {}) } })

// ------------------------------------------------------------------- tiles

const enabled = (entry) => !TILES[entry.id].feature || settings.features[TILES[entry.id].feature]
// A notice with nothing to say takes no room, except while customizing
const hasNotice = (entry) => (entry.id === 'suggestions' ? badges.suggestions > 0 : entry.id === 'connections' ? badges.connections > 0 : true)
const shownTiles = computed(() => layout.value.tiles.filter((entry) => enabled(entry) && (editing.value || (entry.visible && hasNotice(entry)))))
const hiddenCount = computed(() => layout.value.tiles.filter((entry) => enabled(entry) && !entry.visible).length)

// The same options object for a tile until its options change: a fresh one on every render of Home (hiding amounts,
// a step of a drag) would look like new options to the tile
const optionsCache = new WeakMap()
function tileOptions(entry) {
  if (!optionsCache.has(entry)) optionsCache.set(entry, computed(() => optionsOf(entry)))
  return optionsCache.get(entry).value
}

function move(entry, step) {
  const tiles = layout.value.tiles
  const visible = tiles.filter(enabled)
  const target = visible[visible.indexOf(entry) + step]
  if (!target) return
  tiles.splice(tiles.indexOf(entry), 1)
  tiles.splice(tiles.indexOf(target) + (step > 0 ? 1 : 0), 0, entry)
  touch()
}

function resize(entry) {
  const sizes = TILES[entry.id][device.value]
  entry.size = sizes[(sizes.indexOf(entry.size) + 1) % sizes.length]
  touch()
}

function toggle(entry) {
  entry.visible = !entry.visible
  touch()
}

function finishEditing() {
  editing.value = false
  dragId.value = null
}

// ---------------------------------------------------------------- dragging

// Pointer events rather than HTML drag and drop, which iOS Safari does not do. The tile under the finger takes the
// dragged tile's place as it moves; near the screen's edge the page scrolls.
let scrollTimer = null
function startDrag(entry, event) {
  event.preventDefault()
  dragId.value = entry.id
  // Scroll anchoring would chase the moving tiles and scroll the page on its own
  document.documentElement.style.overflowAnchor = 'none'
  window.addEventListener('pointermove', onDrag)
  window.addEventListener('pointerup', endDrag, { once: true })
  window.addEventListener('pointercancel', endDrag, { once: true })
}
let lastPoint = null
function reorderUnder({ x, y }) {
  // At the screen's edges the finger is over the sticky header or the tab bar, not a tile: look just inside them
  const top = document.querySelector('.page-head')?.getBoundingClientRect().bottom ?? 0
  const bottom = document.querySelector('.tabbar')?.getBoundingClientRect().top ?? innerHeight
  const grid = document.querySelector('.home-grid')?.getBoundingClientRect()
  const clampedY = Math.min(Math.max(y, top + 12), (bottom > 0 && bottom < innerHeight ? bottom : innerHeight) - 12)
  const clampedX = grid ? Math.min(Math.max(x, grid.left + 12), grid.right - 12) : x
  const under = document.elementFromPoint(clampedX, clampedY)?.closest('[data-tile-id]')
  const targetId = under?.dataset.tileId
  if (!targetId || targetId === dragId.value) return
  const tiles = layout.value.tiles
  const from = tiles.findIndex((entry) => entry.id === dragId.value)
  const to = tiles.findIndex((entry) => entry.id === targetId)
  // Swap only past the target's middle, so two tiles do not trade places back and forth under a still finger.
  // Tiles side by side compare across, tiles above and below compare down.
  const rect = under.getBoundingClientRect()
  const dragged = document.querySelector(`[data-tile-id="${dragId.value}"]`)?.getBoundingClientRect()
  const sideBySide = dragged && dragged.top < rect.bottom - 8 && rect.top < dragged.bottom - 8
  const past = sideBySide ? (to < from ? clampedX < rect.left + rect.width / 2 : clampedX > rect.left + rect.width / 2) : to < from ? clampedY < rect.top + rect.height / 2 : clampedY > rect.top + rect.height / 2
  if (!past) return
  const [moved] = tiles.splice(from, 1)
  tiles.splice(to, 0, moved)
}
function onDrag(event) {
  lastPoint = { x: event.clientX, y: event.clientY }
  reorderUnder(lastPoint)
  const edge = 90
  const speed = event.clientY < edge ? -14 : event.clientY > innerHeight - edge - 70 ? 14 : 0
  clearInterval(scrollTimer)
  // While the page scrolls under a finger that holds still, the tile under it changes too
  scrollTimer = speed
    ? setInterval(() => {
        scrollBy(0, speed)
        reorderUnder(lastPoint)
      }, 16)
    : null
}
function endDrag() {
  window.removeEventListener('pointermove', onDrag)
  clearInterval(scrollTimer)
  document.documentElement.style.overflowAnchor = ''
  if (dragId.value) touch()
  dragId.value = null
}
onBeforeUnmount(endDrag)

// -------------------------------------------------------------------- data

// A transaction saved from the composer: the save made the app ask Pocket's data version again, so the tiles' reads
// go past what was kept (lib/cache.js)
function refresh() {
  version.value++
  load('accounts', { force: true })
  refreshBadges()
}
watch(() => composer.version, refresh)

// Opening Home asks nothing that this browser kept under the current data version
onMounted(() => {
  load('accounts', { force: true })
  load('rates')
  pullHome()
})
</script>

<style>
.period-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 14px;
}
.period-nav {
  display: flex;
  align-items: center;
  gap: 4px;
}
.period-label {
  min-width: 128px;
  white-space: nowrap;
  border: 0;
  background: transparent;
  font-weight: 700;
  font-size: 16px;
  text-align: center;
}
.period-label:disabled {
  cursor: default;
}
.edit-banner {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}
.edit-banner p {
  margin: 2px 0 0;
}
.lib-count {
  font-size: 12px;
  color: var(--muted);
  font-weight: 500;
}
.home-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  grid-auto-flow: row dense;
  gap: 12px;
}
.home-grid.device-desktop {
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 16px;
}
.home-grid .card + .card {
  margin-top: 0;
}
.home-grid.hidden .blurable,
.home-grid.hidden .tx .item-end {
  filter: blur(9px);
}
.home-grid .tx .item-end {
  transition: filter 0.2s ease;
}
/* "All" and "Details" in a tile's title: a 44px target around 17px of text, without growing the title */
.home-grid .card-title > a {
  padding: 13px 12px;
  margin: -13px -12px;
}
.blurable {
  transition: filter 0.2s ease;
}
.tile-move-move {
  transition: transform 0.25s ease;
}
@media (max-width: 420px) {
  .customize-label {
    display: none;
  }
  .period-label {
    min-width: 0;
    font-size: 15px;
  }
}
</style>
