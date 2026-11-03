<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, router } from '@inertiajs/vue3'

defineProps({
  integrations: { type: Array, required: true },
  drivers: { type: Array, required: true },
})

function toggle (integration) {
  router.put(`/admin/support/integrations/${integration.id}/enabled`, { enabled: !integration.enabled }, { preserveScroll: true })
}

function formatDate (value) {
  return value ? new Date(value).toLocaleString() : '-'
}
</script>
<template>
  <Head>
    <title>{{ $t('tickets.integrations.title') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('tickets.integrations.title') }}</va-card-title>
    <va-card-content>
      <p class="va-p mb-3">{{ $t('tickets.integrations.intro') }}</p>

      <div class="mb-4">
        <strong class="mr-2">{{ $t('tickets.integrations.add') }}</strong>
        <Link v-for="driver in drivers" :key="driver.key" :href="`/admin/support/integrations/create?driver=${driver.key}`" class="mr-2">
          <va-button size="small" color="secondary">{{ driver.label }}</va-button>
        </Link>
      </div>

      <va-scroll-container horizontal>
        <table v-if="integrations.length" class="va-table va-table--hoverable">
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
              <td>{{ formatDate(integration.last_success_at) }}</td>
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
        <p v-else>{{ $t('tickets.integrations.none') }}</p>
      </va-scroll-container>
    </va-card-content>
  </va-card>
</template>
<script>
export default {
  layout: (h, page) => h(AppLayout, [page]),
}
</script>
<style lang="scss">
  .integration-reason {
    color: var(--va-danger);
    font-size: 0.85em;
    max-width: 28em;
  }
</style>
