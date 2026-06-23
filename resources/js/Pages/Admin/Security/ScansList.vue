<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, router, useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.security.scans') }} - Control Panel</title>
  </Head>

  <div class="row mb-4">
    <div class="flex md2" v-for="(count, severity) in summary" :key="severity">
      <va-card :color="severityColor(severity)" gradient>
        <va-card-content>
          <div class="va-text-bold" style="font-size: 1.6rem">{{ count }}</div>
          <div>{{ severity }}</div>
        </va-card-content>
      </va-card>
    </div>
  </div>

  <va-card class="mb-4">
    <va-card-title class="row align-center justify-space-between">
      <span>{{ $t('admin.security.scans') }}</span>
      <va-button @click="showRunModal = true">{{ $t('admin.security.runScan') }}</va-button>
    </va-card-title>
    <va-card-content>
      <div class="row">
        <div class="flex flex-col md3">
          <VaSelect
            v-model="filters.org_server_id"
            :label="$t('admin.security.server')"
            :options="org_servers"
            value-by="id"
            text-by="name"
            clearable
            @update:modelValue="applyFilters"
          />
        </div>
        <div class="flex flex-col md2">
          <VaSelect
            v-model="filters.tool"
            :label="$t('admin.security.tool')"
            :options="tools"
            clearable
            @update:modelValue="applyFilters"
          />
        </div>
        <div class="flex flex-col md2">
          <VaSelect
            v-model="filters.severity"
            :label="$t('admin.security.severity')"
            :options="['critical', 'high', 'medium', 'low', 'info']"
            clearable
            @update:modelValue="applyFilters"
          />
        </div>
        <div class="flex flex-col md2">
          <VaDateInput
            v-model="filters.date_from"
            :label="$t('admin.security.dateFrom')"
            clearable
            @update:modelValue="applyFilters"
          />
        </div>
        <div class="flex flex-col md2">
          <VaDateInput
            v-model="filters.date_to"
            :label="$t('admin.security.dateTo')"
            clearable
            @update:modelValue="applyFilters"
          />
        </div>
      </div>

      <va-scroll-container color="primary" horizontal>
        <table class="va-table va-table--hoverable mt-3" style="width:100%">
          <thead>
            <tr>
              <th>{{ $t('admin.security.tool') }}</th>
              <th>{{ $t('admin.security.server') }}</th>
              <th>{{ $t('admin.security.status') }}</th>
              <th>{{ $t('admin.security.findings') }}</th>
              <th>{{ $t('admin.security.startedAt') }}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="scan in scans" :key="scan.id">
              <td>{{ scan.tool }}</td>
              <td>{{ scan.organization }}</td>
              <td><va-badge :color="statusColor(scan.status)" :text="scan.status" /></td>
              <td>
                <va-badge
                  v-for="(count, severity) in scan.summary"
                  v-if="count > 0"
                  :key="severity"
                  :color="severityColor(severity)"
                  :text="`${severity}: ${count}`"
                  class="mr-1"
                />
              </td>
              <td>{{ scan.started_at }}</td>
              <td class="va-text-right">
                <Link :href="`/admin/server/security/scans/${scan.id}`">{{ $t('admin.security.viewReport') }}</Link>
              </td>
            </tr>
          </tbody>
        </table>
      </va-scroll-container>

      <va-pagination v-if="meta.pages > 1" class="mt-3 mb-3 justify-center" v-model="curPage" :pages="meta.pages" input @update:modelValue="changePage" />
    </va-card-content>
  </va-card>

  <va-modal v-model="showRunModal" :title="$t('admin.security.runScan')" @ok="submitRunScan" :ok-text="$t('admin.security.run')">
    <VaSelect
      v-model="runForm.org_server_id"
      :label="$t('admin.security.server')"
      :options="org_servers"
      value-by="id"
      text-by="name"
      class="mb-3"
    />
    <VaSelect
      v-model="runForm.tool"
      :label="$t('admin.security.tool')"
      :options="tools"
    />
  </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    scans: Array,
    meta: Object,
    summary: Object,
    org_servers: Array,
    tools: Array
  },
  data () {
    return {
      curPage: this.meta.page,
      showRunModal: false,
      filters: {
        org_server_id: this.$page.props.ziggy?.query?.org_server_id || '',
        tool: this.$page.props.ziggy?.query?.tool || '',
        severity: this.$page.props.ziggy?.query?.severity || '',
        date_from: this.$page.props.ziggy?.query?.date_from || '',
        date_to: this.$page.props.ziggy?.query?.date_to || ''
      },
      runForm: {
        org_server_id: '',
        tool: ''
      }
    }
  },
  methods: {
    applyFilters () {
      this.curPage = 1
      this.visit()
    },
    changePage () {
      this.visit()
    },
    visit () {
      const query = { ...this.filters, page: this.curPage }
      router.get('/admin/server/security/scans', query, { preserveState: true })
    },
    submitRunScan () {
      const form = useForm(this.runForm)
      form.post('/admin/server/security/scans', {
        onSuccess: () => { this.showRunModal = false }
      })
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
