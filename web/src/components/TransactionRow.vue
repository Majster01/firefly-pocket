<template>
  <button class="item tx" @click="edit(split)">
    <span class="avatar" :class="split.type">{{ avatar }}</span>
    <span class="grow">
      <span class="item-title ellipsis" style="display: block">{{ title }}</span>
      <span class="item-sub ellipsis" style="display: block">
        <template v-if="showDate">{{ shortDate(split.date) }} · </template>{{ subtitle }}
        <span v-if="!split.category_name && split.type === 'withdrawal'" class="chip warn uncat">Uncategorized</span>
      </span>
    </span>
    <span class="item-end">
      <span class="num tx-amount" :class="split.type">{{ amountText }}</span>
      <span v-if="split.foreign_amount" class="item-sub num" style="display: block">{{ money(split.foreign_amount, split.foreign_currency_code) }}</span>
    </span>
  </button>
</template>

<script setup>
import { computed } from 'vue'
import { counterparty, signedAmount } from '../lib/data.js'
import { initials, money, shortDate } from '../lib/format.js'
import { edit } from '../lib/composer.js'

const props = defineProps({ split: { type: Object, required: true }, showDate: Boolean, accountId: { type: [String, Number], default: null } })

const title = computed(() => props.split.description)
const party = computed(() => counterparty(props.split))
const subtitle = computed(() => [props.split.type === 'transfer' ? party.value : party.value, props.split.category_name].filter(Boolean).join(' · '))
const avatar = computed(() => (props.split.type === 'transfer' ? '⇄' : initials(party.value || props.split.description)))
const amountText = computed(() => {
  const { split } = props
  if (split.type === 'transfer') {
    // From an account's own page a transfer is in or out of it
    if (props.accountId) {
      const out = String(split.source_id) === String(props.accountId)
      return money(out ? -split.amount : split.amount, split.currency_code, { signed: true })
    }
    return money(split.amount, split.currency_code)
  }
  return money(signedAmount(split), split.currency_code, { signed: true })
})
</script>

<style>
.tx .avatar.deposit {
  color: var(--income);
}
.tx-amount {
  font-weight: 650;
}
.tx-amount.deposit {
  color: var(--income);
}
.tx-amount.transfer {
  color: var(--muted);
}
.uncat {
  margin-left: 4px;
  padding: 0 6px;
  font-size: 11px;
}
</style>
