import { describe, expect, it } from 'vitest'
import { manualDetail } from './manualImport.js'

describe('manualDetail', () => {
  it.each([
    ['queued says nothing yet', { status: 'queued' }, ''],
    ['importing says nothing yet', { status: 'importing' }, ''],
    ['done: new and suggestions', { status: 'done', summary: { messages: 4 }, suggestions: { written: 2 } }, ' · 4 new · 2 suggestions'],
    ['done with one suggestion', { status: 'done', summary: { messages: 1 }, suggestions: { written: 1 } }, ' · 1 new · 1 suggestion'],
    ['done, suggester failed', { status: 'done', summary: { messages: 4 }, suggest_error: 'down' }, ' · suggester failed: down'],
    ['failed, suggestions made', { status: 'failed', suggestions: { written: 2 } }, ' · 2 suggestions'],
    ['failed, none made: only the failure shows', { status: 'failed', suggestions: { written: 0 } }, ''],
    ['failed, suggester never ran', { status: 'failed' }, ''],
    ['failed, suggester failed', { status: 'failed', suggest_error: 'down' }, ' · suggester failed: down'],
  ])('%s', (_, manual, expected) => {
    expect(manualDetail(manual)).toBe(expected)
  })
})
