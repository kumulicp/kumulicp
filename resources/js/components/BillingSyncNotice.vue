<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

// The plan changes right away, but the billing provider (and so the invoices shown here) only
// catches up when the queued billing update has run. Say so, keep checking while it runs, and
// refresh the page once it is done.
const POLL_MS = 3000
const MAX_POLLS = 60

const page = usePage()
const sync = computed(() => page.props.billing_sync as { pending: boolean, failed: boolean, error: string | null } | null)
const pending = computed(() => !!sync.value?.pending)

let timer: ReturnType<typeof setInterval> | null = null
let polls = 0

function stopPolling () {
  if (timer) {
    clearInterval(timer)
    timer = null
  }
}

function startPolling () {
  if (timer) return
  polls = 0
  timer = setInterval(() => {
    // Only the sync state: the pages' billing data comes from the provider and is slow to load
    if (++polls > MAX_POLLS) return stopPolling()
    router.reload({ only: ['billing_sync'], preserveScroll: true, preserveState: true })
  }, POLL_MS)
}

watch(pending, (isPending, wasPending) => {
  if (isPending) {
    startPolling()
  } else {
    stopPolling()
    // Finished while we were watching: the invoices and prices may have changed
    if (wasPending) router.reload({ preserveScroll: true, preserveState: true })
  }
})

onMounted(() => pending.value && startPolling())
onBeforeUnmount(stopPolling)
</script>

<template>
  <va-alert v-if="pending" id="billingSyncPending" color="info" icon="fa-spinner" class="mb-4">
    {{ $t('organization.subscription.billingSync.updating') }}
  </va-alert>
  <va-alert v-else-if="sync?.failed" id="billingSyncFailed" color="danger" icon="fa-circle-exclamation" class="mb-4">
    {{ $t('organization.subscription.billingSync.failed') }}
    <div v-if="sync.error" class="mt-1 va-text-secondary">{{ sync.error }}</div>
  </va-alert>
</template>
