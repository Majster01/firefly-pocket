<template>
  <div class="bank-done">
    <van-icon :name="isOk ? 'checked' : 'warning-o'" :color="isOk ? 'var(--van-success-color, #07c160)' : 'var(--van-danger-color, #ee0a24)'" size="64" />
    <h2>{{ isOk ? $t('bank_connection_page.done.ok_title') : $t('bank_connection_page.done.failed_title') }}</h2>
    <p>{{ isOk ? $t('bank_connection_page.done.ok_message') : $t(`bank_connection_page.reauthenticate_failed.${reason}`) }}</p>
    <p class="text-muted">{{ $t('bank_connection_page.done.open_app') }}</p>
  </div>
</template>

<script setup>
// Where the bank can leave the user when it opens Pico in a browser that is not signed in, e.g. not the installed app.
// Nothing here needs a login; the connection itself is safe in the importer and waits in the app.
definePageMeta({ layout: false })

const route = useRoute()
const { te } = useI18n()

const isOk = computed(() => route.query.result === 'ok')
const reason = computed(() => (te(`bank_connection_page.reauthenticate_failed.${route.query.reason}`) ? route.query.reason : 'unknown'))
</script>

<style scoped>
.bank-done {
  padding: 96px 32px;
  text-align: center;
}
</style>
