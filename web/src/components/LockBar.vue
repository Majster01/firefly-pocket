<template>
  <!-- In <body> like the sheets, so a sheet's "everything else is inert" never reaches the Unlock button -->
  <Teleport to="body">
    <div class="lockbar" role="region" aria-label="Locked">
      <p v-if="lock.error" class="lockbar-error small bad" role="alert">{{ lock.error }}</p>
      <!-- Browsers open the passkey prompt from a tap; Ask automatically (Settings) tries it without one first -->
      <button class="btn primary lockbar-btn" :disabled="lock.busy" @click="unlock()">
        <span v-if="lock.busy" class="spinner" />
        <template v-else><Icon name="lock" :size="19" /> Unlock</template>
      </button>
    </div>
  </Teleport>
</template>

<script setup>
import { onMounted } from 'vue'
import Icon from './Icon.vue'
import { autoUnlock, lock, unlock } from '../lib/lock.js'

// Cold start, or the lock came up while the app was open (the app coming back from the background is lib/lock.js)
onMounted(autoUnlock)
</script>

<style>
/* Where the tab bar is, with the same footprint: the app behind looks as it does unlocked, amounts hidden */
.lockbar {
  position: fixed;
  z-index: var(--z-lock);
  left: calc(12px + var(--safe-left));
  right: calc(12px + var(--safe-right));
  bottom: calc(var(--safe-bottom) + 10px);
  display: grid;
  place-items: center;
  height: 66px;
  max-width: 480px;
  margin-inline: auto;
  padding: 0 8px;
  border-radius: 33px;
  background: color-mix(in srgb, var(--glass-base) 90%, transparent);
  border: 1px solid var(--glass-border);
  box-shadow: var(--frost-edge), var(--float-shadow);
  -webkit-backdrop-filter: blur(30px) saturate(170%);
  backdrop-filter: blur(30px) saturate(170%);
}
.lockbar-btn {
  width: 100%;
  height: 50px;
  border-radius: 25px;
  font-size: 17px;
}
.lockbar-error {
  position: absolute;
  left: 0;
  right: 0;
  bottom: calc(100% + 8px);
  margin: 0;
  text-align: center;
}
/* Over the floating sidebar instead, which shows through blurred: the page looks as it does unlocked */
@media (min-width: 900px) {
  .lockbar {
    background: color-mix(in srgb, var(--glass-base) 35%, transparent);
    -webkit-backdrop-filter: blur(18px) saturate(150%);
    backdrop-filter: blur(18px) saturate(150%);
    box-shadow: none;
    top: 16px;
    bottom: 16px;
    left: 16px;
    right: auto;
    width: 248px;
    height: auto;
    max-width: none;
    margin: 0;
    border-radius: 26px;
  }
  .lockbar-btn {
    width: auto;
    padding: 0 28px;
  }
  .lockbar-error {
    bottom: auto;
    top: calc(50% + 40px);
    padding: 0 12px;
  }
}
</style>
