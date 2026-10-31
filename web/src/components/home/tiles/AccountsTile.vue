<template>
  <div class="card flush">
    <h2 class="card-title">
      <span>Accounts</span>
      <RouterLink to="/accounts" class="tiny">All</RouterLink>
    </h2>
    <RouterLink v-for="account in accounts" :key="account.id" :to="`/accounts/${account.id}`" class="item">
      <span class="avatar">{{ account.attributes.currency_code === 'VWCE' ? '📈' : initials(account.attributes.name) }}</span>
      <span class="grow ellipsis item-title">{{ account.attributes.name }}</span>
      <span class="item-end num blurable" :class="{ bad: balanceOf(account) < 0 }">
        {{ money(balanceOf(account), account.attributes.currency_code) }}
        <span v-if="account.attributes.currency_code !== 'EUR' && toEur(balanceOf(account), account.attributes.currency_code) !== null" class="item-sub" style="display: block">
          {{ money(toEur(balanceOf(account), account.attributes.currency_code)) }}
        </span>
      </span>
    </RouterLink>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { assetAccounts, balanceOf, ownAccounts, toEur } from '../../../lib/data.js'
import { initials, money } from '../../../lib/format.js'

const props = defineProps({ options: Object })
const accounts = computed(() => (props.options.include === 'assets' ? assetAccounts().filter((account) => account.attributes.currency_code !== 'VWCE') : ownAccounts()))
</script>
