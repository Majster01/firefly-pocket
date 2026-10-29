<template>
  <app-card-info v-if="profileStore.ruleSuggestionsEnabled && count > 0" class="dashboard-rule-suggestions">
    <app-field-link :label="$t('rule_suggestions_to_review', count)" :icon="TablerIconConstants.ruleSuggestion" @click="navigateTo(RouteConstants.ROUTE_RULE_SUGGESTION_LIST)" />
  </app-card-info>
</template>

<script setup>
import RouteConstants from '~/constants/RouteConstants.js'
import TablerIconConstants from '~/constants/TablerIconConstants.js'

const profileStore = useProfileStore()
const ruleSuggestionStore = useRuleSuggestionStore()

const count = computed(() => ruleSuggestionStore.ruleSuggestionList.length)

// Suggestions arrive nightly => refresh the count whenever the dashboard opens
onMounted(() => ruleSuggestionStore.fetchRuleSuggestions())
</script>
