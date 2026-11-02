<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
import TicketBadge from '@/components/tickets/TicketBadge.vue'
import TicketPreferences from '@/components/tickets/TicketPreferences.vue'
</script>
<template>
  <Head>
    <title>{{ $t('tickets.admin.title') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>
      <div class="flex justify-between items-center w-full">
        <span>{{ $t('tickets.admin.title') }}</span>
        <span>
          <TicketPreferences :preferences="preferences" class="mr-2" />
          <Link href="/admin/support/integrations"><va-button size="small" color="secondary" class="mr-2">{{ $t('tickets.integrations.title') }}</va-button></Link>
          <va-button size="small" color="secondary" @click="showCategories = true">{{ $t('tickets.admin.categories') }}</va-button>
        </span>
      </div>
    </va-card-title>
    <va-card-content>
      <!-- Quick views. "Mine" is where staff land; Unassigned carries a count so new tickets are not missed -->
      <div class="mb-3">
        <va-button-group>
          <va-button :preset="view === 'mine' ? undefined : 'secondary'" @click="setView('mine')">
            {{ $t('tickets.admin.mine') }} ({{ counts.mine_open }})
          </va-button>
          <va-button :preset="view === 'unassigned' ? undefined : 'secondary'" @click="setView('unassigned')">
            {{ $t('tickets.admin.unassigned') }} ({{ counts.unassigned_open }})
          </va-button>
          <va-button :preset="view === 'all' ? undefined : 'secondary'" @click="setView('all')">
            {{ $t('tickets.admin.allOpen') }} ({{ counts.open }})
          </va-button>
        </va-button-group>
      </div>

      <div class="row mb-3">
        <div class="flex xs12 md3 pr-2">
          <va-select v-model="assigned" :label="$t('tickets.assignedTo')" :options="assignedOptions" value-by="value" text-by="text" @update:model-value="apply" />
        </div>
        <div class="flex xs12 md3 pr-2">
          <va-select v-model="status" :label="$t('tickets.statusTab')" :options="statusOptions" value-by="value" text-by="text" @update:model-value="apply" />
        </div>
        <div class="flex xs12 md2 pr-2">
          <va-select v-model="priority" :label="$t('tickets.priorityLabel')" :options="priorities" value-by="value" text-by="text" clearable @update:model-value="apply" />
        </div>
        <div class="flex xs12 md2 pr-2">
          <va-select v-model="category" :label="$t('tickets.category')" :options="categories" value-by="value" text-by="text" clearable @update:model-value="apply" />
        </div>
        <div class="flex xs12 md2">
          <va-input v-model="q" :label="$t('common.search')" clearable @keyup.enter="apply" @clear="apply" />
        </div>
      </div>

      <va-scroll-container horizontal>
        <table v-if="tickets.data.length" class="va-table va-table--hoverable mt-3">
          <thead>
            <tr>
              <th>{{ $t('tickets.reference') }}</th>
              <th>{{ $t('tickets.subject') }}</th>
              <th>{{ $t('tickets.organization') }}</th>
              <th>{{ $t('tickets.statusColumn') }}</th>
              <th>{{ $t('tickets.priorityLabel') }}</th>
              <th>{{ $t('tickets.supportAssignee') }}</th>
              <th>{{ $t('tickets.customerAssignee') }}</th>
              <th>{{ $t('tickets.lastActivity') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="ticket in tickets.data" :key="ticket.number" :class="{ 'ticket-row--unread': ticket.unread }">
              <td><span v-if="ticket.unread" class="ticket-unread-dot" :title="$t('tickets.unread')"></span><Link :href="ticket.url">{{ ticket.reference }}</Link></td>
              <td><Link :href="ticket.url">{{ ticket.subject }}</Link></td>
              <td>{{ ticket.organization?.name }}</td>
              <td><TicketBadge :value="ticket.status.value" :label="ticket.status.label" /></td>
              <td><TicketBadge :value="ticket.priority.value" :label="ticket.priority.label" /></td>
              <td>
                {{ ticket.support_assignee?.name || $t('tickets.unassigned') }}
                <va-button
                  v-if="ticket.support_assignee?.id !== me"
                  preset="plain"
                  size="small"
                  :title="$t('tickets.admin.assignToMe')"
                  @click="assignToMe(ticket)"
                >{{ $t('tickets.admin.assignToMe') }}</va-button>
              </td>
              <td>{{ ticket.customer_assignee?.name || '-' }}</td>
              <td>{{ formatDate(ticket.last_activity_at) }}</td>
            </tr>
          </tbody>
        </table>
      </va-scroll-container>

      <div v-if="!tickets.data.length" class="text-center py-6" id="emptyState">
        <p v-if="filters.assigned === 'me' && counts.open > 0">{{ $t('tickets.admin.emptyMine', { unassigned: counts.unassigned_open, open: counts.open }) }}</p>
        <p v-else>{{ $t('tickets.empty') }}</p>
        <va-button v-if="filters.assigned === 'me' && counts.open > 0" class="mt-2" @click="setView('all')">{{ $t('tickets.viewAll') }}</va-button>
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

  <va-modal v-model="showCategories" hide-default-actions no-padding>
    <template #content>
      <va-card-title>{{ $t('tickets.admin.categories') }}</va-card-title>
      <va-card-content>
        <ul class="mb-3">
          <li v-for="item in categories" :key="item.value" class="flex justify-between items-center mb-1">
            <span>{{ item.text }}</span>
            <va-button preset="plain" color="danger" size="small" @click="archiveCategory(item)">{{ $t('tickets.admin.archive') }}</va-button>
          </li>
          <li v-if="!categories.length">{{ $t('tickets.admin.noCategories') }}</li>
        </ul>
        <form @submit.prevent="addCategory">
          <va-input v-model="categoryForm.name" :label="$t('tickets.admin.newCategory')" :error="!!categoryForm.errors.name" :error-messages="categoryForm.errors.name" maxlength="100" />
          <va-button type="submit" class="mt-2" :disabled="categoryForm.processing">{{ $t('common.add') }}</va-button>
        </form>
      </va-card-content>
      <va-card-actions align="right">
        <va-button color="textInverted" @click="showCategories = false">{{ $t('common.close') }}</va-button>
      </va-card-actions>
    </template>
  </va-modal>
</template>
<script>
import { router, useForm } from '@inertiajs/vue3'
import { createPoller } from '@/composables/ticketPoller'

export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    tickets: Object,
    filters: Object,
    counts: Object,
    staff: Array,
    categories: Array,
    priorities: Array,
    me: Number,
    version: String,
    preferences: Object,
  },
  data () {
    return {
      assigned: this.filters.assigned,
      status: this.filters.status,
      priority: this.filters.priority,
      category: this.filters.category,
      q: this.filters.q,
      showCategories: false,
      categoryForm: useForm({ name: '' }),
    }
  },
  computed: {
    view () {
      if (this.filters.assigned === 'me' && this.filters.status === 'open') return 'mine'
      if (this.filters.assigned === 'unassigned' && this.filters.status === 'open') return 'unassigned'
      if (this.filters.assigned === 'all' && this.filters.status === 'open') return 'all'
      return null
    },
    assignedOptions () {
      return [
        { value: 'me', text: this.$t('tickets.assignedToMe') },
        { value: 'all', text: this.$t('tickets.allTickets') },
        { value: 'unassigned', text: this.$t('tickets.noOneAssigned') },
        ...this.staff.filter(member => member.id !== this.me).map(member => ({ value: `user:${member.id}`, text: member.name })),
      ]
    },
    statusOptions () {
      return ['open', 'waiting_on_support', 'waiting_on_customer', 'on_hold', 'resolved', 'closed', 'all']
        .map(value => ({ value, text: this.$t(`tickets.admin.tabs.${value}`) }))
    },
  },
  watch: {
    version (value) {
      this.poller?.setVersion(value)
    },
  },
  mounted () {
    // Refresh the inbox when any ticket changes
    this.poller = createPoller({
      url: '/support/tickets/summary',
      version: this.version,
      params: () => ({ scope: 'all' }),
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
    query (extra = {}) {
      return {
        assigned: this.assigned,
        status: this.status,
        priority: this.priority || undefined,
        category: this.category || undefined,
        q: this.q || undefined,
        ...extra,
      }
    },
    apply () {
      router.get('/admin/support/tickets', this.query(), { preserveState: true, replace: true })
    },
    goToPage (page) {
      router.get('/admin/support/tickets', this.query({ page }), { preserveState: true })
    },
    setView (view) {
      this.status = 'open'
      this.assigned = { mine: 'me', unassigned: 'unassigned', all: 'all' }[view]
      this.apply()
    },
    assignToMe (ticket) {
      router.post(ticket.url, { support_assignee_id: this.me, lock_version: ticket.lock_version }, { preserveScroll: true })
    },
    addCategory () {
      this.categoryForm.post('/admin/support/categories', {
        preserveScroll: true,
        onSuccess: () => this.categoryForm.reset(),
      })
    },
    archiveCategory (item) {
      router.delete(`/admin/support/categories/${item.value}`, { preserveScroll: true })
    },
    formatDate (value) {
      return value ? new Date(value).toLocaleString() : ''
    },
  },
}
</script>
<style lang="scss">
  .ticket-row--unread {
    font-weight: 600;
  }

  .ticket-unread-dot {
    display: inline-block;
    width: 0.6em;
    height: 0.6em;
    margin-right: 0.4em;
    border-radius: 50%;
    background: var(--va-primary);
  }
</style>
