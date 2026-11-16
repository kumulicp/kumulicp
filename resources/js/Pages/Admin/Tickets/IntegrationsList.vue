<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import TicketHeader from '@/components/tickets/TicketHeader.vue'
import { formatDate as format } from '@/composables/ticketFormat'

defineProps({
  integrations: { type: Array, required: true },
  drivers: { type: Array, required: true },
})

function toggle (integration) {
  router.put(`/admin/support/integrations/${integration.id}/enabled`, { enabled: !integration.enabled }, { preserveScroll: true })
}

function formatDate (value) {
  return format(value) || '-'
}
</script>
<template>
  <Head>
    <title>{{ $t('tickets.integrations.title') }} - Control Panel</title>
  </Head>
  <TicketHeader :title="$t('tickets.integrations.title')">
    <template #actions>
      <Link href="/admin/support/tickets"><va-button preset="secondary" border-color="primary" icon="arrow_back">{{ $t('tickets.admin.title') }}</va-button></Link>
    </template>
  </TicketHeader>

  <div class="t-col">
    <va-card>
      <va-card-content>
        <p class="t-muted mb-3">{{ $t('tickets.integrations.intro') }}</p>
        <div class="va-input-label mb-2">{{ $t('tickets.integrations.add') }}</div>
        <div class="t-actions">
          <Link v-for="driver in drivers" :key="driver.key" :href="`/admin/support/integrations/create?driver=${driver.key}`">
            <va-button preset="secondary" border-color="primary" icon="add">{{ driver.label }}</va-button>
          </Link>
        </div>
      </va-card-content>
    </va-card>

    <va-card>
      <va-card-content>
        <p v-if="!integrations.length" class="text-center py-4">{{ $t('tickets.integrations.none') }}</p>
        <div v-else class="t-list">
          <table class="va-table va-table--hoverable t-table t-list__table">
            <thead>
              <tr>
                <th>{{ $t('tickets.integrations.name') }}</th>
                <th>{{ $t('tickets.integrations.type') }}</th>
                <th>{{ $t('tickets.integrations.state') }}</th>
                <th>{{ $t('tickets.integrations.lastSuccess') }}</th>
                <th>{{ $t('tickets.integrations.queue') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="integration in integrations" :key="integration.id">
                <td><Link :href="`/admin/support/integrations/${integration.id}/edit`">{{ integration.name }}</Link></td>
                <td>
                  {{ integration.driver }}
                  <va-chip v-if="!integration.installed" size="small" color="danger">{{ $t('tickets.integrations.notInstalled') }}</va-chip>
                </td>
                <td>
                  <va-chip size="small" :color="integration.enabled ? 'success' : 'secondary'">
                    {{ integration.enabled ? $t('tickets.integrations.on') : $t('tickets.integrations.off') }}
                  </va-chip>
                  <div v-if="integration.disabled_reason" class="integration-reason">{{ integration.disabled_reason }}</div>
                  <div v-else-if="integration.failures > 0" class="integration-reason">{{ $t('tickets.integrations.failuresInARow', { count: integration.failures }) }}</div>
                </td>
                <td class="t-muted">{{ formatDate(integration.last_success_at) }}</td>
                <td>
                  <Link :href="`/admin/support/integrations/${integration.id}/deliveries`">
                    {{ $t('tickets.integrations.queueSummary', { pending: integration.pending, failed: integration.failed }) }}
                  </Link>
                </td>
                <td>
                  <va-button size="small" preset="plain" @click="toggle(integration)">
                    {{ integration.enabled ? $t('tickets.integrations.switchOff') : $t('tickets.integrations.switchOn') }}
                  </va-button>
                </td>
              </tr>
            </tbody>
          </table>

          <ul class="t-list__cards">
            <li v-for="integration in integrations" :key="integration.id" class="t-ticket">
              <div class="t-ticket__top">
                <span class="t-ticket__ref">{{ integration.driver }}</span>
                <va-chip v-if="!integration.installed" size="small" color="danger">{{ $t('tickets.integrations.notInstalled') }}</va-chip>
                <va-chip size="small" :color="integration.enabled ? 'success' : 'secondary'">
                  {{ integration.enabled ? $t('tickets.integrations.on') : $t('tickets.integrations.off') }}
                </va-chip>
              </div>
              <Link :href="`/admin/support/integrations/${integration.id}/edit`" class="t-ticket__link">
                <div class="t-ticket__subject">{{ integration.name }}</div>
              </Link>
              <div v-if="integration.disabled_reason" class="integration-reason">{{ integration.disabled_reason }}</div>
              <div v-else-if="integration.failures > 0" class="integration-reason">{{ $t('tickets.integrations.failuresInARow', { count: integration.failures }) }}</div>
              <div class="t-ticket__meta t-muted">
                <span>{{ $t('tickets.integrations.lastSuccess') }}: {{ formatDate(integration.last_success_at) }}</span>
                <Link :href="`/admin/support/integrations/${integration.id}/deliveries`">
                  {{ $t('tickets.integrations.queueSummary', { pending: integration.pending, failed: integration.failed }) }}
                </Link>
              </div>
              <va-button size="small" preset="plain" class="mt-1" @click="toggle(integration)">
                {{ integration.enabled ? $t('tickets.integrations.switchOff') : $t('tickets.integrations.switchOn') }}
              </va-button>
            </li>
          </ul>
        </div>
      </va-card-content>
    </va-card>
  </div>
</template>
<script>
export default {
  layout: (h, page) => h(AppLayout, [page]),
}
</script>
<style lang="scss">
  .integration-reason {
    color: var(--va-danger);
    font-size: 0.85rem;
    max-width: 28em;
    overflow-wrap: anywhere;
  }
</style>
