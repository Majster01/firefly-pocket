<template>
  <div class="app-form">
    <app-top-toolbar />

    <van-cell-group inset>
      <div class="van-cell-group-title">{{ $t('settings.notifications.title') }}:</div>
      <app-boolean v-if="canToggle" :model-value="state === 'on'" :label="$t('settings.notifications.enable')" @update:model-value="onToggle" />
      <div v-if="state !== 'loading'" class="text-muted text-size-12 p-20 pt-10">{{ $t(`settings.notifications.state_${state}`) }}</div>
    </van-cell-group>
  </div>
</template>

<script setup>
import { useToolbar } from '~/composables/useToolbar'
import { usePushNotifications } from '~/composables/usePushNotifications'

const { state, isBusy, refresh, enable, disable } = usePushNotifications()
const canToggle = computed(() => ['off', 'on'].includes(state.value))

const onToggle = (value) => !isBusy.value && (value ? enable() : disable())

const { t } = useI18n()
useToolbar().init({ title: t('settings.notifications.title') })

onMounted(refresh)
</script>
