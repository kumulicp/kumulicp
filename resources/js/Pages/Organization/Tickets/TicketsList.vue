<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
import TicketHeader from '@/components/tickets/TicketHeader.vue'
import TicketFilterBar from '@/components/tickets/TicketFilterBar.vue'
import TicketTable from '@/components/tickets/TicketTable.vue'
import TicketPreferences from '@/components/tickets/TicketPreferences.vue'
</script>
<template>
  <Head>
    <title>{{ $t('tickets.title') }} - Control Panel</title>
  </Head>
  <TicketHeader :title="$t('tickets.title')">
    <template #actions>
      <TicketPreferences :preferences="preferences" />
      <Link href="/support/tickets/create">
        <va-button id="newTicket" icon="add">{{ $t('tickets.newTicket') }}</va-button>
      </Link>
    </template>
  </TicketHeader>

  <va-card class="mb-4">
    <va-card-content>
      <TicketFilterBar :active="activeFilters">
        <va-select
          v-model="assigned"
          :label="$t('tickets.assignedTo')"
          :options="assignedOptions"
          value-by="value"
          text-by="text"
          @update:model-value="apply"
        />
        <va-select
          v-model="status"
          :label="$t('tickets.statusTab')"
          :options="statusOptions"
          value-by="value"
          text-by="text"
          @update:model-value="apply"
        />
        <template #search>
          <va-input
            v-model="q"
            :label="$t('common.search')"
            clearable
            @keyup.enter="apply"
            @clear="apply"
          />
        </template>
      </TicketFilterBar>

      <TicketTable v-if="tickets.data.length" class="mt-4" :tickets="tickets.data" />

      <!-- Nothing here is not the same as nothing at all, so say what else exists -->
      <div v-else class="text-center py-6" id="emptyState">
        <p v-if="filters.assigned === 'me' && counts.organization_open > 0">
          {{ $t('tickets.emptyMine', { mine: counts.mine_open, open: counts.organization_open }) }}
        </p>
        <p v-else>{{ $t('tickets.empty') }}</p>
        <va-button
          v-if="filters.assigned === 'me' && counts.organization_open > 0"
          class="mt-2"
          @click="assigned = 'all'; apply()"
        >{{ $t('tickets.viewAll') }}</va-button>
      </div>

      <va-pagination
        v-if="tickets.last_page > 1"
        class="mt-4 justify-center"
        :model-value="tickets.current_page"
        :pages="tickets.last_page"
        direction-links
        @update:model-value="goToPage"
      />
    </va-card-content>
  </va-card>
</template>
<script>
import { router } from '@inertiajs/vue3'
import { createPoller } from '@/composables/ticketPoller'

export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    tickets: Object,
    filters: Object,
    counts: Object,
    members: Array,
    me: Number,
    version: String,
    preferences: Object,
  },
  data () {
    return {
      assigned: this.filters.assigned,
      status: this.filters.status,
      q: this.filters.q,
    }
  },
  computed: {
    // Filters that differ from where the list opens (my open tickets)
    activeFilters () {
      return (this.assigned !== 'me' ? 1 : 0) + (this.status !== 'open' ? 1 : 0)
    },
    assignedOptions () {
      return [
        { value: 'me', text: this.$t('tickets.assignedToMe') },
        { value: 'all', text: this.$t('tickets.allTickets') },
        { value: 'unassigned', text: this.$t('tickets.noOneAssigned') },
        ...this.members.filter(member => member.id !== this.me).map(member => ({ value: `user:${member.id}`, text: member.name })),
      ]
    },
    statusOptions () {
      return ['open', 'waiting_on_us', 'waiting_on_you', 'resolved', 'closed', 'all']
        .map(value => ({ value, text: this.$t(`tickets.tabs.${value}`) }))
    },
  },
  watch: {
    version (value) {
      this.poller?.setVersion(value)
    },
  },
  mounted () {
    // Refresh the list when anything in the organization's tickets changes
    this.poller = createPoller({
      url: '/support/tickets/summary',
      version: this.version,
      params: () => ({ scope: 'org' }),
      alwaysNotify: true,
      interval: 30000,
      hiddenInterval: 120000,
      onChange: (data) => {
        if (data.changed) {
          router.reload({ only: ['tickets', 'counts', 'version'], preserveScroll: true, preserveState: true })
        }
      },
    })
    this.poller.start()
  },
  beforeUnmount () {
    this.poller?.stop()
  },
  methods: {
    apply () {
      router.get('/support/tickets', { assigned: this.assigned, status: this.status, q: this.q || undefined }, { preserveState: true, replace: true })
    },
    goToPage (page) {
      router.get('/support/tickets', { assigned: this.assigned, status: this.status, q: this.q || undefined, page }, { preserveState: true })
    },
  },
}
</script>
