import { describe, expect, it } from 'vitest'
import { fromRouteQuery, isFiltered, pageState, searchQuery, showsTotal, toRouteQuery } from './search.js'

const month = { start: '2026-11-01', end: '2026-11-30' }

describe('searchQuery', () => {
  it.each([
    ['nothing set', {}, ''],
    ['a filter alone', { filter: 'withdrawal' }, ''],
    ['a blank search with a filter', { search: '   ', filter: 'withdrawal' }, ''],
    ['a search', { search: ' lidl ' }, 'lidl'],
    ['a budget with all types', { budget: 'Groceries' }, 'budget_is:"Groceries"'],
    ['a budget’s expenses', { budget: 'Groceries', filter: 'withdrawal' }, 'budget_is:"Groceries" type:withdrawal'],
    ['a budget’s month', { budget: 'Eating out', filter: 'withdrawal', ...month }, 'budget_is:"Eating out" date_after:2026-11-01 date_before:2026-11-30 type:withdrawal'],
    ['a category’s month', { category: 'Fuel', filter: 'withdrawal', ...month }, 'category_is:"Fuel" date_after:2026-11-01 date_before:2026-11-30 type:withdrawal'],
    ['a budget’s uncategorized', { budget: 'Fun', filter: 'uncategorized' }, 'budget_is:"Fun" has_no_category:true type:withdrawal'],
    ['uncategorized alone', { filter: 'uncategorized' }, 'has_no_category:true type:withdrawal'],
    ['a quote in a budget name', { budget: 'The "fun" one' }, 'budget_is:"The \\"fun\\" one"'],
    ['a quote in a category name', { category: '12" records' }, 'category_is:"12\\" records"'],
  ])('%s → %s', (_name, state, expected) => {
    expect(searchQuery(state)).toBe(expected)
  })
})

describe('fromRouteQuery', () => {
  it('an empty URL → the unset state', () => {
    expect(fromRouteQuery({})).toEqual({ search: '', filter: 'all', category: '', budget: '', start: '', end: '' })
  })

  it.each([
    ['an unknown filter', { filter: 'everything' }, 'filter', 'all'],
    ['a budget given twice', { budget: ['a', 'b'] }, 'budget', ''],
    ['a date that is not a day', { start: 'yesterday' }, 'start', ''],
    ['an end that is not a day', { end: '2026-11' }, 'end', ''],
  ])('%s → %s unset', (_name, query, key, expected) => {
    expect(fromRouteQuery(query)[key]).toBe(expected)
  })
})

describe('toRouteQuery', () => {
  it('the unset state → no parameters', () => {
    expect(toRouteQuery(pageState())).toEqual({})
  })

  it('an end without a start → dropped', () => {
    expect(toRouteQuery({ end: '2026-11-30' })).toEqual({})
  })

  it.each([
    ['a budget sheet’s state', { budget: 'Groceries', filter: 'withdrawal', ...month }],
    ['a category sheet’s state', { category: 'Fuel', filter: 'withdrawal', ...month }],
    ['every field set', { search: 'lidl', filter: 'withdrawal', category: 'Fuel', budget: 'Groceries', ...month }],
  ])('%s → a URL the page reads back as it was', (_name, state) => {
    const page = fromRouteQuery(toRouteQuery(state))

    expect(page).toEqual(pageState(state))
    // Compared as JSON by the page, so the key order counts too
    expect(JSON.stringify(page)).toBe(JSON.stringify(pageState(state)))
  })
})

describe('isFiltered / showsTotal', () => {
  it.each([
    ['nothing set', {}, false, false],
    ['a filter alone', { filter: 'deposit' }, true, false],
    ['a search alone', { search: 'lidl' }, false, true],
    ['a blank search', { search: '  ' }, false, false],
    ['a budget alone', { budget: 'Groceries' }, true, true],
    ['a category alone', { category: 'Fuel' }, true, true],
    ['a period', month, true, true],
  ])('%s → filtered %s, total %s', (_name, state, filtered, total) => {
    expect([isFiltered(state), showsTotal(state)]).toEqual([filtered, total])
  })
})
