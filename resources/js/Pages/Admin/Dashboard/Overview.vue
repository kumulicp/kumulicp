<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.dashboard.dashboard') }} - Control Panel</title>
  </Head>

  <div class="admin-dashboard">
    <va-card class="mb-4">
      <va-card-title>{{ $t('admin.dashboard.warnings') }}</va-card-title>
      <va-card-content>
        <va-alert v-if="warnings.length === 0" color="success" icon="check_circle">
          {{ $t('admin.dashboard.no_warnings') }}
        </va-alert>
        <va-alert
          v-for="(warning, idx) in warnings"
          :key="idx"
          :color="warning.level"
          icon="warning"
          class="mb-2"
        >
          <Link v-if="warning.url" :href="warning.url" class="warning-link">{{ warning.message }}</Link>
          <span v-else>{{ warning.message }}</span>
        </va-alert>
      </va-card-content>
    </va-card>

    <div class="row row-equal mb-1">
      <div class="flex xs12 sm6 md3">
        <va-card class="mb-4">
          <va-card-content>
            <h2 class="va-h2 ma-0">{{ organizations.total }}</h2>
            <p>{{ $t('admin.dashboard.organizations') }}</p>
            <p class="secondary">{{ organizations.active }} {{ $t('admin.dashboard.active') }} &middot; {{ organizations.new_last_30_days }} {{ $t('admin.dashboard.new_last_30_days') }}</p>
          </va-card-content>
        </va-card>
      </div>
      <div class="flex xs12 sm6 md3">
        <va-card class="mb-4">
          <va-card-content>
            <h2 class="va-h2 ma-0">{{ applications.total }}</h2>
            <p>{{ $t('admin.dashboard.apps_catalog') }}</p>
            <p class="secondary">{{ applications.enabled }} {{ $t('admin.dashboard.apps_enabled') }}</p>
          </va-card-content>
        </va-card>
      </div>
      <div class="flex xs12 sm6 md3">
        <va-card class="mb-4">
          <va-card-content>
            <h2 class="va-h2 ma-0" :class="{ 'text-danger': stats.failed_jobs > 0 }">{{ stats.failed_jobs }}</h2>
            <p>{{ $t('admin.dashboard.failed_jobs') }}</p>
          </va-card-content>
        </va-card>
      </div>
      <div class="flex xs12 sm6 md3">
        <va-card class="mb-4">
          <va-card-content>
            <h2 class="va-h2 ma-0" :class="{ 'text-warning': stats.error_logs_last_day > 0 }">{{ stats.error_logs_last_day }}</h2>
            <p>{{ $t('admin.dashboard.error_logs_24h') }}</p>
          </va-card-content>
        </va-card>
      </div>
    </div>

    <div class="row">
      <div class="flex xs12 lg6">
        <va-card class="mb-4">
          <va-card-title>{{ $t('admin.dashboard.plan_usage') }}</va-card-title>
          <va-card-content>
            <VaScrollContainer color="primary" horizontal>
              <table class="va-table va-table--hoverable mt-3">
                <thead>
                  <tr>
                    <th>{{ $t('admin.dashboard.plan') }}</th>
                    <th>{{ $t('admin.dashboard.status') }}</th>
                    <th>{{ $t('admin.dashboard.subscribers') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="plan in plans" :key="plan.id">
                    <td>
                      <Link :href="'/admin/service/plans/'+plan.id">{{ plan.name }}</Link>
                      <span v-if="plan.is_default" class="secondary"> ({{ $t('admin.dashboard.default') }})</span>
                      <span v-if="plan.archive" class="secondary"> ({{ $t('admin.dashboard.archived') }})</span>
                    </td>
                    <td>{{ plan.status }}</td>
                    <td>{{ plan.subscribers }}</td>
                  </tr>
                </tbody>
              </table>
            </VaScrollContainer>
          </va-card-content>
        </va-card>
      </div>
      <div class="flex xs12 lg6">
        <va-card class="mb-4">
          <va-card-title>{{ $t('admin.dashboard.app_usage') }}</va-card-title>
          <va-card-content>
            <VaScrollContainer color="primary" horizontal>
              <table class="va-table va-table--hoverable mt-3">
                <thead>
                  <tr>
                    <th>{{ $t('admin.dashboard.application') }}</th>
                    <th>{{ $t('admin.dashboard.active_instances') }}</th>
                    <th>{{ $t('admin.dashboard.total_instances') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="app in app_usage" :key="app.id">
                    <td><Link :href="'/admin/apps/'+app.id">{{ app.name }}</Link></td>
                    <td>{{ app.active }}</td>
                    <td>{{ app.total }}</td>
                  </tr>
                </tbody>
              </table>
            </VaScrollContainer>
          </va-card-content>
        </va-card>
      </div>
    </div>

    <div class="row">
      <div class="flex xs12 lg6">
        <va-card class="mb-4">
          <va-card-title>{{ $t('admin.dashboard.task_status') }}</va-card-title>
          <va-card-content>
            <table class="va-table va-table--hoverable mt-3">
              <tbody>
                <tr v-for="status in taskStatusList" :key="status.name">
                  <td>{{ status.name }}</td>
                  <td>{{ status.count }}</td>
                </tr>
              </tbody>
            </table>
          </va-card-content>
        </va-card>

        <va-card class="mb-4">
          <va-card-title>{{ $t('admin.dashboard.security_findings') }}</va-card-title>
          <va-card-content>
            <table class="va-table va-table--hoverable mt-3">
              <tbody>
                <tr v-for="finding in securityFindingList" :key="finding.name">
                  <td>{{ finding.name }}</td>
                  <td>{{ finding.count }}</td>
                </tr>
              </tbody>
            </table>
          </va-card-content>
        </va-card>
      </div>

      <div class="flex xs12 lg6">
        <va-card class="mb-4">
          <va-card-title>
            <span>{{ $t('admin.dashboard.recent_failed_tasks') }}</span>
            <Link href="/admin/server/tasks">{{ $t('admin.dashboard.view_all') }}</Link>
          </va-card-title>
          <va-card-content>
            <p v-if="recent_failed_tasks.length === 0" class="secondary">{{ $t('admin.dashboard.no_failed_tasks') }}</p>
            <VaScrollContainer v-else color="primary" horizontal>
              <table class="va-table va-table--hoverable mt-3">
                <thead>
                  <tr>
                    <th>{{ $t('admin.dashboard.organization') }}</th>
                    <th>{{ $t('admin.dashboard.application') }}</th>
                    <th>{{ $t('admin.dashboard.error') }}</th>
                    <th>{{ $t('admin.dashboard.time') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="task in recent_failed_tasks" :key="task.id">
                    <td>{{ task.organization }}</td>
                    <td>{{ task.application }}</td>
                    <td>{{ task.error_message }}</td>
                    <td>{{ task.time }}</td>
                  </tr>
                </tbody>
              </table>
            </VaScrollContainer>
          </va-card-content>
        </va-card>

        <va-card class="mb-4">
          <va-card-title>
            <span>{{ $t('admin.dashboard.recent_failed_scans') }}</span>
            <Link href="/admin/server/security/scans">{{ $t('admin.dashboard.view_all') }}</Link>
          </va-card-title>
          <va-card-content>
            <p v-if="recent_failed_scans.length === 0" class="secondary">{{ $t('admin.dashboard.no_failed_scans') }}</p>
            <VaScrollContainer v-else color="primary" horizontal>
              <table class="va-table va-table--hoverable mt-3">
                <thead>
                  <tr>
                    <th>{{ $t('admin.dashboard.server') }}</th>
                    <th>{{ $t('admin.dashboard.tool') }}</th>
                    <th>{{ $t('admin.dashboard.error') }}</th>
                    <th>{{ $t('admin.dashboard.time') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="scan in recent_failed_scans" :key="scan.id">
                    <td>{{ scan.server }}</td>
                    <td>{{ scan.tool }}</td>
                    <td>{{ scan.error_message }}</td>
                    <td>{{ scan.time }}</td>
                  </tr>
                </tbody>
              </table>
            </VaScrollContainer>
          </va-card-content>
        </va-card>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
const TASK_STATUS_ORDER = ['pending', 'ready', 'queued', 'in_progress', 'complete', 'failed']
const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low', 'info']

export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    organizations: Object,
    applications: Object,
    plans: Object,
    app_usage: Object,
    task_statuses: Object,
    recent_failed_tasks: Object,
    security_findings: Object,
    recent_failed_scans: Object,
    warnings: Object,
    stats: Object
  },
  computed: {
    taskStatusList () {
      const statuses = { ...this.task_statuses }
      const ordered = TASK_STATUS_ORDER.filter((status) => status in statuses)
      const remaining = Object.keys(statuses).filter((status) => !TASK_STATUS_ORDER.includes(status))

      return [...ordered, ...remaining].map((status) => ({
        name: status,
        count: statuses[status]
      }))
    },
    securityFindingList () {
      const findings = { ...this.security_findings }
      const ordered = SEVERITY_ORDER.filter((severity) => severity in findings)
      const remaining = Object.keys(findings).filter((severity) => !SEVERITY_ORDER.includes(severity))

      return [...ordered, ...remaining].map((severity) => ({
        name: severity,
        count: findings[severity]
      }))
    }
  }
}
</script>

<style lang="scss">
.admin-dashboard {
  .secondary {
    color: #666E75;
  }
  .text-danger {
    color: var(--va-danger);
  }
  .text-warning {
    color: var(--va-warning);
  }
  .va-card__title {
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .warning-link {
    color: inherit;
    text-decoration: underline;
  }
}
</style>
