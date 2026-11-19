import { ref } from 'vue'

// Whether the phone's on-screen keyboard is up, so the floating tab bar can step out of its way.
//
// No browser says so directly on iOS (navigator.virtualKeyboard is Chrome on Android only), so two signals together:
// - a field that types text has focus: on its own this is also true with an iPad's hardware keyboard, and
// - the visual viewport is well short of the layout viewport: the keyboard covers that much of the window.
// The address bar collapsing or expanding changes the gap by far less than the threshold.

export const KEYBOARD_MIN_PX = 150

// Inputs that bring up a picker, a toggle or nothing at all, not the keyboard
const NO_KEYBOARD = new Set(['button', 'checkbox', 'color', 'file', 'hidden', 'image', 'radio', 'range', 'reset', 'submit'])

export function typesText(el) {
  if (!el || el.disabled || el.readOnly) return false
  if (el.isContentEditable) return true
  const tag = el.tagName
  if (tag === 'TEXTAREA') return true
  if (tag !== 'INPUT') return false
  return !NO_KEYBOARD.has((el.type || 'text').toLowerCase())
}

// viewport: window.visualViewport (missing in old browsers: then focus alone decides)
export function keyboardShowing(active, innerHeight, viewport) {
  if (!typesText(active)) return false
  if (!viewport) return true
  return innerHeight - viewport.height * (viewport.scale || 1) > KEYBOARD_MIN_PX
}

export const keyboardOpen = ref(false)

let started = false
export function watchKeyboard(win = window) {
  if (started) return
  started = true
  const doc = win.document
  const vv = win.visualViewport
  const update = () => {
    keyboardOpen.value = keyboardShowing(doc.activeElement, win.innerHeight, vv)
  }
  vv?.addEventListener('resize', update)
  doc.addEventListener('focusin', update)
  // Hopping from one field to the next blurs the first before the second has focus: look once focus has settled
  doc.addEventListener('focusout', () => setTimeout(update, 50))
  update()
}
