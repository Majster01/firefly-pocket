import { describe, expect, it } from 'vitest'
import { budgetStanding, isOverLimit, leftOf, ratioOf } from './budget.js'

const money = (amount) => `€${amount.toFixed(2)}`
const standing = (limit, spent) => budgetStanding({ limit, left: leftOf(limit, spent) }, 'November 2026', money)

describe('leftOf', () => {
  it.each([
    ['under the limit', 400, 312.4, 87.6],
    ['over by a cent', 400, 400.01, -0.01],
    ['no limit', 0, 5, -5],
  ])('%s → %s', (_name, limit, spent, expected) => {
    expect(leftOf(limit, spent)).toBe(expected)
  })

  it('a float’s hair over the limit → 0 left, not -0', () => {
    expect(leftOf(0.3, 0.1 + 0.2)).toBe(0)
  })
})

describe('ratioOf / isOverLimit', () => {
  it.each([
    ['half spent', 400, 200, 0.5, false],
    ['exactly spent', 400, 400, 1, false],
    ['a float’s hair over the limit', 0.3, 0.1 + 0.2, 1, false],
    ['half over', 400, 600, 1.5, true],
    ['no limit', 0, 25, 0, false],
  ])('%s → its ratio, and whether it is over', (_name, limit, spent, ratio, over) => {
    expect([ratioOf(limit, spent), isOverLimit({ limit, left: leftOf(limit, spent) })]).toEqual([ratio, over])
  })

  it('over by a cent → above 1', () => {
    expect(ratioOf(400, 400.01)).toBeGreaterThan(1)
  })
})

describe('budgetStanding', () => {
  it.each([
    ['under the limit', 400, 312.4, '€87.60 left of €400.00'],
    ['exactly spent', 400, 400, '€0.00 left of €400.00'],
    ['over by a cent', 400, 400.01, '€0.01 over €400.00'],
    ['a float’s hair over the limit', 0.3, 0.1 + 0.2, '€0.00 left of €0.30'],
    ['no limit', 0, 25, 'No limit in November 2026'],
  ])('%s → %s', (_name, limit, spent, expected) => {
    expect(standing(limit, spent)).toBe(expected)
  })
})
