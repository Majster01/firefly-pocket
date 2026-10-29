<template>
  <van-field value="" :label="label" readonly class="app-field-link van-cell-fake" v-bind="binding">
    <template #left-icon="state">
      <slot>
        <app-icon v-if="props.icon" :icon="props.icon" :size="props.size" />
      </slot>
    </template>
    <template v-if="props.badge" #button>
      <van-badge :content="props.badge" class="app-count-badge" />
    </template>
  </van-field>
</template>

<script setup>
const props = defineProps({
  label: {},
  icon: {},
  size: {
    default: '20',
  },
  isLink: {
    default: true,
  },
  badge: {},
})

const binding = computed(() => ({
  'is-link': props.isLink,
}))
</script>

