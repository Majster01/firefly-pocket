<template>
  <div class="app-form">
    <app-top-toolbar />

    <van-form ref="form" :name="formName" @submit="onApprove" @failed="onValidationError">
      <van-cell-group inset>
        <div class="van-cell-group-title">{{ $t('rule_suggestion_page.transactions') }}</div>
        <van-cell v-for="transaction in transactions" :key="transaction.journal_id" is-link class="rule-suggestion-transaction" @click="onGoToTransaction(transaction)">
          <template #icon>
            <app-icon :icon="TablerIconConstants.transaction" :size="20" class="mr-2" />
          </template>
          <template #title>
            <div class="list-item-title max-2-lines word-break-word">{{ transaction.description }}</div>
            <div class="list-item-subtitle">{{ transaction.destination_name }} · {{ DateUtils.dateToUI(DateUtils.autoToDate(transaction.date)) }}</div>
          </template>
          <template #value>
            <span class="amount-expense text-size-12 font-weight-700 ellipse-text">{{ getAmountFormatted(transaction) }}</span>
          </template>
        </van-cell>

        <div class="rule-suggestion-reason">
          <div v-if="RuleSuggestion.isLowConfidence(item)" class="rule-suggestion-low-confidence">{{ $t('rule_suggestion_page.low_confidence') }}</div>
          <div v-if="RuleSuggestion.getReason(item)" class="list-item-subtitle">
            <app-icon :icon="TablerIconConstants.magic" :size="16" />
            <span class="word-break-word">{{ RuleSuggestion.getReason(item) }}</span>
          </div>
          <div v-if="RuleSuggestion.getCompany(item)" class="list-item-subtitle">
            <app-icon :icon="TablerIconConstants.ruleSuggestionCompany" :size="16" />
            <span class="word-break-word">{{ RuleSuggestion.getCompany(item) }}</span>
          </div>
        </div>
      </van-cell-group>

      <van-cell-group inset>
        <category-select v-model="category" name="category" :clearable="false" :rules="[rule.required()]" />
      </van-cell-group>

      <van-cell-group inset>
        <div class="van-cell-group-title">{{ $t('rule_suggestion_page.conditions') }}</div>
        <div class="van-cell-fake van-cell flex-column">
          <app-repeater v-model="triggers" :empty-item="emptyTrigger" :is-draggable="false">
            <template #content="{ element, index }">
              <div class="rule-suggestion-trigger">
                <app-select
                  v-model="element.type"
                  :label="$t('rule_suggestion_page.field')"
                  :popup-title="$t('rule_suggestion_page.field')"
                  :list="RuleSuggestion.triggerTypesList()"
                  :columns="1"
                  :has-search="false"
                  :clearable="false"
                  :get-display-value="(type) => $t(type.label)"
                >
                  <template #left-icon>
                    <app-icon :icon="TablerIconConstants.filter" :size="20" />
                  </template>
                </app-select>
                <account-select v-if="RuleSuggestion.isAccountTrigger(element)" v-model="element.account" :name="`trigger-account-${index}`" :clearable="false" :rules="[rule.required()]" />
                <app-field
                  v-else
                  v-model="element.value"
                  :label="$t('rule_suggestion_page.value')"
                  :icon="TablerIconConstants.fieldText2"
                  :name="`trigger-value-${index}`"
                  :rules="[rule.required()]"
                />
              </div>
            </template>
          </app-repeater>
        </div>

        <div v-if="preview" class="rule-suggestion-preview list-item-subtitle">
          <app-icon :icon="TablerIconConstants.ruleSuggestionPreview" :size="16" />
          <span v-if="preview.message">{{ preview.message }}</span>
          <span v-else-if="preview.data">
            {{ $t('rule_suggestion_page.preview_uncategorized', { count: preview.data.uncategorized_count }) }}
            <template v-if="preview.data.categorized_count > 0"> · {{ $t('rule_suggestion_page.preview_categorized', { count: preview.data.categorized_count }) }}</template>
          </span>
          <span v-else>{{ $t('loading') }}</span>
        </div>
      </van-cell-group>

      <div style="margin: 16px">
        <app-button-form-save :label="$t('rule_suggestion_page.approve')" />

        <van-button round plain block class="mt-10" @click="onSetCategoryOnly">
          {{ $t('rule_suggestion_page.set_category_only') }}
        </van-button>

        <van-button plain hairline block type="danger" class="mt-10" @click="onDelete">
          <van-icon name="close" size="20" />
          {{ $t('rule_suggestion_page.reject') }}
        </van-button>
      </div>
    </van-form>
  </div>
