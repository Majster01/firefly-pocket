import { describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'
import { usePicked } from './picked.js'

const groceries = { id: '1', name: 'Groceries', spent: 10 }

const open = async () => {
  const rows = ref([groceries, { id: '2', name: 'Fuel', spent: 5 }])
  const sheet = usePicked(rows)
  sheet.pick(groceries)
  await nextTick()

  return { rows, ...sheet }
}

describe('usePicked', () => {
  it('nothing picked → null', () => {
    expect(usePicked(ref([groceries])).picked.value).toBe(null)
  })

  it('a reloaded list → the fresh row', async () => {
    const { rows, picked } = await open()

    rows.value = [{ ...groceries, spent: 25 }]

    expect(picked.value.spent).toBe(25)
  })

  it('a row gone from the list → closed, also when it comes back', async () => {
    const { rows, picked } = await open()

    rows.value = []
    await nextTick()
    rows.value = [groceries]

    expect(picked.value).toBe(null)
  })

  it('a blanked list → closed, also after it loads again', async () => {
    const { rows, picked } = await open()

    rows.value = null
    await nextTick()
    rows.value = [groceries]

    expect(picked.value).toBe(null)
  })

  it('unpick → closed', async () => {
    const { picked, unpick } = await open()

    unpick()

    expect(picked.value).toBe(null)
  })

  it('a key of its own → rows without an id are told apart', () => {
    const rows = ref([{ name: 'Fees' }, { name: 'Uncategorized' }])
    const { picked, pick } = usePicked(rows, (row) => row.id ?? row.name)

    pick(rows.value[1])

    expect(picked.value).toBe(rows.value[1])
  })
})
