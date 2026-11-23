<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AppsLayout from '../AppsLayout.vue'
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ app.name }} - {{ $t('admin.charts.charts') }} - Control Panel</title>
  </Head>
  <p class="va-text-secondary mb-3">{{ $t('admin.charts.intro') }}</p>
  <div v-if="sources.length" class="row align-center mb-3">
    <va-select
      v-model="attachForm.chart_source_id"
      id="chartSource"
      class="mr-2"
      style="min-width: 14rem"
      :label="$t('admin.charts.source')"
      :options="sources"
      value-by="id"
      text-by="name"
      :error="$page.props.errors.chart_source_id"
      :error-messages="$page.props.errors.chart_source_id"
    />
    <va-input
      v-model="attachForm.chart_name"
      id="chartName"
      class="mr-2"
      :label="$t('admin.charts.chartName')"
      :placeholder="app.slug"
      :error="$page.props.errors.chart_name"
      :error-messages="$page.props.errors.chart_name"
    />
    <va-button
      id="attachChart"
      :loading="attachForm.processing"
      :disabled="attachForm.processing || !attachForm.chart_source_id"
      @click="attachForm.post('/admin/apps/' + app.slug + '/charts')"
    >{{ $t('admin.charts.add') }}</va-button>
  </div>
  <VaScrollContainer color="primary" horizontal>
    <table class="va-table va-table--hoverable mt-3">
      <thead>
        <tr>
          <th style="width: 50px">{{ $t('status.enabled') }}</th>
          <th>{{ $t('admin.charts.name') }}</th>
          <th>{{ $t('admin.charts.chart') }}</th>
          <th>{{ $t('admin.charts.deployedBy') }}</th>
          <th>{{ $t('admin.charts.usage') }}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="chart in charts" :key="chart.id" :id="'chart' + chart.id">
          <td style="text-align: center"><va-icon name="fa-circle" :color="chart.enabled ? 'success' : 'backgroundElement'" /></td>
          <td>
            {{ chart.name }}
            <va-badge v-if="chart.is_default" :text="$t('admin.charts.default')" color="info" class="ml-1" />
          </td>
          <td>
            <div>{{ chart.chart_name }}</div>
            <div class="va-text-secondary" style="font-size: 0.85rem">{{ chart.helm_repo }}</div>
          </td>
          <td>{{ chart.source }}</td>
          <td>{{ $t('admin.charts.usageSummary', { groups: chart.groups, versions: chart.versions, plans: chart.plans }) }}</td>
          <td style="text-align: right">
            <va-button
              v-if="!chart.is_default && !chart.enabled"
              :id="'enableChart' + chart.id"
              size="small"
              preset="secondary"
              @click="toggleForm.post('/admin/apps/' + app.slug + '/charts/' + chart.id + '/enable')"
            >{{ $t('admin.charts.enable') }}</va-button>
            <va-button
              v-if="!chart.is_default && chart.enabled"
              :id="'disableChart' + chart.id"
              size="small"
              preset="secondary"
              color="danger"
              @click="toggleForm.post('/admin/apps/' + app.slug + '/charts/' + chart.id + '/disable')"
            >{{ $t('admin.charts.disable') }}</va-button>
          </td>
        </tr>
      </tbody>
    </table>
  </VaScrollContainer>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(AppsLayout, () => page))
  },
  props: {
    app: Object,
    charts: Array,
    sources: {
      type: Array,
      default: () => []
    },
    errors: Object
  },
  data () {
    return {
      attachForm: useForm({ chart_source_id: this.sources.length === 1 ? this.sources[0].id : null, chart_name: '' }),
      toggleForm: useForm({})
    }
  }
}
</script>
