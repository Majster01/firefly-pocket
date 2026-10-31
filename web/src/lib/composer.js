import { reactive } from 'vue'

// The add/edit transaction sheet is one instance in App.vue; any page opens it from here
export const composer = reactive({
  open: false,
  split: null, // an existing split (flatten()) to edit, or null for a new one
  preset: null, // fields to start a new one with
  version: 0, // bumped after a save or delete, so lists reload
})

export function compose(preset = null) {
  Object.assign(composer, { open: true, split: null, preset })
}
export function edit(split) {
  Object.assign(composer, { open: true, split, preset: null })
}
export function closeComposer() {
  composer.open = false
}
export function saved() {
  composer.version++
  composer.open = false
}
