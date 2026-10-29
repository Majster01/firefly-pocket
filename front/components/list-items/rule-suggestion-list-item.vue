<template>
  <van-swipe-cell ref="swipeCell" v-bind="clickWithoutSwipe">
    <van-cell>
      <template #title>
        <div class="display-flex transaction-card prevent-select rule-suggestion-list-item">
          <div class="second_column flex-1-w">
            <div class="flex-center-vertical gap-1">
              <app-icon :icon="Category.getIcon(category) ?? TablerIconConstants.category" :size="20" />
              <div class="list-item-title">{{ Category.getDisplayName(category) }}</div>
              <div v-if="RuleSuggestion.isLowConfidence(props.value)" class="rule-suggestion-low-confidence">{{ $t('rule_suggestion_page.low_confidence') }}</div>
            </div>

            <div v-if="description" class="list-item-subtitle">
              <app-icon :icon="TablerIconConstants.description" :size="16" />
              <span class="max-2-lines word-break-word">{{ description }}</span>
            </div>

            <div class="tags-container">
              <div v-for="(trigger, index) in RuleSuggestion.getTriggers(props.value)" :key="index" class="tag-gray text-size-12 gap-1">
                <app-icon :icon="RuleSuggestion.isAccountTrigger(trigger) ? TablerIconConstants.account : TablerIconConstants.search" :size="14" />
                <span class="word-break-word">{{ RuleSuggestion.getTriggerDisplayValue(trigger) }}</span>
              </div>
            </div>
          </div>

          <div class="third_column gap-1">
            <div v-if="transaction" :class="amountClass" class="text-size-12 font-weight-700 ellipse-text">{{ amount }}</div>
            <div class="display-flex flex-column align-items-end text-size-12 gap-1 line-height-normal mt-1">
              <div>{{ dateFormatted }}</div>
              <div class="text-muted">{{ timeAgo }}</div>
              <div v-if="otherTransactionsCount > 0" class="text-muted">{{ $t('rule_suggestion_page.more_transactions', { count: otherTransactionsCount }) }}</div>
            </div>
          </div>
        </div>
      </template>
    </van-cell>

    <template #right>
      <van-button class="delete-button" square type="danger" :text="$t('rule_suggestion_page.reject')" @click="onDelete" />
    </template>
  </van-swipe-cell>
</template>

<script setup>
import { capitalize, get } from 'lodash-es'
import { formatTimeAgo } from '@vueuse/core'
import { useClickWithoutSwipe } from '~/composables/useClickWithoutSwipe'
import TablerIconConstants from '~/constants/TablerIconConstants'
import RuleSuggestion from '~/models/RuleSuggestion'
import Category from '~/models/Category'
import Transaction from '~/models/Transaction'
import DateUtils from '~/utils/DateUtils'
import { formatNumber } from '~/utils/NumberUtils'

const props = defineProps({
  value: Object,
})

const emit = defineEmits(['onEdit', 'onDelete'])

const category = computed(() => RuleSuggestion.getCategory(props.value))
const transaction = computed(() => RuleSuggestion.getFirstTransaction(props.value))
const otherTransactionsCount = computed(() => RuleSuggestion.getTransactions(props.value).length - 1)
const description = computed(() => get(transaction.value, 'description'))

const amount = computed(() => `${formatNumber(get(transaction.value, 'amount'), get(transaction.value, 'currency_decimal_places') ?? 2)} ${get(transaction.value, 'currency_symbol') ?? ''}`)
const amountClass = computed(() => (get(transaction.value, 'type') === Transaction.types.income.fireflyCode ? 'amount-income' : 'amount-expense'))

const date = computed(() => DateUtils.autoToDate(get(transaction.value, 'date')))
const dateFormatted = computed(() => DateUtils.dateToUI(date.value))
const timeAgo = computed(() => (date.value ? capitalize(formatTimeAgo(date.value)) : ''))

const onEdit = async () => {
  emit('onEdit', props.value)
}

const onDelete = async () => {
  emit('onDelete', props.value)
}

const swipeCell = ref(null)
const clickWithoutSwipe = useClickWithoutSwipe({ swipeCell: swipeCell, onClick: onEdit })
</script>