</template>

<script setup>
import { get, isEqual } from 'lodash-es'
import { watchDebounced } from '@vueuse/core'
import RouteConstants from '~/constants/RouteConstants'
import TablerIconConstants from '~/constants/TablerIconConstants.js'
import { useForm } from '~/composables/useForm'
import { useToolbar } from '~/composables/useToolbar'
import { generateChildren } from '~/utils/VueUtils'
import { rule } from '~/utils/ValidationUtils.js'
import { formatNumber } from '~/utils/NumberUtils'
import DateUtils from '~/utils/DateUtils'
import UIUtils from '~/utils/UIUtils'
import RuleSuggestion from '~/models/RuleSuggestion'
import RuleSuggestionRepository from '~/repository/RuleSuggestionRepository'
import RuleSuggestionTransformer from '~/transformers/RuleSuggestionTransformer'
import { useRuleSuggestionStore } from '~/stores/ruleSuggestionStore'

const { t } = useI18n()
const ruleSuggestionStore = useRuleSuggestionStore()
const repository = new RuleSuggestionRepository()

const form = ref(null)

const removeFromStore = () => {
  ruleSuggestionStore.ruleSuggestionList = ruleSuggestionStore.ruleSuggestionList.filter((item) => item.id !== itemId.value)
}

const onEvent = (event) => {
  if (event === 'onPostDelete') {
    removeFromStore()
  }
}

const { itemId, item, onDelete, onValidationError, formName } = useForm({
  form: form,
  routeList: RouteConstants.ROUTE_RULE_SUGGESTION_LIST,
  routeForm: RouteConstants.ROUTE_RULE_SUGGESTION_ID,
  model: new RuleSuggestion(),
  onEvent: onEvent,
})

const { category, triggers, transactions } = generateChildren(item, [
  { computed: 'category', parentKey: 'attributes.category' },
  { computed: 'triggers', parentKey: 'attributes.triggers' },
  { computed: 'transactions', parentKey: 'attributes.transactions' },
])

const emptyTrigger = { type: RuleSuggestion.triggerTypes.descriptionContains, value: '', account: null }

// ----- Live preview of what the conditions match -----

// null while a condition is incomplete, {} while loading
const preview = ref(null)
const previewTriggers = computed(() => get(RuleSuggestionTransformer.transformToApi(item.value), 'triggers', []))

watchDebounced(
  previewTriggers,
  async (newTriggers, oldTriggers) => {
    if (isEqual(newTriggers, oldTriggers)) {
      return
    }
    preview.value = null
    if (newTriggers.length === 0 || newTriggers.some((trigger) => !trigger.type || !trigger.value)) {
      return
    }
    preview.value = {}
    const response = await repository.preview({ triggers: newTriggers })
    preview.value = ResponseUtils.isSuccess(response) ? { data: get(response, 'data.data') } : { message: get(response, 'data.message') }
  },
  { debounce: 500 },
)

// ----- Actions -----

const onResolved = async (response, message) => {
  if (!ResponseUtils.isSuccess(response)) {
    return
  }
  removeFromStore()
  UIUtils.showToastSuccess(message, 2000)
  await navigateTo(RouteConstants.ROUTE_RULE_SUGGESTION_LIST)
}

const onApprove = async () => {
  const response = await repository.approve(itemId.value, RuleSuggestionTransformer.transformToApi(item.value))
  await onResolved(response, t('rule_suggestion_page.approved', get(response, 'data.data.transactions_count', 0)))
}

const onSetCategoryOnly = async () => {
  const isCategoryValid = await form.value
    .validate('category')
    .then(() => true)
    .catch(() => false)
  if (!isCategoryValid) {
    return
  }
  const response = await repository.categorize(itemId.value, { category: RuleSuggestionTransformer.transformToApi(item.value).category })
  await onResolved(response, t('rule_suggestion_page.categorized', get(response, 'data.data.transactions_count', 0)))
}

const getAmountFormatted = (transaction) => `${formatNumber(transaction.amount, transaction.currency_decimal_places ?? 2)} ${transaction.currency_symbol ?? ''}`

const onGoToTransaction = async (transaction) => await navigateTo(`${RouteConstants.ROUTE_TRANSACTION_ID}/${transaction.transaction_id}`)

const toolbar = useToolbar()
toolbar.init({
  title: t('rule_suggestion_page.title'),
  backRoute: RouteConstants.ROUTE_RULE_SUGGESTION_LIST,
})
</script>
