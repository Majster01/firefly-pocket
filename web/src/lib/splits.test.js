import { describe, expect, it } from 'vitest'
import { groupPayload, newSplitFrom, planSplitOff, splitOf, totalChange } from './splits.js'

describe('planSplitOff', () => {
  it.each([
    ['30 of 100.00', 100, 30, 2, '70/30'],
    ['0.10 of 0.30, exact where floats are not', 0.3, 0.1, 2, '0.2/0.1'],
    ['0.5 of 1.8169 shares', 1.8169, 0.5, 4, '1.3169/0.5'],
    ['Firefly’s long amount "100.000000000000"', Number('100.000000000000'), 25.5, 2, '74.5/25.5'],
    ['the smallest leftover', 0.02, 0.01, 2, '0.01/0.01'],
    ['all but a cent', 100, 99.99, 2, '0.01/99.99'],
    ['the same amount → nothing would be left', 100, 100, 2, 'Enter an amount below 100.00'],
    ['more than the amount', 100, 100.01, 2, 'Enter an amount below 100.00'],
    ['shares: the same amount at 4 places', 1.8169, 1.8169, 4, 'Enter an amount below 1.8169'],
    ['zero', 100, 0, 2, 'The amount to split off must be above zero'],
    ['a negative amount', 100, -5, 2, 'The amount to split off must be above zero'],
    ['less than the smallest unit', 100, 0.004, 2, 'The amount to split off must be above zero'],
    ['nothing typed (NaN)', 100, NaN, 2, 'Enter the amount to split off'],
    ['the split’s own amount unreadable', NaN, 10, 2, 'The amount does not read as a number'],
  ])('%s → %s', (_name, amount, splitAmount, decimals, expected) => {
    const plan = planSplitOff({ amount, splitAmount, decimals })

    expect(plan.ok ? `${plan.remaining}/${plan.splitOff}` : plan.reason).toBe(expected)
  })
})

describe('totalChange', () => {
  it.each([
    ['unchanged', 100, 100, 2, 0],
    ['lowered', 100, 90, 2, -10],
    ['raised', 100, 100.5, 2, 0.5],
    ['0.30 → 0.20, exact where floats are not', 0.3, 0.2, 2, -0.1],
    ['shares', 1.8169, 1.8, 4, -0.0169],
  ])('%s → %s', (_name, original, edited, decimals, expected) => {
    expect(totalChange({ original, edited, decimals })).toBe(expected)
  })

  it('unchanged → 0, not -0', () => {
    expect(Object.is(totalChange({ original: 5, edited: 5, decimals: 2 }), 0)).toBe(true)
  })
})

const withdrawal = {
  type: 'withdrawal',
  amount: '70.0000',
  description: 'Groceries',
  date: '2026-11-01T12:00:00',
  category_name: 'Food',
  tags: ['weekly'],
  notes: 'bought on the way home',
  source_id: '3',
  destination_name: 'Lidl',
  budget_id: '7',
}

describe('newSplitFrom', () => {
  const build = (edited = withdrawal, overrides = {}) => newSplitFrom({ edited, externalId: 'bank-123', amount: 30, description: 'Cleaning', categoryName: 'Household', ...overrides })

  it('a withdrawal → its accounts, date and budget, with its own amount, description and category', () => {
    expect(build()).toEqual({
      type: 'withdrawal',
      amount: '30.0000',
      description: 'Cleaning',
      date: '2026-11-01T12:00:00',
      category_name: 'Household',
      source_id: '3',
      destination_name: 'Lidl',
      budget_id: '7',
      external_id: 'bank-123',
    })
  })

  it('a deposit → its payer and account as edited', () => {
    const deposit = { type: 'deposit', amount: '70.0000', description: 'Refund', date: '2026-11-01T12:00:00', category_name: null, tags: [], notes: null, source_name: 'Shop', destination_id: '3' }

    expect(build(deposit)).toMatchObject({ type: 'deposit', source_name: 'Shop', destination_id: '3' })
  })

  it('a transfer → both accounts', () => {
    const transfer = { type: 'transfer', amount: '70.0000', description: 'Move', date: '2026-11-01T12:00:00', category_name: null, tags: [], notes: null, source_id: '3', destination_id: '4' }

    expect(build(transfer)).toMatchObject({ type: 'transfer', source_id: '3', destination_id: '4' })
  })

  it('a foreign amount → left behind, it would be the full one on both splits', () => {
    const foreign = { type: 'transfer', amount: '70.0000', description: 'Buy', date: '2026-11-01T12:00:00', source_id: '3', destination_id: '4', foreign_amount: '1.2345', foreign_currency_id: '9', foreign_currency_code: 'VWCE' }
    const split = build(foreign)

    expect(split).not.toHaveProperty('foreign_amount')
    expect(split).not.toHaveProperty('foreign_currency_id')
    expect(split).not.toHaveProperty('foreign_currency_code')
  })

  it('tags and notes → left behind', () => {
    const split = build()

    expect(split).not.toHaveProperty('tags')
    expect(split).not.toHaveProperty('notes')
  })

  it('an original without an external ID → none on the new split', () => {
    expect(build(withdrawal, { externalId: null })).not.toHaveProperty('external_id')
    expect(build(withdrawal, { externalId: '' })).not.toHaveProperty('external_id')
  })

  it('no category chosen → null, which clears nothing else', () => {
    expect(build(withdrawal, { categoryName: '' }).category_name).toBeNull()
  })

  it('the edited split → not changed', () => {
    const before = structuredClone(withdrawal)

    build()

    expect(withdrawal).toEqual(before)
  })
})

