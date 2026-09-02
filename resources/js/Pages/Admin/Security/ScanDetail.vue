<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import axios from 'axios'

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
    <va-card-title>{{ $t('admin.security.findings') }} ({{ resolvedCount }} / {{ localFindings.length }})</va-card-title>
    <va-card-content>
      <va-scroll-container>
        <table class="va-table va-table--hoverable" style="width:100%; table-layout: fixed">
          <thead>
            <tr>
              <th style="width: 50px">{{ $t('admin.security.severity') }}</th>
              <th>{{ $t('admin.security.title') }}</th>
              <th>{{ $t('admin.security.type') }}</th>
              <th>{{ $t('admin.security.name') }}</th>
              <th>{{ $t('admin.security.category') }}</th>
              <th style="width: 40%">{{ $t('admin.security.remediation') }}</th>
              <th style="width: 110px"></th>
              <th style="width: 90px"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="finding in sortedFindings" :key="finding.id" :class="{ 'text-secondary': finding.resolved }">
              <td><va-badge :color="severityColor(finding.severity)" :text="finding.severity" /></td>
              <td style="white-space: normal; word-break: break-word">{{ finding.title }}</td>
              <td style="white-space: normal; word-break: break-word">{{ finding.resource_type }}</td>
              <td style="white-space: normal; word-break: break-word">{{ finding.resource_name }}</td>
              <td style="white-space: normal; word-break: break-word">{{ finding.category }}</td>
              <td style="white-space: normal; word-break: break-word">{{ finding.remediation }}</td>
              <td>
                <va-button
                  preset="secondary"
                  size="small"
                  :color="finding.resolved ? 'success' : 'secondary'"
                  :loading="finding.togglingResolved"
                  @click="toggleResolved(finding)"
                >
                  {{ finding.resolved ? $t('admin.security.resolved') : $t('admin.security.unresolved') }}
                </va-button>
              </td>
              <td>
                <va-button preset="secondary" size="small" @click="openDetails(finding)">{{ $t('admin.security.details') }}</va-button>
              </td>
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

  <va-modal v-model="showDetailModal" :title="selectedFinding ? selectedFinding.title : ''" hide-default-actions>
    <template v-if="selectedFinding">
      <div class="row mb-2">
        <div class="flex md6"><b>{{ $t('admin.security.severity') }}:</b> <va-badge :color="severityColor(selectedFinding.severity)" :text="selectedFinding.severity" /></div>
        <div class="flex md6"><b>{{ $t('admin.security.category') }}:</b> {{ selectedFinding.category || '-' }}</div>
      </div>
      <div class="row mb-2">
        <div class="flex">
          <va-button
            preset="secondary"
            size="small"
            :color="selectedFinding.resolved ? 'success' : 'secondary'"
            :loading="selectedFinding.togglingResolved"
            @click="toggleResolved(selectedFinding)"
          >
            {{ selectedFinding.resolved ? $t('admin.security.resolved') : $t('admin.security.unresolved') }}
          </va-button>
        </div>
      </div>
      <div class="row mb-2" v-if="selectedFinding.resource_type || selectedFinding.resource_name">
        <div class="flex md6"><b>{{ $t('admin.security.type') }}:</b> {{ selectedFinding.resource_type || '-' }}</div>
        <div class="flex md6"><b>{{ $t('admin.security.name') }}:</b> {{ selectedFinding.resource_name || '-' }}</div>
      </div>
      <div class="row mb-2" v-if="selectedFinding.rule_id">
        <div class="flex"><b>{{ $t('admin.security.ruleId') }}:</b> {{ selectedFinding.rule_id }}</div>
      </div>
      <div class="row mb-2" v-if="selectedFinding.description">
        <div class="flex">
          <b>{{ $t('admin.security.description') }}:</b>
          <div style="white-space: pre-wrap; word-break: break-word">{{ selectedFinding.description }}</div>
        </div>
      </div>
      <div class="row mb-2" v-if="selectedFinding.remediation">
        <div class="flex">
          <b>{{ $t('admin.security.remediation') }}:</b>
          <div style="white-space: pre-wrap; word-break: break-word">{{ selectedFinding.remediation }}</div>
        </div>
      </div>
      <div class="row mb-2" v-if="metadataEntries.length">
        <div class="flex">
          <b>{{ $t('admin.security.metadata') }}:</b>
          <table class="va-table" style="width:100%">
            <tbody>
              <tr v-for="entry in metadataEntries" :key="entry.label">
                <td style="width: 40%">{{ entry.label }}</td>
                <td style="word-break: break-word">
                  <a v-if="entry.isLink" :href="entry.value" target="_blank" rel="noopener">{{ entry.value }}</a>
                  <span v-else>{{ entry.value }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </va-modal>
</template>

<script lang="ts">
const severityOrder = { critical: 0, high: 1, medium: 2, low: 3, info: 4 }

export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    scan: Object
  },
  data () {
    return {
      showDetailModal: false,
      selectedFinding: null,
      localFindings: this.scan.findings.map(finding => ({ ...finding, togglingResolved: false }))
    }
  },
  computed: {
    sortedFindings () {
      return [...this.localFindings].sort((a, b) => severityOrder[a.severity] - severityOrder[b.severity])
    },
    resolvedCount () {
      return this.localFindings.filter(finding => finding.resolved).length
    },
    metadataEntries () {
      if (!this.selectedFinding || !this.selectedFinding.metadata) return []

      return Object.entries(this.selectedFinding.metadata).map(([key, value]) => ({
        label: key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()),
        value,
        isLink: typeof value === 'string' && /^https?:\/\//.test(value)
      }))
    }
  },
  methods: {
    openDetails (finding) {
      this.selectedFinding = finding
      this.showDetailModal = true
    },
    async toggleResolved (finding) {
      finding.togglingResolved = true

      try {
        const response = await axios.patch(`/admin/server/security/scans/${this.scan.id}/findings/${finding.id}`, {
          resolved: !finding.resolved
        })
        finding.resolved = response.data.resolved
        finding.resolved_at = response.data.resolved_at
      } finally {
        finding.togglingResolved = false
      }
    },
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
