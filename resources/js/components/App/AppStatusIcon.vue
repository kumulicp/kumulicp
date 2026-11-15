<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ status: string }>()

const icons: Record<string, string> = {
  active: 'check-circle',
  activating: 'rocket',
  updating: 'spinner',
  unavailable: 'triangle-exclamation',
  deactivating: 'explosion',
  deactivated: 'ban',
  deleting: 'explosion'
}

const colors: Record<string, string> = {
  active: 'success',
  updating: 'warning',
  unavailable: 'warning',
  deactivating: 'danger',
  deactivated: 'danger',
  deleting: 'danger'
}

const name = computed(() => 'fa-' + (icons[props.status] ?? 'circle-question'))
const color = computed(() => colors[props.status] ?? 'primary')
</script>

<template>
  <va-icon
    :name="name"
    :color="color"
    :spin="status === 'updating'"
    :title="status"
  />
</template>
