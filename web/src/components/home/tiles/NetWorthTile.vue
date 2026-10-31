<template>
  <div class="card hero networth">
    <div class="hero-label">Net worth</div>
    <div class="hero-value blurable">
      <template v-if="store.loaded.accounts">{{ whole(netWorth.total) }}<span class="cents">{{ cents(netWorth.total) }}</span></template>
      <span v-else class="skeleton" style="display: inline-block; width: 180px; height: 40px" />
    </div>
    <div class="small muted hero-sub">
      <span v-if="vwce">VWCE {{ shares(vwce.shares) }} shares · <span class="blurable">{{ money(vwce.eur) }}</span> at {{ money(vwcePrice()) }}</span>
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
const whole = (value) => {
  const text = money(value)
  const match = text.match(/^(.*?)([.,]\d{2})(\D*)$/)
  return match ? match[1] + match[3] : text
}
const cents = (value) => money(value).match(/[.,]\d{2}/)?.[0] ?? ''
const vwce = computed(() => {
  const account = store.accounts.find((item) => item.attributes.type === 'asset' && item.attributes.currency_code === 'VWCE')
  return account ? { shares: balanceOf(account), eur: toEur(balanceOf(account), 'VWCE') ?? 0 } : null
})
</script>

<style>
.networth-note {
  margin: 8px 0 0;
}
.size-half .networth .hero-value,
.size-third .networth .hero-value {
  font-size: 30px;
}
</style>
