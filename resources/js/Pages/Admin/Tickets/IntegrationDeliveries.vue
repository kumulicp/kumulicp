<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'
</script>
<template>
  <Head>
    <title>{{ $t('tickets.integrations.deliveries') }} - {{ integration.name }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('tickets.integrations.deliveriesFor', { name: integration.name }) }}</va-card-title>
    <va-card-content>
      <div class="mb-3" style="max-width: 16em">
        <va-select
          v-model="statusFilter"
          :label="$t('tickets.integrations.filterStatus')"
          :options="statusOptions"
          value-by="value"
          text-by="text"
          @update:model-value="apply"
        />
      </div>

      <va-scroll-container horizontal>
        <table v-if="deliveries.data.length" class="va-table va-table--hoverable">
          <thead>
            <tr>
              <th>{{ $t('tickets.integrations.when') }}</th>
              <th>{{ $t('tickets.reference') }}</th>
              <th>{{ $t('tickets.integrations.event') }}</th>
              <th>{{ $t('tickets.statusColumn') }}</th>
              <th>{{ $t('tickets.integrations.attempts') }}</th>
              <th>{{ $t('tickets.integrations.result') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="delivery in deliveries.data" :key="delivery.id">
              <tr>
                <td>{{ formatDate(delivery.created_at) }}</td>
                <td><Link v-if="delivery.ticket" :href="delivery.ticket.url">{{ delivery.ticket.reference }}</Link></td>
                <td>{{ delivery.event }}</td>
                <td><va-chip size="small" :color="colors[delivery.status]">{{ $t(`tickets.integrations.statuses.${delivery.status}`) }}</va-chip></td>
                <td>{{ delivery.attempts }}</td>
                <td class="delivery-result">
                  <span v-if="delivery.response_code">{{ delivery.response_code }}</span>
                  <span v-if="delivery.error" class="delivery-error"> {{ delivery.error }}</span>
                </td>
                <td>
                  <va-button size="small" preset="plain" @click="toggle(delivery)">{{ expanded === delivery.id ? $t('tickets.integrations.hide') : $t('tickets.integrations.payload') }}</va-button>
                  <va-button v-if="delivery.status === 'failed'" size="small" preset="plain" color="warning" @click="redeliver(delivery)">{{ $t('tickets.integrations.redeliver') }}</va-button>
                </td>
              </tr>
              <tr v-if="expanded === delivery.id">
                <td colspan="7">
                  <div class="mb-1"><strong>{{ $t('tickets.integrations.sent') }}</strong></div>
                  <pre class="delivery-payload">{{ payload ?? $t('tickets.integrations.loading') }}</pre>
                  <template v-if="delivery.response">
                    <div class="mb-1 mt-2"><strong>{{ $t('tickets.integrations.answer') }}</strong></div>
                    <pre class="delivery-payload">{{ delivery.response }}</pre>
                  </template>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
        <p v-else>{{ $t('tickets.integrations.noDeliveries') }}</p>
      </va-scroll-container>

      <va-pagination
        v-if="deliveries.last_page > 1"
        class="mt-3 mb-3 justify-center"
        :model-value="deliveries.current_page"
        :pages="deliveries.last_page"
        direction-links
        @update:model-value="goToPage"
      />
    </va-card-content>
  </va-card>
</template>
<script>
import { router } from '@inertiajs/vue3'
import axios from 'axios'

export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    integration: Object,
    deliveries: Object,
    status: String,
  },
  data () {
    return {
      statusFilter: this.status ?? 'all',
      expanded: null,
      payload: null,
      colors: { pending: 'warning', delivered: 'success', failed: 'danger' },
    }
  },
  computed: {
    statusOptions () {
      return ['all', 'pending', 'delivered', 'failed'].map(value => ({ value, text: value === 'all' ? this.$t('tickets.tabs.all') : this.$t(`tickets.integrations.statuses.${value}`) }))
    },
  },
  methods: {
    base () {
      return `/admin/support/integrations/${this.integration.id}/deliveries`
    },
    apply () {
      router.get(this.base(), { status: this.statusFilter === 'all' ? undefined : this.statusFilter }, { preserveState: true, replace: true })
    },
    goToPage (page) {
      router.get(this.base(), { status: this.statusFilter === 'all' ? undefined : this.statusFilter, page }, { preserveState: true })
    },
    async toggle (delivery) {
      if (this.expanded === delivery.id) {
        this.expanded = null
        return
      }
      this.expanded = delivery.id
      this.payload = null
      try {
        const { data } = await axios.get(`${this.base()}/${delivery.id}`)
        this.payload = JSON.stringify(data.payload, null, 2)
      } catch {
        this.payload = this.$t('tickets.integrations.loadFailed')
      }
    },
    redeliver (delivery) {
      router.post(`${this.base()}/${delivery.id}/redeliver`, {}, { preserveScroll: true })
    },
    formatDate (value) {
      return value ? new Date(value).toLocaleString() : ''
    },
  },
}
</script>
<style lang="scss">
  .delivery-error {
    color: var(--va-danger);
  }

  .delivery-result {
    max-width: 28em;
    overflow-wrap: anywhere;
  }

  .delivery-payload {
    max-height: 20em;
    overflow: auto;
    padding: 0.75em;
    background: var(--va-background-secondary);
    border-radius: 4px;
    font-size: 0.85em;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }
</style>
