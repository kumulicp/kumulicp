<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
import TicketBadge from '@/components/tickets/TicketBadge.vue'
</script>
<template>
  <Head>
    <title>{{ $t('tickets.title') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>
      <div class="flex justify-between items-center w-full">
        <span>{{ $t('tickets.title') }}</span>
        <Link href="/support/tickets/create">
          <va-button id="newTicket">{{ $t('tickets.newTicket') }}</va-button>
        </Link>
      </div>
    </va-card-title>
    <va-card-content>
      <div class="row mb-3">
        <div class="flex xs12 md4 pr-2">
          <va-select
            v-model="assigned"
            :label="$t('tickets.assignedTo')"
            :options="assignedOptions"
            value-by="value"
            text-by="text"
            @update:model-value="apply"
          />
        </div>
        <div class="flex xs12 md4 pr-2">
          <va-select
            v-model="status"
            :label="$t('tickets.statusTab')"
            :options="statusOptions"
            value-by="value"
            text-by="text"
            @update:model-value="apply"
          />
        </div>
        <div class="flex xs12 md4">
          <va-input
            v-model="q"
            :label="$t('common.search')"
            @keyup.enter="apply"
            @clear="apply"
            clearable
          />
        </div>
      </div>

      <va-scroll-container horizontal>
        <table class="va-table va-table--hoverable mt-3" v-if="tickets.data.length">
          <thead>
            <tr>
              <th>{{ $t('tickets.reference') }}</th>
              <th>{{ $t('tickets.subject') }}</th>
              <th>{{ $t('tickets.statusColumn') }}</th>
              <th>{{ $t('tickets.customerAssignee') }}</th>
              <th>{{ $t('tickets.supportAssignee') }}</th>
              <th>{{ $t('tickets.lastActivity') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="ticket in tickets.data" :key="ticket.number">
              <td><Link :href="ticket.url">{{ ticket.reference }}</Link></td>
              <td><Link :href="ticket.url">{{ ticket.subject }}</Link></td>
              <td><TicketBadge :value="ticket.status.value" :label="ticket.status.label" /></td>
              <td>{{ ticket.customer_assignee?.name || '-' }}</td>
              <td>{{ ticket.support_assignee?.name || $t('tickets.unassigned') }}</td>
              <td>{{ formatDate(ticket.last_activity_at) }}</td>
            </tr>
          </tbody>
        </table>
      </va-scroll-container>

      <!-- Nothing here is not the same as nothing at all, so say what else exists -->
      <div v-if="!tickets.data.length" class="text-center py-6" id="emptyState">
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
        class="mt-3 mb-3 justify-center"
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

export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    tickets: Object,
    filters: Object,
    counts: Object,
    members: Array,
    me: Number,
  },
  data () {
    return {
      assigned: this.filters.assigned,
      status: this.filters.status,
      q: this.filters.q,
    }
  },
  computed: {
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
  methods: {
    apply () {
      router.get('/support/tickets', { assigned: this.assigned, status: this.status, q: this.q || undefined }, { preserveState: true, replace: true })
    },
    goToPage (page) {
      router.get('/support/tickets', { assigned: this.assigned, status: this.status, q: this.q || undefined, page }, { preserveState: true })
    },
    formatDate (value) {
      return value ? new Date(value).toLocaleString() : ''
    },
  },
}
</script>
