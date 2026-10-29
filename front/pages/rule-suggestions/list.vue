<template>
  <div :class="formClass">
    <app-top-toolbar />

    <empty-list v-if="isEmpty" :title="$t('rule_suggestion_page.list_empty')" :subtitle="$t('rule_suggestion_page.list_empty_subtitle')" />

    <van-pull-refresh v-model="isRefreshing" @refresh="onRefresh">
      <van-list class="p-1" :finished="isFinished" @load="onLoadMore">
        <rule-suggestion-list-item v-for="item in list" :key="item.id" :value="item" @on-edit="onEdit" @on-delete="onDelete" />
      </van-list>
    </van-pull-refresh>
  </div>
</template>

<script setup>
import RouteConstants from '~/constants/RouteConstants'
import { useRuleSuggestionStore } from '~/stores/ruleSuggestionStore'
import { useList } from '~/composables/useList'
import RuleSuggestion from '~/models/RuleSuggestion'
import { useToolbar } from '~/composables/useToolbar'
import { animateSwipeList } from '~/utils/AnimationUtils.js'

const ruleSuggestionStore = useRuleSuggestionStore()

const onEvent = (event, payload) => {
  if (event === 'onPostDelete') {
    ruleSuggestionStore.ruleSuggestionList = ruleSuggestionStore.ruleSuggestionList.filter((item) => item.id !== payload.id)
  }
}

const { isLoading, isFinished, isRefreshing, list, isEmpty, onEdit, onDelete } = useList({
  routeList: RouteConstants.ROUTE_RULE_SUGGESTION_LIST,
  routeForm: RouteConstants.ROUTE_RULE_SUGGESTION_ID,
  model: new RuleSuggestion(),
  onEvent: onEvent,
})

const formClass = computed(() => ({
  'app-form': true,
  empty: isEmpty.value,
}))

const onRefresh = async () => {
  isLoading.value = true
  isRefreshing.value = true

  await ruleSuggestionStore.fetchRuleSuggestions()

  isLoading.value = false
  isRefreshing.value = false

  onLoadMore()
}

const onLoadMore = () => {
  list.value = ruleSuggestionStore.ruleSuggestionList
  // The whole list comes from the store => stop van-list from triggering more loads on scroll
  isFinished.value = true
}

// Suggestions arrive nightly => always show the latest ones
onMounted(() => onRefresh())

// -----

const toolbar = useToolbar()
const { t } = useI18n()

toolbar.init({
  title: t('rule_suggestions'),
  backRoute: RouteConstants.ROUTE_EXTRAS,
})

animateSwipeList(list)
</script>
