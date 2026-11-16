<script setup>
// The support icon in the top bar, with the number of tickets that have
// something new for you. Hidden unless tickets are switched on.
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { createPoller } from '@/composables/ticketPoller'

const page = usePage()
const count = ref(0)
const enabled = computed(() => !!page.props.flags?.tickets)
const href = computed(() => (page.props.auth?.can?.admin ? '/admin/support/tickets' : '/support/tickets'))

let poller = null
let stopListening = null

onMounted(() => {
  if (!enabled.value) return

  poller = createPoller({
    url: '/support/tickets/summary',
    alwaysNotify: true,
    immediate: true,
    interval: 30000,
    hiddenInterval: 120000,
    onChange: (data) => { count.value = data.unread },
  })
  poller.start()

  // Reading a ticket changes the count, so look again after each page visit
  stopListening = router.on('success', () => poller?.refresh())
})

onBeforeUnmount(() => {
  poller?.stop()
  if (typeof stopListening === 'function') stopListening()
})
</script>
<template>
  <Link v-if="enabled" :href="href" class="tickets-badge" :title="$t('tickets.title')">
    <va-badge v-if="count > 0" :text="count > 99 ? '99+' : String(count)" color="danger" overlap>
      <va-icon name="fa-life-ring" class="clickable-icon" />
    </va-badge>
    <va-icon v-else name="fa-life-ring" class="clickable-icon" />
  </Link>
</template>
<style lang="scss">
  .tickets-badge {
    display: inline-flex;
    align-items: center;
    margin: 0 0.75rem;
    color: inherit;
  }
</style>
