<template>
  <div class="lockscreen" role="dialog" aria-modal="true" aria-labelledby="lock-title">
    <div class="card lock-card">
      <img src="/favicon.svg" alt="" width="64" height="64" class="lock-logo" />
      <h1 id="lock-title" tabindex="-1">Pocket is locked</h1>
      <p class="muted">Confirm with Face ID, Touch ID or this device's passcode to open it.</p>
      <!-- Browsers only open the passkey prompt from a tap -->
      <button class="btn primary block" :disabled="lock.busy" @click="unlock()">
        <span v-if="lock.busy" class="spinner" />
        <template v-else><Icon name="lock" :size="19" /> Unlock</template>
      </button>
      <p v-if="lock.error" class="small bad" role="alert">{{ lock.error }}</p>
      <p class="tiny muted lock-help">Lost the passkey? Remove Pocket from the Home Screen (or clear this site's data) and open it again. You will paste the Firefly token again, and the lock is off.</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import Icon from './Icon.vue'
import { autoUnlock, lock, unlock } from '../lib/lock.js'

// Cold start, or the lock came up while the app was open (the app coming back from the background is lib/lock.js)
onMounted(autoUnlock)
</script>

<style>
.lockscreen {
  position: fixed;
  inset: 0;
  z-index: 1000;
  display: grid;
  place-items: center;
  padding: calc(var(--safe-top) + 24px) 16px calc(var(--safe-bottom) + 24px);
  overflow-y: auto;
  /* Nothing of the app is under it: the room behind the glass */
  background: var(--bg, #d9c7b3);
}
.lock-card {
  width: 100%;
  max-width: 400px;
  padding: 28px 22px;
  text-align: center;
}
.lock-logo {
  border-radius: 18px;
  box-shadow: var(--card-shadow);
}
.lock-card h1 {
  margin: 12px 0 6px;
  font-size: 26px;
}
.lock-help {
  margin: 18px 0 0;
}
</style>
