<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import VersionLayout from './VersionLayout.vue'
import BypassTestsModal from '@/components/BypassTestsModal.vue'
import { Link, useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.versionTests.title', { version: version.version }) }} - Control Panel</title>
  </Head>
  <div class="row justify-center">
    <va-button
      v-if="version.gate !== 'running' && !active_run"
      id="runTests"
      :loading="startForm.processing"
      :disabled="startForm.processing"
      @click="startForm.post('/admin/apps/' + app.slug + '/versions/' + version.version + '/tests')"
    >{{ $t('admin.versions.runTests') }}</va-button>
    <va-button id="bypassTests" class="ml-2" preset="secondary" color="danger" @click="showBypass = true">{{ $t('admin.versions.bypassTests') }}</va-button>
    <BypassTestsModal v-model="showBypass" :app="app" :version="version" />
  </div>
  <va-alert v-if="active_run" id="activeRun" color="info" border="top" border-color="info" class="my-3">
    {{ $t('admin.versionTests.activeElsewhere', { app: active_run.app, version: active_run.version }) }}
    <Link :href="active_run.url">{{ $t('admin.versionTests.openActive') }}</Link>
  </va-alert>
  <h5 class="va-h5 mt-4">{{ $t('admin.versionTests.history') }}</h5>
  <p v-if="runs.length === 0" id="noRuns" class="va-text-secondary">{{ $t('admin.versionTests.noRuns') }}</p>
  <VaScrollContainer v-else color="primary" horizontal>
    <table class="va-table va-table--hoverable mt-3">
      <thead>
        <tr>
          <th>{{ $t('admin.versionTests.run') }}</th>
          <th>{{ $t('admin.versionTests.result') }}</th>
          <th>{{ $t('admin.versionTests.stepsCount') }}</th>
          <th>{{ $t('admin.versionTests.startedBy') }}</th>
          <th>{{ $t('admin.versionTests.started') }}</th>
          <th>{{ $t('admin.versionTests.finished') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="run in runs" :key="run.id">
          <td><Link :href="'/admin/apps/' + app.slug + '/versions/' + version.version + '/tests/' + run.id">#{{ run.id }}</Link></td>
          <td><va-badge :color="statusColor(run.status)" :text="$t('admin.versionTests.status.' + run.status)" /></td>
          <td>{{ run.steps }}</td>
          <td>{{ run.started_by }}</td>
          <td>{{ run.created_at }}</td>
          <td>{{ run.finished_at }}</td>
        </tr>
      </tbody>
    </table>
  </VaScrollContainer>
</template>

<script>
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(VersionLayout, () => page))
  },
  props: {
    app: Object,
    version: Object,
    runs: Array,
    active_run: Object,
    errors: Object
  },
  data () {
    return {
      showBypass: false,
      startForm: useForm({})
    }
  },
  methods: {
    statusColor (status) {
      return { running: 'info', passed: 'success', bypassed: 'warning', failed: 'danger', cancelled: 'secondary' }[status] || 'secondary'
    }
  }
}
</script>
