<template>
  <div>
    <PageHeader title="More" />
    <template v-for="section in sections" :key="section.title">
      <h2 class="section-title">{{ section.title }}</h2>
      <div class="card flush">
        <RouterLink v-for="item in section.items" :key="item.to" :to="item.to" class="item">
          <span class="avatar"><Icon :name="item.icon" :size="20" /></span>
          <span class="grow item-title">{{ item.label }}</span>
          <span v-if="badgeFor(item)" class="side-badge">{{ badgeFor(item) }}</span>
          <Icon name="chevron" class="chev" :size="18" />
        </RouterLink>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import PageHeader from '../components/PageHeader.vue'
import Icon from '../components/Icon.vue'
import { NAV } from '../nav.js'
import { settings } from '../lib/settings.js'
import { badges } from '../lib/badges.js'

// Everything the tab bar has no room for
const TABS = ['/', '/transactions', '/accounts']
const sections = computed(() =>
  NAV.map((section) => ({ ...section, items: section.items.filter((item) => !TABS.includes(item.to) && (!item.feature || settings.features[item.feature])) })).filter((section) => section.items.length),
)
const badgeFor = (item) => (item.badge ? badges[item.badge] : 0)
</script>
