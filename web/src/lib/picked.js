import { computed, ref, watch } from 'vue'

/**
 * The row a sheet was opened for, always read from the current list, so its numbers follow a reload. A row that is
 * gone (deleted, or the list blanked for another period) closes the sheet for good: it does not reopen by itself.
 */
export function usePicked(rows, keyOf = (row) => row.id) {
  const key = ref(null)
  const picked = computed(() => (key.value === null ? null : (rows.value?.find((row) => keyOf(row) === key.value) ?? null)))

  watch(picked, (row) => {
    if (!row) {
      key.value = null
    }
  })

  return {
    picked,
    pick: (row) => (key.value = keyOf(row)),
    unpick: () => (key.value = null),
  }
}
