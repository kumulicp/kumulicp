<script setup lang="ts">
import { ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

const page = usePage()
const storage_key = 'recoveryReminderDismissed'

function wasDismissed (): boolean {
  try {
    return sessionStorage.getItem(storage_key) === '1'
  } catch {
    return false
  }
}

// Dismissed for the rest of the session only: it comes back at the next sign in until a recovery email is added
const open = ref(!wasDismissed())

watch(open, (value) => {
  if (!value) {
    try {
      sessionStorage.setItem(storage_key, '1')
    } catch {
      // Storage isn't available: it just stays dismissed until the page is reloaded
    }
  }
})
</script>
<template>
  <va-alert
    v-if="page.props.recovery_reminder"
    v-model="open"
    id="recoveryReminder"
    color="warning"
    border="top"
    class="mb-3"
    closeable
  >
    <template #icon>
      <va-icon name="info" />
    </template>
    {{ $t('recoveryEmail.reminder') }}
    <Link href="/profile" class="ml-2">{{ $t('recoveryEmail.reminderAction') }}</Link>
  </va-alert>
</template>
