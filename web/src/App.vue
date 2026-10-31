<template>
  <RouterView v-if="route.meta.bare" />
  <div v-else class="shell">
    <aside class="side glass" aria-label="Sections">
      <RouterLink to="/" class="brand">
        <img src="/favicon.svg" alt="" width="34" height="34" />
        <div>
          <div class="brand-name">Pocket</div>
          <div class="tiny muted">Firefly III</div>
        </div>
      </RouterLink>
      <button class="btn primary block side-add" @click="compose()"><Icon name="plus" :size="20" /> New transaction</button>
      <nav ref="sideNav" class="side-nav" :class="{ 'more-below': moreBelow }" @scroll.passive="measureSide">
        <template v-for="section in sections" :key="section.title">
          <div class="side-section">{{ section.title }}</div>
          <RouterLink v-for="item in section.items" :key="item.to" :to="item.to" class="side-link" :class="{ on: isActive(item.to) }" :aria-current="isActive(item.to) ? 'page' : null">
            <Icon :name="item.icon" :size="20" />
            <span class="grow">{{ item.label }}</span>
            <span v-if="badgeFor(item)" class="side-badge">{{ badgeFor(item) }}</span>
          </RouterLink>
        </template>
      </nav>
    </aside>

    <main class="main">
      <RouterView v-slot="{ Component }">
        <component :is="Component" :key="route.path" />
      </RouterView>
    </main>

    <nav class="tabbar" aria-label="Tabs">
      <RouterLink v-for="tab in tabs.slice(0, 2)" :key="tab.to" :to="tab.to" class="tab" :class="{ on: tabOn(tab) }" :aria-current="tabOn(tab) ? 'page' : null">
        <span class="tab-pill"><Icon :name="tab.icon" :size="23" /></span>
        <span class="tab-label">{{ tab.label }}</span>
      </RouterLink>
      <button class="tab-plus" aria-label="New transaction" @click="compose()"><Icon name="plus" :size="26" :stroke="2.2" /></button>
      <RouterLink v-for="tab in tabs.slice(2)" :key="tab.to" :to="tab.to" class="tab" :class="{ on: tabOn(tab) }" :aria-current="tabOn(tab) ? 'page' : null">
        <span class="tab-pill">
          <Icon :name="tab.icon" :size="23" />
          <i v-if="tab.to === '/more' && moreDot" class="tab-dot" />
        </span>
        <span class="tab-label">{{ tab.label }}</span>
      </RouterLink>
    </nav>

    <TransactionSheet />
  </div>
  <Toasts />
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Icon from './components/Icon.vue'
import Toasts from './components/Toasts.vue'
import TransactionSheet from './components/TransactionSheet.vue'
import { NAV } from './nav.js'
import { settings } from './lib/settings.js'
import { compose } from './lib/composer.js'
import { badges, refreshBadges } from './lib/badges.js'
import { load } from './lib/data.js'

const route = useRoute()

const tabs = [
  { to: '/', label: 'Home', icon: 'home' },
  { to: '/transactions', label: 'Activity', icon: 'list' },
  { to: '/accounts', label: 'Accounts', icon: 'wallet' },
  { to: '/more', label: 'More', icon: 'grid' },
]

const sections = computed(() => NAV.map((section) => ({ ...section, items: section.items.filter((item) => !item.feature || settings.features[item.feature]) })).filter((section) => section.items.length))
const isActive = (to) => (to === '/' ? route.path === '/' : route.path === to || route.path.startsWith(`${to}/`))
const inMore = computed(() => !tabs.slice(0, 3).some((tab) => isActive(tab.to)))
// More stands in for every section without a tab of its own: it is the current tab on those pages too
const tabOn = (tab) => isActive(tab.to) || (tab.to === '/more' && inMore.value)
const badgeFor = (item) => (item.badge ? badges[item.badge] : item.to === '/bank-connections' ? badges.connections : 0)
// Only sections that are switched on can call for attention
const moreDot = computed(() => (settings.features.ruleSuggestions && badges.suggestions > 0) || (settings.features.bankConnections && badges.connections > 0))

// A short window can hide the sidebar's last sections: fade its bottom edge while there is more to scroll to
const sideNav = ref(null)
const moreBelow = ref(false)
const measureSide = () => {
  const el = sideNav.value
  moreBelow.value = Boolean(el) && el.scrollTop + el.clientHeight < el.scrollHeight - 2
}
// The window height and the list of sections both change what fits
const sideObserver = new ResizeObserver(measureSide)
watch(
  sideNav,
  (el) => {
    sideObserver.disconnect()
    if (el) sideObserver.observe(el)
  },
  { flush: 'post' },
)
onBeforeUnmount(() => sideObserver.disconnect())

onMounted(() => {
  if (settings.token) {
    refreshBadges()
    load('rates')
  }
})
watch(() => route.path, () => refreshBadges())
</script>

<style>
.shell {
  min-height: 100dvh;
}
.main {
  padding: 0 calc(16px + var(--safe-right)) calc(110px + var(--safe-bottom)) calc(16px + var(--safe-left));
  max-width: 760px;
  margin: 0 auto;
}

