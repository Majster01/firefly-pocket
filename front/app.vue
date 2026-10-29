<template>
  <van-config-provider :theme="theme">
    <NuxtPwaManifest />
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
    <app-loading />
  </van-config-provider>
</template>

<script setup>
import { useDashboardStore } from '~/stores/dashboardStore'
import RouteConstants from '~/constants/RouteConstants'

import '~/assets/styles/variables.css'
import AppLoading from '~/components/ui-kit/app-loading.vue'

const dashboardStore = useDashboardStore()
const profileStore = useProfileStore()
const appStore = useAppStore()

const theme = computed(() => (profileStore.darkTheme ? 'dark' : 'white'))
const pwaColor = computed(() => (profileStore.darkTheme ? '#1c1c1e' : '#ffffff'))
useHead({
  meta: [{ name: 'theme-color', content: pwaColor }],
})

useResize()

const route = useRoute()

onMounted(async () => {
  if (!appStore.authToken) {
    // The bank may send the user back to a browser that is not signed in (not the installed app): explain, do not ask for a login
    const isBankReturn = route.path.startsWith('/bank-connections') && route.query.result
    navigateTo(isBankReturn ? { path: RouteConstants.ROUTE_BANK_CONNECTION_DONE, query: route.query } : RouteConstants.ROUTE_SETTINGS_SETUP)
    return
  }
  await dashboardStore.init()

  appStore.fetchInfo()
  await profileStore.getProfiles({ showLoading: false })
  await appStore.syncEverythingIfOld()
})
</script>

<style>
.page-enter-active,
.page-leave-active {
  transition: all 0.15s;
}

.page-enter-from,
.page-leave-to {
  opacity: 0;
  filter: blur(0.2rem);
}
</style>
