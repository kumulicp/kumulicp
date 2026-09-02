<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import axios from 'axios'

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
    <va-card-title>
      <span>{{ $t('admin.security.scans') }}</span>
    </va-card-title>
    <va-card-content>
      <div class="row">
        <div class="flex flex-col md3">
          <va-button @click="showRunModal = true">{{ $t('admin.security.runScan') }}</va-button>
        </div>
      </div>
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
                <div>
                  <va-badge
                    v-for="[severity, count] in nonZeroSummary(scan.summary)"
                    :key="severity"
                    :color="severityColor(severity)"
                    :text="`${severity}: ${count}`"
                    class="mr-1"
                  />
                </div>
                <div v-if="scan.findings_count > 0" class="mt-1">
                  {{ $t('admin.security.resolvedCount', { resolved: scan.resolved_findings_count, total: scan.findings_count }) }}
                </div>
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
    <div class="row align-center">
      <div class="flex">
        <VaSelect
          v-model="runForm.tool"
          :label="$t('admin.security.tool')"
          :options="tools"
        />
      </div>
      <div class="flex flex-none">
        <va-button
          preset="plain"
          icon="info"
          :aria-label="$t('admin.security.toolReference')"
          @click="showToolInfoModal = true"
        />
      </div>
    </div>

    <template v-if="requiresTargets">
      <VaSelect
        v-model="runForm.app_instance_id"
        :label="$t('admin.security.app')"
        :options="apps"
        value-by="value"
        text-by="text"
        clearable
        class="mb-3"
      />
      <VaSelect
        v-if="runForm.app_instance_id"
        v-model="runForm.app_domain_targets"
        :label="$t('admin.security.appDomains')"
        :options="appDomainOptions"
        multiple
        class="mb-3"
      />
      <VaSelect
        v-model="runForm.custom_domains"
        :label="$t('admin.security.customDomains')"
        :options="savedCustomDomains"
        multiple
        class="mb-3"
      />
      <div class="row align-end">
        <div class="flex">
          <VaInput
            v-model="newCustomDomain"
            :placeholder="$t('admin.security.customDomainPlaceholder')"
            @keyup.enter="addCustomDomain"
          />
        </div>
        <div class="flex flex-none">
          <va-button preset="secondary" @click="addCustomDomain">{{ $t('admin.security.addCustomDomain') }}</va-button>
        </div>
      </div>
    </template>

    <template v-if="supportsSeverityFilter">
      <VaSelect
        v-model="runForm.severity"
        :label="`${$t('admin.security.severityFilter')} *`"
        :options="severities"
        :error="severityMissing"
        :error-messages="severityMissing ? [$t('admin.security.severityFilterWarning')] : []"
        multiple
        class="mb-3"
      />
      <VaSwitch
        v-model="runForm.ignore_unfixed"
        :label="$t('admin.security.ignoreUnfixed')"
        class="mb-3"
      />
    </template>

    <template v-if="supportsNamespaceFilter">
      <VaSwitch
        v-model="runForm.include_org_namespace"
        :label="$t('admin.security.includeOrgNamespace')"
        class="mb-3"
      />
      <VaSelect
        v-model="runForm.custom_namespaces"
        :label="$t('admin.security.customNamespaces')"
        :options="savedCustomNamespaces"
        multiple
        class="mb-3"
      />
      <div class="row align-end">
        <div class="flex">
          <VaInput
            v-model="newCustomNamespace"
            :placeholder="$t('admin.security.customNamespacePlaceholder')"
            @keyup.enter="addCustomNamespace"
          />
        </div>
        <div class="flex flex-none">
          <va-button preset="secondary" @click="addCustomNamespace">{{ $t('admin.security.addCustomNamespace') }}</va-button>
        </div>
      </div>
      <VaAlert
        v-if="!runForm.include_org_namespace && runForm.custom_namespaces.length === 0"
        outline
        icon="warning"
        color="warning"
        class="mb-3 mt-3"
      >
        {{ $t('admin.security.namespaceFilterWarning') }}
      </VaAlert>
    </template>
  </va-modal>

  <va-modal v-model="showToolInfoModal" :title="$t('admin.security.toolReference')" hide-default-actions>
    <div v-for="tool in tools" :key="tool" class="mb-3">
      <div class="va-text-bold">{{ tool }}</div>
      <div>{{ tool_descriptions[tool] }}</div>
    </div>
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
    tools: Array,
    tool_descriptions: Object,
    tools_requiring_targets: Array,
    tools_supporting_severity_filter: Array,
    tools_supporting_namespace_filter: Array,
    severities: Array
  },
  data () {
    return {
      curPage: this.meta.page,
      showRunModal: false,
      showToolInfoModal: false,
      filters: {
        org_server_id: this.$page.props.ziggy?.query?.org_server_id || '',
        tool: this.$page.props.ziggy?.query?.tool || '',
        severity: this.$page.props.ziggy?.query?.severity || '',
        date_from: this.$page.props.ziggy?.query?.date_from || '',
        date_to: this.$page.props.ziggy?.query?.date_to || ''
      },
      runForm: this.emptyRunForm(),
      apps: [],
      appDomainOptions: [],
      savedCustomDomains: [],
      newCustomDomain: '',
      savedCustomNamespaces: [],
      newCustomNamespace: ''
    }
  },
  computed: {
    requiresTargets () {
      return this.tools_requiring_targets.includes(this.runForm.tool)
    },
    supportsSeverityFilter () {
      return this.tools_supporting_severity_filter.includes(this.runForm.tool)
    },
    supportsNamespaceFilter () {
      return this.tools_supporting_namespace_filter.includes(this.runForm.tool)
    },
    severityMissing () {
      return this.supportsSeverityFilter && this.runForm.severity.length === 0
    },
    selectedOrganizationId () {
      const server = this.org_servers.find(s => s.id === this.runForm.org_server_id)
      return server ? server.organization_id : null
    }
  },
  watch: {
    'runForm.tool' () {
      this.loadTargetOptions()
      this.loadCustomNamespaces()
    },
    'runForm.org_server_id' () {
      this.runForm.app_instance_id = ''
      this.runForm.app_domain_targets = []
      this.apps = []
      this.appDomainOptions = []
      this.savedCustomNamespaces = []
      this.loadTargetOptions()
      this.loadCustomNamespaces()
    },
    'runForm.app_instance_id' (appId) {
      this.runForm.app_domain_targets = []
      this.appDomainOptions = []
      if (appId) this.loadAppDomains(appId)
    }
  },
  methods: {
    nonZeroSummary (summary) {
      return Object.entries(summary).filter(([, count]) => count > 0)
    },
    emptyRunForm () {
      return {
        org_server_id: '',
        tool: '',
        app_instance_id: '',
        app_domain_targets: [],
        custom_domains: [],
        severity: ['HIGH', 'CRITICAL'],
        ignore_unfixed: false,
        include_org_namespace: true,
        custom_namespaces: []
      }
    },
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
    async loadTargetOptions () {
      if (!this.requiresTargets || !this.runForm.org_server_id) return

      const appsResponse = await axios.get('/admin/server/security/scans/apps', {
        params: { org_server_id: this.runForm.org_server_id }
      })
      this.apps = appsResponse.data

      if (this.selectedOrganizationId) {
        const domainsResponse = await axios.get('/admin/server/security/scans/custom-domains', {
          params: { organization_id: this.selectedOrganizationId }
        })
        this.savedCustomDomains = domainsResponse.data.map(domain => domain.value)
      }
    },
    async loadAppDomains (appId) {
      const response = await axios.get(`/admin/server/security/scans/apps/${appId}/domains`)
      this.appDomainOptions = response.data.map(domain => domain.value)
    },
    addCustomDomain () {
      const domain = this.newCustomDomain.trim()
      if (!domain) return
      if (!this.savedCustomDomains.includes(domain)) this.savedCustomDomains.push(domain)
      if (!this.runForm.custom_domains.includes(domain)) this.runForm.custom_domains.push(domain)
      this.newCustomDomain = ''
    },
    async loadCustomNamespaces () {
      if (!this.supportsNamespaceFilter || !this.selectedOrganizationId) return

      const response = await axios.get('/admin/server/security/scans/custom-namespaces', {
        params: { organization_id: this.selectedOrganizationId }
      })
      this.savedCustomNamespaces = response.data.map(namespace => namespace.value)
    },
    addCustomNamespace () {
      const namespace = this.newCustomNamespace.trim()
      if (!namespace) return
      if (!this.savedCustomNamespaces.includes(namespace)) this.savedCustomNamespaces.push(namespace)
      if (!this.runForm.custom_namespaces.includes(namespace)) this.runForm.custom_namespaces.push(namespace)
      this.newCustomNamespace = ''
    },
    submitRunScan () {
      // va-modal keeps the modal open when the @ok handler returns false.
      if (this.severityMissing) {
        return false
      }

      const form = useForm(this.runForm)
      form.post('/admin/server/security/scans', {
        onSuccess: () => {
          this.showRunModal = false
          this.runForm = this.emptyRunForm()
          this.apps = []
          this.appDomainOptions = []
          this.savedCustomDomains = []
          this.savedCustomNamespaces = []
        }
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