describe('groupPayload', () => {
  const edited = { type: 'withdrawal', amount: '70.0000', description: 'Groceries' }
  const three = [{ transaction_journal_id: 11 }, { transaction_journal_id: 12 }, { transaction_journal_id: 13 }]

  it('one split edited, nothing added → just that split and no title', () => {
    expect(groupPayload({ splits: [{ transaction_journal_id: 11 }], groupTitle: '', journalId: 11, edited })).toEqual({
      apply_rules: false,
      transactions: [{ transaction_journal_id: 11, ...edited }],
    })
  })

  it('the middle of three edited → the others by id alone, in order, with a title', () => {
    expect(groupPayload({ splits: three, groupTitle: 'Shopping', journalId: 12, edited })).toEqual({
      apply_rules: false,
      group_title: 'Shopping',
      transactions: [{ transaction_journal_id: 11 }, { transaction_journal_id: 12, ...edited }, { transaction_journal_id: 13 }],
    })
  })

  it('several splits without a title → the edited description is the title', () => {
    expect(groupPayload({ splits: three, groupTitle: null, journalId: 11, edited }).group_title).toBe('Groceries')
  })

  it('journal ids as text or number → still the same split', () => {
    const payload = groupPayload({ splits: three, groupTitle: 'x', journalId: '12', edited })

    expect(payload.transactions[1]).toEqual({ transaction_journal_id: 12, ...edited })
  })

  it('one split plus a new one → two, the new one last without an id, and a title', () => {
    const newSplit = { type: 'withdrawal', amount: '30.0000', description: 'Cleaning' }
    const payload = groupPayload({ splits: [{ transaction_journal_id: 11 }], groupTitle: '', journalId: 11, edited, newSplit })

    expect(payload).toEqual({
      apply_rules: false,
      group_title: 'Groceries',
      transactions: [{ transaction_journal_id: 11, ...edited }, newSplit],
    })
    expect(payload.transactions[1]).not.toHaveProperty('transaction_journal_id')
  })

  it('three splits plus a new one → the others kept by id, an existing title kept', () => {
    const newSplit = { type: 'withdrawal', amount: '5.0000', description: 'Bags' }
    const payload = groupPayload({ splits: three, groupTitle: 'Shopping', journalId: 13, edited, newSplit })

    expect(payload.group_title).toBe('Shopping')
    expect(payload.transactions.map((split) => split.transaction_journal_id)).toEqual([11, 12, 13, undefined])
    expect(payload.transactions[3]).toBe(newSplit)
  })

  it('the edited split not in the group → only ids go back, nothing is edited', () => {
    const payload = groupPayload({ splits: three, groupTitle: 'x', journalId: 99, edited })

    expect(payload.transactions).toEqual(three)
  })
})

describe('splitOf', () => {
  const splits = [
    { transaction_journal_id: 11, external_id: 'bank-1' },
    { transaction_journal_id: 12, external_id: 'bank-2' },
  ]

  it.each([
    ['a number that is there', 12, 'bank-2'],
    ['text that is there', '11', 'bank-1'],
    ['an id that is gone', 99, undefined],
  ])('%s → %s', (_name, journalId, expected) => {
    expect(splitOf({ splits, journalId })?.external_id).toBe(expected)
  })
})
