import test from 'node:test'
import assert from 'node:assert/strict'
import { buildNotification, getBadgeCount, getTargetUrl, parsePayload } from './push.mjs'

test('parsePayload reads JSON and falls back to plain text', () => {
  assert.deepEqual(parsePayload('{"title":"T","body":"B"}'), { title: 'T', body: 'B' })
  assert.deepEqual(parsePayload('hello'), { body: 'hello' })
  assert.deepEqual(parsePayload(''), { body: '' })
  assert.deepEqual(parsePayload('42'), { body: '42' })
})

test('buildNotification always has a title and a body, and the path to open', () => {
  assert.deepEqual(buildNotification({}), { title: 'Pico', options: { body: '', icon: '/android-chrome-192x192.png', data: { path: '/' } } })
  const { title, options } = buildNotification({ title: 'Imported', body: '12 new', tag: 'import', path: '/transactions/list' })
  assert.equal(title, 'Imported')
  assert.equal(options.tag, 'import')
  assert.deepEqual(options.data, { path: '/transactions/list' })
})

test('getBadgeCount is null unless the push carries a valid count', () => {
  assert.equal(getBadgeCount({}), null)
  assert.equal(getBadgeCount({ badge: null }), null)
  assert.equal(getBadgeCount({ badge: 0 }), 0)
  assert.equal(getBadgeCount({ badge: 7 }), 7)
  assert.equal(getBadgeCount({ badge: '3' }), 3)
  assert.equal(getBadgeCount({ badge: -1 }), null)
  assert.equal(getBadgeCount({ badge: 'many' }), null)
  assert.equal(getBadgeCount({ badge: 1.5 }), null)
})

test('getTargetUrl stays on the origin of the app', () => {
  const origin = 'https://pico.example'
  assert.equal(getTargetUrl('/bank-connections/list', origin), 'https://pico.example/bank-connections/list')
  assert.equal(getTargetUrl(undefined, origin), 'https://pico.example/')
  assert.equal(getTargetUrl('//evil.example/x', origin), 'https://pico.example/')
  assert.equal(getTargetUrl('https://evil.example/x', origin), 'https://pico.example/')
})
