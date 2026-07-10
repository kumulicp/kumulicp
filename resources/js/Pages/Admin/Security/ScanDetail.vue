<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ scan.tool }} {{ $t('admin.security.scanReport') }} - Control Panel</title>
  </Head>

  <va-card class="mb-4">
    <va-card-title>
      <span class="mr-3">{{ scan.tool }} - {{ scan.organization }}</span>
      <va-badge :color="statusColor(scan.status)" :text="scan.status" />
    </va-card-title>
    <va-card-content>
      <div class="row">
        <div class="flex md3"><b>{{ $t('admin.security.startedAt') }}:</b> {{ scan.started_at }}</div>
        <div class="flex md3"><b>{{ $t('admin.security.finishedAt') }}:</b> {{ scan.finished_at }}</div>
        <div class="flex md3"><b>{{ $t('admin.security.triggeredBy') }}:</b> {{ scan.triggered_by }}</div>
      </div>
      <div class="row mt-2" v-if="scan.error_message">
        <div class="flex"><b>{{ $t('admin.tasks.errorMessage') }}:</b> {{ scan.error_message }}</div>
      </div>
    </va-card-content>
  </va-card>

  <va-card class="mb-4">
    <va-card-title>{{ $t('admin.security.findings') }} ({{ scan.findings.length }})</va-card-title>
    <va-card-content>
      <va-scroll-container>
        <table class="va-table va-table--hoverable" style="width:100%; table-layout: fixed">
          <thead>
            <tr>
              <th style="width: 50px">{{ $t('admin.security.severity') }}</th>
              <th>{{ $t('admin.security.title') }}</th>
              <th>{{ $t('admin.security.category') }}</th>
              <th style="width: 70%">{{ $t('admin.security.remediation') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="finding in sortedFindings" :key="finding.id">
              <td><va-badge :color="severityColor(finding.severity)" :text="finding.severity" /></td>
              <td style="white-space: normal; word-break: break-word">{{ finding.title }}</td>
              <td style="white-space: normal; word-break: break-word">{{ finding.category }}</td>
              <td style="white-space: normal; word-break: break-word">{{ finding.remediation }}</td>
            </tr>
          </tbody>
        </table>
      </va-scroll-container>
    </va-card-content>
  </va-card>

  <va-card v-if="scan.raw_output">
    <va-card-title>{{ $t('admin.security.rawOutput') }}</va-card-title>
    <va-card-content>
      <va-collapse :header="$t('admin.security.rawOutput')">
        <pre style="max-height: 400px; overflow-y: auto; overflow-x: hidden; white-space: pre-wrap; word-break: break-word">{{ scan.raw_output }}</pre>
      </va-collapse>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
const severityOrder = { critical: 0, high: 1, medium: 2, low: 3, info: 4 }

export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    scan: Object
  },
  computed: {
    sortedFindings () {
      return [...this.scan.findings].sort((a, b) => severityOrder[a.severity] - severityOrder[b.severity])
    }
  },
  methods: {
    severityColor (severity) {
      return {
        critical: 'danger',
        high: 'danger',
        medium: 'warning',
        low: 'info',
        info: 'secondary'
      }[severity] || 'secondary'
    },
    statusColor (status) {
      return {
        complete: 'success',
        failed: 'danger',
        running: 'info',
        pending: 'warning'
      }[status] || 'secondary'
    }
  }
}
</script>
