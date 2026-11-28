<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AppsLayout from './AppsLayout.vue'
import { stringify } from 'yaml'

</script>
<template>
  <Head>
    <title>{{ $t('admin.apps.helmValuesPageTitle') }}</title>
  </Head>
  <div v-for="chart in charts" :key="chart.name" class="mb-4">
    <h5 class="mb-2">{{ chart.name }}</h5>
    <div v-for="section in sections" :key="section.key" class="mb-3">
      <h6 :id="`${section.key}-${chart.name}`" class="mb-1">{{ $t(section.title) }}</h6>
      <p class="mb-1 va-text-secondary">{{ $t(section.help) }}</p>
      <va-scroll-container color="primary" horizontal>
        <pre style="max-height: 600px; overflow-y: auto; overflow-x: hidden; white-space: pre-wrap; word-break: break-word">{{ toYaml(chart[section.key]) }}</pre>
      </va-scroll-container>
    </div>
  </div>
  <p v-if="!charts.length">{{ $t('admin.apps.helmValuesEmpty') }}</p>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(AppsLayout, () => page))
  },
  props: {
    app: Object,
    organization: Object,
    charts: Array
  },
  data () {
    return {
      // The two renders of each chart, which `backup:charts` writes as the same two files
      sections: [
        { key: 'values', title: 'admin.apps.helmValuesChart', help: 'admin.apps.helmValuesChartHelp' },
        { key: 'deployed', title: 'admin.apps.helmValuesDeployed', help: 'admin.apps.helmValuesDeployedHelp' }
      ]
    }
  },
  methods: {
    toYaml (values) {
      return stringify(values ?? {})
    }
  }
}
</script>

<style lang="scss"></style>
