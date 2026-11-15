<template>
  <span v-if="!change" class="change-summary">{{ $t('admin.updates.found') }}</span>
  <span v-else class="change-summary">
    {{ change.by
      ? $t('admin.updates.editedBy', { subject: change.subject, user: change.by, fields })
      : $t('admin.updates.edited', { subject: change.subject, fields }) }}
  </span>
</template>

<script lang="ts">
// Says which edit put something out of date, or that none was seen
export default {
  props: {
    change: {
      type: Object,
      default: null
    }
  },
  computed: {
    fields () {
      return (this.change.changed || []).join(', ')
    }
  }
}
</script>
