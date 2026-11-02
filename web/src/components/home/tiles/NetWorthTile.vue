<template>
  <div class="card hero networth">
    <div class="hero-label">Net worth</div>
    <div class="hero-value blurable" :style="{ '--fit': fit }">
      <template v-if="store.loaded.accounts">{{ parts.whole }}<span class="cents">{{ parts.cents }}</span>{{ parts.after }}</template>
      <span v-else class="skeleton networth-skeleton" />
    </div>
    <div class="small muted hero-sub">
      <span v-if="vwce">VWCE <span class="blurable">{{ shares(vwce.shares) }} shares · {{ money(vwce.eur) }}</span> at <span class="blurable">{{ money(vwcePrice()) }}</span></span>
      <span v-if="netWorth.missing"> · some balances have no exchange rate</span>
    </div>
    <p class="tiny muted networth-note">Today, whatever period Home shows</p>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { balanceOf, netWorthEur, store, toEur, vwcePrice } from '../../../lib/data.js'
import { money, shares } from '../../../lib/format.js'

const netWorth = computed(() => netWorthEur())
// The cents are the last separator and two digits, before any currency sign after them ("14.294,57 €"):
// the first match would be a thousands group in "€12,848.57" (hidden amounts have dots for digits, lib/format.js)
const parts = computed(() => {
  const text = money(netWorth.value.total)
  const match = text.match(/^(.*?)([.,][\d•]{2})([^\d•]*)$/)
  return match ? { whole: match[1], cents: match[2], after: match[3] } : { whole: text, cents: '', after: '' }
})
// The figure's width in ems (the cents are smaller): it takes the font size that fits it in the tile
const fit = computed(() => (parts.value.whole.length + parts.value.after.length) * 0.6 + parts.value.cents.length * 0.33)
const vwce = computed(() => {
  const account = store.accounts.find((item) => item.attributes.type === 'asset' && item.attributes.currency_code === 'VWCE')
  return account ? { shares: balanceOf(account), eur: toEur(balanceOf(account), 'VWCE') ?? 0 } : null
})
</script>

<style>
/* Sized by the tile, not the screen: half a phone is narrower than a third of a desktop */
.networth {
  container-type: inline-size;
}
.networth .hero-value {
  font-size: clamp(16px, calc(100cqi / var(--fit, 6)), 40px);
  white-space: nowrap;
}
.networth-skeleton {
  display: inline-block;
  width: min(180px, 100%);
  height: 1em;
  vertical-align: middle;
}
.networth-note {
  margin: 8px 0 0;
}
</style>
