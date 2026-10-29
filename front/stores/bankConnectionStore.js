import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { get } from 'lodash-es'
import BankConnectionRepository from '~/repository/BankConnectionRepository'

export const useBankConnectionStore = defineStore('bankConnection', () => {
  const bankConnectionList = ref([])
  const isLoadingBankConnections = ref(false)

  // Consents that need re-authenticating soon or now
  const attentionCount = computed(() => bankConnectionList.value.filter((item) => ['expiring', 'expired', 'error'].includes(item.status)).length)

  async function fetchBankConnections({ showLoading = true, showErrorToast = true } = {}) {
    isLoadingBankConnections.value = true
    try {
      const response = await new BankConnectionRepository().getAll({ showLoading, showErrorToast })
      bankConnectionList.value = get(response, 'data', [])
    } finally {
      isLoadingBankConnections.value = false
    }
  }

  return {
    bankConnectionList,
    isLoadingBankConnections,
    attentionCount,
    fetchBankConnections,
  }
})
