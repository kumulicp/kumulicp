<script setup lang="ts">
import { computed } from 'vue'

// An active app in maintenance mode shows a page of its own instead of the app
const props = defineProps<{ status: string, maintenance?: boolean }>()

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

const inMaintenance = computed(() => props.maintenance === true && props.status === 'active')
const name = computed(() => inMaintenance.value ? 'fa-screwdriver-wrench' : 'fa-' + (icons[props.status] ?? 'circle-question'))
const color = computed(() => inMaintenance.value ? '#ff9800' : (colors[props.status] ?? 'primary'))
</script>

<template>
  <va-icon
    :name="name"
    :color="color"
    :spin="status === 'updating'"
    :title="inMaintenance ? $t('organization.apps.maintenanceMode') : status"
  />
</template>
