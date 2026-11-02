<script setup>
import { computed } from 'vue'

// One small chip used for status and priority. `value` picks the colour.
const props = defineProps({
  value: { type: String, required: true },
  label: { type: String, required: true },
})

const colors = {
  open: 'primary',
  pending_support: 'warning',
  pending_customer: 'info',
  on_hold: 'secondary',
  resolved: 'success',
  closed: 'secondary',
  low: 'secondary',
  normal: 'info',
  high: 'warning',
  urgent: 'danger',
}

const color = computed(() => colors[props.value] || 'secondary')
</script>
<template>
  <va-chip size="small" :color="color" class="ticket-badge">{{ label }}</va-chip>
</template>
