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
    <title>{{ $t('tickets.admin.title') }} - Control Panel</title>
  </Head>
  <TicketHeader :title="$t('tickets.admin.title')">
    <template #actions>
      <TicketPreferences ref="preferencesModal" :preferences="preferences" hide-button />
      <va-dropdown placement="bottom-end" :offset="[4, 0]">
        <template #anchor>
          <va-button preset="secondary" border-color="primary" icon-right="expand_more">{{ $t('tickets.admin.manage') }}</va-button>
        </template>
        <va-dropdown-content class="t-menu">
          <Link href="/admin/support/reports" class="t-menu__item">{{ $t('tickets.reports.title') }}</Link>
          <Link href="/admin/support/canned" class="t-menu__item">{{ $t('tickets.canned.title') }}</Link>
          <Link href="/admin/support/settings" class="t-menu__item">{{ $t('tickets.settings.title') }}</Link>
          <Link href="/admin/support/integrations" class="t-menu__item">{{ $t('tickets.integrations.title') }}</Link>
          <button type="button" class="t-menu__item" @click="showCategories = true">{{ $t('tickets.admin.categories') }}</button>
          <button type="button" class="t-menu__item" @click="$refs.preferencesModal.show()">{{ $t('tickets.preferences.title') }}</button>
        </va-dropdown-content>
      </va-dropdown>
    </template>
  </TicketHeader>

  <!-- Quick views. "Mine" is where staff land; Unassigned carries a count so new tickets are not missed -->
  <div class="t-views">
    <va-button size="small" :preset="view === 'mine' ? undefined : 'secondary'" border-color="primary" @click="setView('mine')">
      {{ $t('tickets.admin.mine') }} ({{ counts.mine_open }})
    </va-button>
    <va-button size="small" :preset="view === 'unassigned' ? undefined : 'secondary'" border-color="primary" @click="setView('unassigned')">
      {{ $t('tickets.admin.unassigned') }} ({{ counts.unassigned_open }})
    </va-button>
    <va-button size="small" :preset="view === 'all' ? undefined : 'secondary'" border-color="primary" @click="setView('all')">
      {{ $t('tickets.admin.allOpen') }} ({{ counts.open }})
    </va-button>
    <va-button size="small" :preset="filters.sla === 'breached' ? undefined : 'secondary'" border-color="danger" color="danger" @click="setSla('breached')">
      {{ $t('tickets.sla.breachedView') }} ({{ counts.sla_breached }})
    </va-button>
    <va-button size="small" :preset="filters.sla === 'at_risk' ? undefined : 'secondary'" border-color="warning" color="warning" @click="setSla('at_risk')">
      {{ $t('tickets.sla.atRiskView') }}
    </va-button>
  </div>

  <va-card class="mb-4">
    <va-card-content>
      <TicketFilterBar :active="activeFilters">
        <va-select v-model="assigned" :label="$t('tickets.assignedTo')" :options="assignedOptions" value-by="value" text-by="text" @update:model-value="apply" />
        <va-select v-model="status" :label="$t('tickets.statusTab')" :options="statusOptions" value-by="value" text-by="text" @update:model-value="apply" />
        <va-select v-model="priority" :label="$t('tickets.priorityLabel')" :options="priorities" value-by="value" text-by="text" clearable @update:model-value="apply" />
        <va-select v-model="category" :label="$t('tickets.category')" :options="categories" value-by="value" text-by="text" clearable @update:model-value="apply" />
        <va-select
          v-model="app"
          :label="$t('tickets.relatedApp')"
          :options="appOptions"
          value-by="value"
          text-by="text"
          searchable
          @update:model-value="apply"
        />
        <template #search>
          <va-input v-model="q" :label="$t('common.search')" clearable @keyup.enter="apply" @clear="apply" />
        </template>
      </TicketFilterBar>

      <TicketTable v-if="tickets.data.length" class="mt-4" :tickets="tickets.data" :me="me" staff @assign="assignToMe" />

      <div v-else class="text-center py-6" id="emptyState">
        <p v-if="filters.assigned === 'me' && counts.open > 0">{{ $t('tickets.admin.emptyMine', { unassigned: counts.unassigned_open, open: counts.open }) }}</p>
        <p v-else>{{ $t('tickets.empty') }}</p>
        <va-button v-if="filters.assigned === 'me' && counts.open > 0" class="mt-2" @click="setView('all')">{{ $t('tickets.viewAll') }}</va-button>
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

  <va-modal v-model="showCategories" hide-default-actions no-padding size="small">
    <template #content>
      <va-card-title>{{ $t('tickets.admin.categories') }}</va-card-title>
      <va-card-content>
        <ul class="mb-3">
          <li v-for="item in categories" :key="item.value" class="t-row-between">
            <span>{{ item.text }}</span>
            <va-button preset="plain" color="danger" size="small" @click="archiveCategory(item)">{{ $t('tickets.admin.archive') }}</va-button>
          </li>
          <li v-if="!categories.length" class="t-muted">{{ $t('tickets.admin.noCategories') }}</li>
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
    apps: Array,
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
      app: this.filters.app === null || this.filters.app === undefined ? 'all' : (this.filters.app === 'none' ? 'none' : Number(this.filters.app)),
      q: this.filters.q,
      showCategories: false,
      categoryForm: useForm({ name: '' }),
    }
  },
  computed: {
    // Filters beyond the quick view the list opened on
    activeFilters () {
      return (this.app !== 'all' ? 1 : 0) + (this.priority ? 1 : 0) + (this.category ? 1 : 0) + (this.status !== 'open' ? 1 : 0) + (!['me', 'all', 'unassigned'].includes(this.assigned) ? 1 : 0)
    },
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
    appOptions () {
      return [
        { value: 'all', text: this.$t('tickets.list.allApps') },
        { value: 'none', text: this.$t('tickets.list.noApp') },
        ...this.apps,
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
        sla: this.filters.sla || undefined,
        priority: this.priority || undefined,
        category: this.category || undefined,
        app: this.app === 'all' ? undefined : this.app,
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
    setSla (kind) {
      this.status = 'open'
      this.assigned = 'all'
      router.get('/admin/support/tickets', { assigned: 'all', status: 'open', sla: this.filters.sla === kind ? undefined : kind }, { preserveState: true, replace: true })
    },
    setView (view) {
      this.status = 'open'
      this.assigned = { mine: 'me', unassigned: 'unassigned', all: 'all' }[view]
      router.get('/admin/support/tickets', { assigned: this.assigned, status: this.status }, { preserveState: true, replace: true })
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
  },
}
</script>
