import { describe, expect, it } from 'vitest'
import { actionType, conditionType, describe as describeItem, describeAction, describeCondition, grouped, needsValue, summary } from './rules.js'

const accountName = (id) => `Account ${id}`
const rule = (attributes) => ({ attributes: { triggers: [], actions: [], strict: true, ...attributes } })

describe('grouped', () => {
  it('neighbours of one group → a single group', () => {
    const list = [{ group: 'A', n: 1 }, { group: 'A', n: 2 }]

    expect(grouped(list)).toEqual([{ group: 'A', items: list }])
  })

  it('a group again after another one → a new group, nothing is sorted', () => {
    const result = grouped([{ group: 'A' }, { group: 'B' }, { group: 'A' }])

    expect(result.map((entry) => entry.group)).toEqual(['A', 'B', 'A'])
  })

  it('empty list → no groups', () => {
    expect(grouped([])).toEqual([])
  })
})

describe('conditionType / actionType', () => {
  it('a listed condition → its entry', () => {
    expect(conditionType('description_contains')).toMatchObject({ group: 'Description', label: 'Description contains', value: 'text' })
  })

  it('an unlisted condition → a stand-in that keeps the type as Firefly sent it', () => {
    expect(conditionType('made_in_firefly')).toEqual({ group: 'Other', type: 'made_in_firefly', label: 'made_in_firefly', value: 'text', unknown: true })
  })

  it('an unlisted action → the same stand-in', () => {
    expect(actionType('made_in_firefly')).toMatchObject({ group: 'Other', label: 'made_in_firefly', unknown: true })
  })
})

describe('needsValue', () => {
  it.each([
    ['none', false],
    ['optional', false],
    ['text', true],
    ['amount', true],
  ])('value kind %s → %s', (value, expected) => {
    expect(needsValue({ value })).toBe(expected)
  })
})

// `describe` is also the runner's: named apart here and in the block title
describe('describe (rules)', () => {
  it('a condition without a value → its label only', () => {
    expect(describeItem({ type: 'has_any_tag' }, conditionType('has_any_tag'))).toBe('Has a tag')
  })

  it('a text condition → label and the value in quotes', () => {
    expect(describeItem({ type: 'description_contains', value: 'lidl' }, conditionType('description_contains'))).toBe('Description contains “lidl”')
  })

  it('account ids → their names through accountName', () => {
    expect(describeItem({ type: 'account_id', value: '1, 2' }, conditionType('account_id'), accountName)).toBe('Either account is account “Account 1, Account 2”')
  })

  it('a transaction type → its label', () => {
    expect(describeItem({ type: 'transaction_type', value: 'withdrawal' }, conditionType('transaction_type'))).toBe('Type is “Expense”')
  })

  it('an optional value left empty → label only', () => {
    expect(describeItem({ type: 'convert_withdrawal', value: '' }, actionType('convert_withdrawal'))).toBe('Convert to expense')
  })
})

describe('describeCondition / describeAction', () => {
  it('a prohibited condition → prefixed with Not', () => {
    expect(describeCondition({ type: 'has_any_tag', prohibited: true })).toBe('Not: Has a tag')
  })

  it('an action → described like a condition', () => {
    expect(describeAction({ type: 'set_category', value: 'Food' })).toBe('Set category “Food”')
  })
})

describe('summary', () => {
  const triggers = [
    { type: 'description_contains', value: 'lidl' },
    { type: 'description_contains', value: 'spar' },
  ]
  const actions = [{ type: 'set_category', value: 'Food' }]

  it('a strict rule with two conditions → "and 1 more"', () => {
    expect(summary(rule({ triggers, actions }))).toBe('Description contains “lidl” and 1 more → Set category “Food”')
  })

  it('a rule matching any condition → "or 1 more"', () => {
    expect(summary(rule({ triggers, actions, strict: false }))).toBe('Description contains “lidl” or 1 more → Set category “Food”')
  })

  it('inactive conditions → skipped', () => {
    const inactive = [{ ...triggers[0], active: false }, triggers[1]]

    expect(summary(rule({ triggers: inactive, actions }))).toBe('Description contains “spar” → Set category “Food”')
  })

  it('no active action → no arrow part', () => {
    expect(summary(rule({ triggers, actions: [{ ...actions[0], active: false }] }))).toBe('Description contains “lidl” and 1 more')
  })
})
