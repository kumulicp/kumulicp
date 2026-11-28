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
    <va-scroll-container color="primary" horizontal>
      <pre style="max-height: 600px; overflow-y: auto; overflow-x: hidden; white-space: pre-wrap; word-break: break-word">{{ toYaml(chart.values) }}</pre>
    </va-scroll-container>
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
  methods: {
    toYaml (values) {
      return stringify(values ?? {})
    }
  }
}
</script>

<style lang="scss"></style>
