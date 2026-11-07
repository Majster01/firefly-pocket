<template>
  <button class="item tx" @click="edit(split)">
    <span class="avatar" :class="split.type">{{ avatar }}</span>
    <span class="grow">
      <span class="item-title ellipsis" style="display: block">{{ title }}</span>
      <!-- The chip sits beside the text, not inside the ellipsis, so it is never cut to "Unc" -->
      <span class="item-sub tx-sub">
        <span class="ellipsis"><template v-if="showDate">{{ shortDate(split.date) }} · </template>{{ subtitle }}</span>
        <template v-if="split.type === 'withdrawal'">
          <span v-if="split.category_name" class="chip good uncat cat-tag" :title="split.category_name">{{ split.category_name }}</span>
          <span v-else class="chip warn uncat">Uncategorized</span>
        </template>
        <span v-if="split.split_count > 1" class="chip uncat" :title="`One of ${split.split_count} splits of the same transaction`">Split {{ split.split_index + 1 }}/{{ split.split_count }}</span>
      </span>
    </span>
    <span class="item-end">
      <span class="num tx-amount blurable" :class="[split.type, { in: accountId && signed.amount > 0 }]">{{ amountText }}</span>
      <span v-if="secondText" class="item-sub num blurable" style="display: block">{{ secondText }}</span>
    </span>
  </button>
</template>

<script setup>
import { computed } from 'vue'
import { counterparty, signedAmount, signedFor } from '../lib/data.js'
import { initials, money, shortDate } from '../lib/format.js'
import { edit } from '../lib/composer.js'

const props = defineProps({ split: { type: Object, required: true }, showDate: Boolean, accountId: { type: [String, Number], default: null } })

const title = computed(() => props.split.description)
const party = computed(() => counterparty(props.split))
// An expense shows its category as a tag beside the text, so it is left out of the subtitle
const subtitle = computed(() => [party.value, props.split.type === 'withdrawal' ? '' : props.split.category_name].filter(Boolean).join(' · '))
const avatar = computed(() => (props.split.type === 'transfer' ? '⇄' : initials(party.value || props.split.description)))
// From an account's own page every row is in or out of that account, in its currency
const signed = computed(() => (props.accountId ? signedFor(props.split, props.accountId) : { amount: signedAmount(props.split), code: props.split.currency_code }))
const amountText = computed(() => {
  const { split } = props
  if (split.type === 'transfer' && !props.accountId) return money(split.amount, split.currency_code)
  return money(signed.value.amount, signed.value.code, { signed: true })
})
// The other currency: the shares bought below the euros, or the euros paid below the shares on the VWCE page
const secondText = computed(() => {
  const { split } = props
  if (!split.foreign_amount) return ''
  return signed.value.foreign ? money(split.amount, split.currency_code) : money(split.foreign_amount, split.foreign_currency_code)
})
</script>

<style>
.tx .avatar.deposit {
  color: var(--income);
}
.tx-amount {
  font-weight: 650;
}
.tx-amount.transfer {
  color: var(--muted);
}
.tx-amount.deposit,
.tx-amount.in {
  color: var(--income);
}
.tx-sub {
  display: flex;
  align-items: center;
  gap: 4px;
  min-width: 0;
}
.uncat {
  flex: none;
  padding: 0 6px;
  font-size: 11px;
}
/* A long category name shrinks with an ellipsis instead of pushing the row wide */
.cat-tag {
  flex: 0 1 auto;
  display: block;
  min-width: 0;
  max-width: 45%;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
