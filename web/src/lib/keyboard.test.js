import { describe, expect, it } from 'vitest'
import { keyboardShowing, typesText } from './keyboard.js'

const input = (type, extra = {}) => ({ tagName: 'INPUT', type, ...extra })
const phone = 844
const view = (height, scale = 1) => ({ height, scale })

describe('typesText', () => {
  it('text-like inputs and textareas bring up the keyboard', () => {
    for (const type of ['text', 'number', 'search', 'email', 'password', 'tel', 'url', '']) expect(typesText(input(type))).toBe(true)
    expect(typesText({ tagName: 'TEXTAREA' })).toBe(true)
    expect(typesText({ tagName: 'DIV', isContentEditable: true })).toBe(true)
  })
  it('toggles, buttons, pickers and other elements do not', () => {
    for (const type of ['checkbox', 'radio', 'button', 'submit', 'range', 'color', 'file']) expect(typesText(input(type))).toBe(false)
    expect(typesText({ tagName: 'SELECT' })).toBe(false)
    expect(typesText({ tagName: 'BUTTON' })).toBe(false)
    expect(typesText({ tagName: 'BODY' })).toBe(false)
    expect(typesText(null)).toBe(false)
  })
  it('read-only and disabled fields do not', () => {
    expect(typesText(input('text', { readOnly: true }))).toBe(false)
    expect(typesText(input('text', { disabled: true }))).toBe(false)
  })
})

describe('keyboardShowing', () => {
  it('a focused field with the viewport shrunk by a keyboard', () => {
    expect(keyboardShowing(input('text'), phone, view(500))).toBe(true)
  })
  it('a focused field with a hardware keyboard: the viewport keeps its height', () => {
    expect(keyboardShowing(input('text'), phone, view(phone))).toBe(false)
  })
  it('the address bar moving changes far less than a keyboard', () => {
    expect(keyboardShowing(input('text'), phone, view(phone - 80))).toBe(false)
  })
  it('a shrunk viewport with nothing to type into', () => {
    expect(keyboardShowing({ tagName: 'BODY' }, phone, view(500))).toBe(false)
  })
  it('pinch zoom is counted in layout pixels', () => {
    expect(keyboardShowing(input('text'), phone, view(phone / 2, 2))).toBe(false)
  })
  it('without visualViewport focus alone decides', () => {
    expect(keyboardShowing(input('text'), phone, undefined)).toBe(true)
  })
})
