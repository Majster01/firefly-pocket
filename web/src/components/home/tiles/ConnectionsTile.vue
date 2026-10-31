<template>
  <div v-if="attention.length" class="notice-stack">
    <RouterLink v-for="connection in attention" :key="connection.name" to="/bank-connections" class="card attention">
      <span class="avatar"><img v-if="connection.bank.logo" :src="connection.bank.logo" alt="" /></span>
      <span class="grow">
        <strong>{{ connection.bank.name }}: {{ note(connection) }}</strong>
        <span class="item-sub" style="display: block">{{ connection.status === 'needs_mapping' ? 'Choose the accounts to import' : 'Renew the bank consent to keep importing' }}</span>
      </span>
      <Icon name="chevron" class="chev" />
    </RouterLink>
  </div>
  <!-- Until the list arrives nothing is known to be fine: Home only shows this tile when the badge counts a problem -->
  <div v-else-if="connections === null" class="card attention" aria-busy="true">
    <span class="avatar skeleton" />
    <span class="grow">
      <span class="skeleton notice-line" />
      <span class="skeleton notice-line short" />
    </span>
  </div>
  <RouterLink v-else-if="editing" to="/bank-connections" class="card attention">
    <span class="avatar"><Icon name="bank" :size="20" /></span>
    <span class="grow">
      <strong>All bank connections are fine</strong>
      <span class="item-sub" style="display: block">Shows up when a consent ends soon or an import fails</span>
    </span>
  </RouterLink>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import Icon from '../../Icon.vue'
import { get } from '../../../lib/api.js'

const props = defineProps({ editing: Boolean, version: Number })
const connections = ref(null)
const attention = computed(() => (connections.value ?? []).filter((item) => ['expiring', 'expired', 'error', 'needs_mapping'].includes(item.status)))
const note = (connection) =>
  ({ expiring: `consent ends in ${connection.days_left} ${connection.days_left === 1 ? 'day' : 'days'}`, expired: 'consent ended', error: 'last import failed', needs_mapping: 'accounts not set up' })[connection.status]

watch(
  () => props.version,
  async (_, __, onCleanup) => {
    let stale = false
    onCleanup(() => (stale = true))
    const json = await get('bank-connections', undefined, { quiet: true }).catch(() => null)
    if (!stale) connections.value = json?.data ?? []
  },
  { immediate: true },
)
</script>

<style>
.notice-stack {
  display: flex;
  flex-direction: column;
  gap: 12px;
  flex: 1;
}
.notice-stack .card + .card {
  margin-top: 0;
}
.notice-line {
  display: block;
  height: 13px;
  width: min(260px, 90%);
}
.notice-line.short {
  width: min(180px, 60%);
  margin-top: 7px;
}
</style>
