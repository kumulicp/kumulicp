<script setup>
// The ticket list for both sides. A table when there is room for one, a stack of
// cards when there is not (decided by the width of the list itself, so it also
// behaves beside the sidebar). Staff get organization, priority and deadlines.
import { Link } from '@inertiajs/vue3'
import TicketBadge from './TicketBadge.vue'
import { formatDate } from '@/composables/ticketFormat'

defineProps({
  tickets: { type: Array, required: true },
  staff: { type: Boolean, default: false },
  me: { type: Number, default: null },
})
const emit = defineEmits(['assign'])

// The worst state of the two clocks, if it is worth showing
function slaOf (ticket) {
  const states = ticket.sla ? [ticket.sla.first_response.state, ticket.sla.resolution.state] : []
  if (!ticket.status.open) return null
  if (states.includes('breached')) return 'breached'
  if (states.includes('warning')) return 'warning'
  return null
}
</script>
<template>
  <div class="t-list">
    <table class="va-table va-table--hoverable t-table t-list__table">
      <thead>
        <tr>
          <th>{{ $t('tickets.reference') }}</th>
          <th>{{ $t('tickets.subject') }}</th>
          <th>{{ $t('tickets.statusColumn') }}</th>
          <th v-if="staff">{{ $t('tickets.sla.title') }}</th>
          <th>{{ $t('tickets.list.people') }}</th>
          <th>{{ $t('tickets.lastActivity') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="ticket in tickets" :key="ticket.number" :class="{ 't-row--unread': ticket.unread }">
          <td class="whitespace-nowrap">
            <span v-if="ticket.unread" class="t-unread-dot" :title="$t('tickets.unread')"></span>
            <Link :href="ticket.url">{{ ticket.reference }}</Link>
          </td>
          <td>
            <Link :href="ticket.url">{{ ticket.subject }}</Link>
            <div v-if="staff && ticket.organization" class="t-muted">{{ ticket.organization.name }}</div>
          </td>
          <td>
            <div class="t-table__stack">
              <TicketBadge :value="ticket.status.value" :label="ticket.status.label" />
              <TicketBadge v-if="staff" :value="ticket.priority.value" :label="ticket.priority.label" />
            </div>
          </td>
          <td v-if="staff">
            <va-chip v-if="slaOf(ticket)" size="small" :color="slaOf(ticket) === 'breached' ? 'danger' : 'warning'">{{ $t(`tickets.sla.states.${slaOf(ticket)}`) }}</va-chip>
            <span v-if="ticket.csat" class="t-muted" :title="$t('tickets.rating.title')"> {{ ticket.csat.score }}★</span>
          </td>
          <td>
            <div><span class="t-muted">{{ $t('tickets.list.support') }}:</span> {{ ticket.support_assignee?.name || $t('tickets.unassigned') }}</div>
            <div><span class="t-muted">{{ $t('tickets.list.assignedTo') }}:</span> {{ ticket.customer_assignee?.name || '-' }}</div>
            <va-button
              v-if="staff && ticket.support_assignee?.id !== me"
              preset="plain"
              size="small"
              class="mt-1"
              @click="emit('assign', ticket)"
            >{{ $t('tickets.admin.assignToMe') }}</va-button>
          </td>
          <td class="t-muted whitespace-nowrap">{{ formatDate(ticket.last_activity_at) }}</td>
        </tr>
      </tbody>
    </table>

    <ul class="t-list__cards">
      <li v-for="ticket in tickets" :key="ticket.number" class="t-ticket" :class="{ 't-ticket--unread': ticket.unread }">
        <Link :href="ticket.url" class="t-ticket__link">
          <div class="t-ticket__top">
            <span class="t-ticket__ref">
              <span v-if="ticket.unread" class="t-unread-dot" :title="$t('tickets.unread')"></span>{{ ticket.reference }}
            </span>
            <va-chip v-if="staff && slaOf(ticket)" size="small" :color="slaOf(ticket) === 'breached' ? 'danger' : 'warning'">{{ $t(`tickets.sla.states.${slaOf(ticket)}`) }}</va-chip>
            <TicketBadge v-if="staff" :value="ticket.priority.value" :label="ticket.priority.label" />
            <TicketBadge :value="ticket.status.value" :label="ticket.status.label" />
          </div>
          <div class="t-ticket__subject">{{ ticket.subject }}</div>
        </Link>
        <div class="t-ticket__meta t-muted">
          <span v-if="staff && ticket.organization"><b>{{ ticket.organization.name }}</b></span>
          <span>{{ $t('tickets.list.support') }}: {{ ticket.support_assignee?.name || $t('tickets.unassigned') }}</span>
          <span>{{ $t('tickets.list.assignedTo') }}: {{ ticket.customer_assignee?.name || '-' }}</span>
          <span>{{ formatDate(ticket.last_activity_at) }}</span>
        </div>
        <va-button
          v-if="staff && ticket.support_assignee?.id !== me"
          preset="plain"
          size="small"
          class="mt-1"
          @click="emit('assign', ticket)"
        >{{ $t('tickets.admin.assignToMe') }}</va-button>
      </li>
    </ul>
  </div>
</template>
