import { defineStore } from 'pinia'
import { ref } from 'vue'
import { get } from 'lodash-es'
import { useLocalStorage } from '@vueuse/core'
import RuleSuggestionRepository from '~/repository/RuleSuggestionRepository'
import RuleSuggestionTransformer from '~/transformers/RuleSuggestionTransformer'
import { useProfileStore } from '~/stores/profileStore'

export const useRuleSuggestionStore = defineStore('ruleSuggestion', () => {
  const ruleSuggestionList = useLocalStorage('ruleSuggestionList', [])
  const isLoadingRuleSuggestions = ref(false)

  async function fetchRuleSuggestions() {
    const profileStore = useProfileStore()
    if (!profileStore.ruleSuggestionsEnabled) {
      ruleSuggestionList.value = []
      return
    }
    isLoadingRuleSuggestions.value = true
    // Refreshed in the background (dashboard), so no blocking loading overlay. The API returns all suggestions at once.
    const response = await new RuleSuggestionRepository().getAll({ showLoading: false })
    ruleSuggestionList.value = RuleSuggestionTransformer.transformFromApiList(get(response, 'data', []))
    isLoadingRuleSuggestions.value = false
  }

  return {
    ruleSuggestionList,
    isLoadingRuleSuggestions,
    fetchRuleSuggestions,
  }
})
