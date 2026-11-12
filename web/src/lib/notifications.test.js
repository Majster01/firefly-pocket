import { describe, expect, it, vi } from 'vitest'
import { applyAppBadge, groupByDay, tappedUrl } from './notifications.js'

// The viewer's day is the day of the local clock; vite.config.js pins the zone to Europe/Ljubljana for these tests
const at = (...local) => new Date(...local)
const item = (id, date) => ({ id, created_at: date.toISOString() })
const dayLabel = (date) => new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long' }).format(date)

describe('the test zone', () => {
  it('Europe/Ljubljana: 30 March 2025 has 23 hours → the clock change cases below mean something', () => {
    expect(at(2025, 2, 31) - at(2025, 2, 30)).toBe(23 * 3600e3)
  })
})

describe('groupByDay', () => {
  const now = at(2025, 5, 15, 12)

  it('nothing → no groups', () => {
    expect(groupByDay([], now)).toEqual([])
  })

  it('today, yesterday and an older day → three groups in order, with labels', () => {
    const older = at(2025, 5, 2, 10)
    const groups = groupByDay([item(4, at(2025, 5, 15, 9)), item(3, at(2025, 5, 15, 8)), item(2, at(2025, 5, 14, 22)), item(1, older)], now)

    expect(groups.map((group) => [group.day, group.label, group.items.map((entry) => entry.id)])).toEqual([
      ['2025-06-15', 'Today', [4, 3]],
      ['2025-06-14', 'Yesterday', [2]],
      ['2025-06-02', dayLabel(older), [1]],
    ])
  })

  it('00:30 local on 2 June → the label is that day, not the one before', () => {
    const [group] = groupByDay([item(1, at(2025, 5, 2, 0, 30))], now)

    expect(group.label).toBe(dayLabel(at(2025, 5, 2, 12)))
  })

  it('23:30 UTC on the 14th is already the 15th in Ljubljana → grouped under today', () => {
    const groups = groupByDay([{ id: 1, created_at: '2025-06-14T23:30:00.000000Z' }], now)

    expect(groups.map((group) => [group.day, group.label])).toEqual([['2025-06-15', 'Today']])
  })

  it('00:10 on 1 January, a message from 31 December → yesterday across the year', () => {
    const groups = groupByDay([item(1, at(2025, 11, 31, 23, 50))], at(2026, 0, 1, 0, 10))

    expect(groups[0].label).toBe('Yesterday')
  })

  it('31 March 2025 00:30, a message from noon on the 30th (a 23 hour day) → yesterday', () => {
    const groups = groupByDay([item(1, at(2025, 2, 30, 12))], at(2025, 2, 31, 0, 30))

    expect(groups[0].label).toBe('Yesterday')
  })

  it('26 October 2025 23:30, a message from noon on the 25th (before a 25 hour day) → yesterday', () => {
    const groups = groupByDay([item(1, at(2025, 9, 25, 12))], at(2025, 9, 26, 23, 30))

    expect(groups[0].label).toBe('Yesterday')
  })

  it('a day again after another one → a new group, nothing is sorted', () => {
    const groups = groupByDay([item(3, at(2025, 5, 15, 9)), item(2, at(2025, 5, 14, 9)), item(1, at(2025, 5, 15, 7))], now)

    expect(groups.map((group) => group.items.map((entry) => entry.id))).toEqual([[3], [2], [1]])
  })

  it('a message from another year → the label carries the year', () => {
    const [group] = groupByDay([item(1, at(2024, 5, 15, 9))], now)

    expect(group.label).toContain('2024')
  })
})

describe('tappedUrl', () => {
  it.each([
    ['no id', '/', undefined, '/'],
    ['an id', '/', 5, '/?n=5'],
    ['a page', '/bank-connections', 12, '/bank-connections?n=12'],
    ['a path with a query → kept, n added', '/x?a=1', 5, '/x?a=1&n=5'],
    ['a path with a hash → n before the hash', '/x#h', 5, '/x?n=5#h'],
    ['a path with both', '/x?a=1#h', 5, '/x?a=1&n=5#h'],
  ])('%s → %s', (_name, path, id, expected) => {
    expect(tappedUrl(path, id)).toBe(expected)
  })
})

describe('applyAppBadge', () => {
  const nav = (overrides = {}) => ({ setAppBadge: vi.fn(async () => {}), clearAppBadge: vi.fn(async () => {}), ...overrides })

  it('3 → the icon shows 3', async () => {
    const target = nav()

    await applyAppBadge(3, target)

    expect(target.setAppBadge).toHaveBeenCalledWith(3)
    expect(target.clearAppBadge).not.toHaveBeenCalled()
  })

  it('0 → the icon is cleared', async () => {
    const target = nav()

    await applyAppBadge(0, target)

    expect(target.clearAppBadge).toHaveBeenCalledOnce()
    expect(target.setAppBadge).not.toHaveBeenCalled()
  })

  it.each([
    ['a negative count', -1],
    ['not a number', 'many'],
    ['a fraction', 1.5],
    ['nothing', undefined],
  ])('%s → the icon is left alone', async (_name, count) => {
    const target = nav()

    await applyAppBadge(count, target)

    expect(target.setAppBadge).not.toHaveBeenCalled()
    expect(target.clearAppBadge).not.toHaveBeenCalled()
  })

  it.each([
    ['no badge support', {}],
    ['no navigator at all', undefined],
    ['a platform that refuses', nav({ setAppBadge: vi.fn(async () => Promise.reject(new Error('denied'))) })],
    ['a platform that throws', nav({ setAppBadge: vi.fn(() => { throw new Error('denied') }) })],
  ])('%s → resolves without an error', async (_name, target) => {
    await expect(applyAppBadge(2, target)).resolves.toBeUndefined()
  })
})