/* --------------------------------------------- phone: floating tab bar */
.side {
  display: none;
}
.tabbar {
  position: fixed;
  z-index: 50;
  left: calc(12px + var(--safe-left));
  right: calc(12px + var(--safe-right));
  bottom: calc(var(--safe-bottom) + 10px);
  display: grid;
  /* minmax(0, …): at 200% zoom the tabs shrink instead of pushing the bar off screen */
  grid-template-columns: repeat(2, minmax(0, 1fr)) auto repeat(2, minmax(0, 1fr));
  align-items: center;
  height: 66px;
  /* A tablet in portrait gets a bar, not a full-width strip */
  max-width: 480px;
  margin-inline: auto;
  padding: 0 8px;
  border-radius: 33px;
  /* Dense enough that page text scrolling underneath never competes with the labels */
  background: color-mix(in srgb, var(--glass-base) 70%, transparent);
  border: 1px solid var(--glass-border);
  box-shadow: var(--frost-edge), var(--float-shadow);
  -webkit-backdrop-filter: blur(30px) saturate(170%);
  backdrop-filter: blur(30px) saturate(170%);
}
.tab {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1px;
  min-width: 0;
  /* Over the bar's glass the room still shows through: --muted is too faint for 11.5px labels */
  color: var(--bare-text);
  font-size: 11.5px;
  font-weight: 600;
}
.tab-pill {
  position: relative;
  display: grid;
  place-items: center;
  width: min(52px, 100%);
  height: 32px;
  border-radius: 16px;
  transition: background 0.2s ease;
}
.tab.on {
  color: var(--text);
}
.tab.on .tab-pill {
  background: var(--pill);
  box-shadow: 0 3px 12px rgba(70, 40, 20, 0.16);
}
.tab-dot {
  position: absolute;
  top: 3px;
  /* 11px in from the edge of a full 52px pill, following the icon when the pill narrows */
  right: calc(50% - 15px);
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--accent);
  box-shadow: 0 0 0 2px var(--glass-base);
}
.tab-plus {
  display: grid;
  place-items: center;
  width: 52px;
  height: 52px;
  margin: 0 6px;
  border: 0;
  border-radius: 50%;
  background: var(--ink);
  color: var(--on-ink);
  box-shadow: 0 6px 18px rgba(23, 19, 15, 0.3);
}
.tab-plus:active {
  transform: scale(0.94);
}
/* Very narrow (a zoomed-in phone): icons only; the labels stay for screen readers */
@media (max-width: 299px) {
  .tab-label {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
  }
  .tab-plus {
    width: 46px;
    height: 46px;
    margin: 0 2px;
  }
}

/* ------------------------------------------- desktop: floating sidebar */
@media (min-width: 900px) {
  .tabbar {
    display: none;
  }
  .side {
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 16px;
    bottom: 16px;
    left: 16px;
    width: 248px;
    padding: 18px 12px;
    border-radius: 26px;
    z-index: 40;
  }
  .main {
    max-width: 1180px;
    /* Centred in the room beside the sidebar (16 + 248 + 16px), not on the whole window */
    margin: 0 0 0 calc(280px + max(0px, (100vw - 280px - 1180px) / 2));
    padding: 0 32px 48px;
  }
  /* The sections scroll on their own under the brand and the add button */
  .side-nav {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    scrollbar-width: thin;
    /* Room for the focus ring, which the scroller would clip */
    margin: 0 -4px;
    padding: 0 4px 4px;
  }
  .side-nav.more-below {
    -webkit-mask-image: linear-gradient(to bottom, #000 calc(100% - 44px), transparent);
    mask-image: linear-gradient(to bottom, #000 calc(100% - 44px), transparent);
  }
}
.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 2px 8px 16px;
}
.brand img {
  border-radius: 10px;
}
.brand-name {
  font-weight: 750;
  font-size: 18px;
  letter-spacing: -0.02em;
}
.side-add {
  margin-bottom: 10px;
}
.side-section {
  margin: 16px 12px 6px;
  font-size: 11.5px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--muted);
}
.side-link {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 9px 12px;
  margin: 1px 0;
  border-radius: 14px;
  font-weight: 550;
  color: color-mix(in srgb, var(--text) 82%, transparent);
}
.side-link:hover {
  background: var(--faint);
}
.side-link.on {
  background: var(--pill);
  color: var(--text);
  box-shadow: 0 4px 14px rgba(70, 40, 20, 0.14);
}
.side-badge {
  min-width: 22px;
  padding: 1px 7px;
  border-radius: 999px;
  background: var(--badge);
  color: var(--on-badge);
  font-size: 12px;
  font-weight: 700;
  text-align: center;
}
/* A laptop-height window: tighter rows so every section fits, or nearly */
@media (min-width: 900px) and (max-height: 820px) {
  .side {
    padding: 12px 12px;
  }
  .brand {
    padding-bottom: 10px;
  }
  .side-section {
    margin: 10px 12px 4px;
  }
  .side-link {
    padding-block: 6px;
  }
}
</style>
