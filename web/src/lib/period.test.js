import { describe, expect, it } from 'vitest'
import { parseOffset } from './period.js'

describe('parseOffset', () => {
  it.each([
    ['a month back', '-1', 0, -1],
    ['next month where allowed', '1', 1, 1],
    ['past the furthest', '2', 1, 1],
    ['the future where only the past is shown', '1', 0, 0],
    ['not a number', 'abc', 1, 0],
    ['given twice', ['-1', '-2'], 1, 0],
    ['missing', undefined, 1, 0],
  ])('%s → %s', (_name, value, max, expected) => {
    expect(parseOffset(value, max)).toBe(expected)
  })
})
