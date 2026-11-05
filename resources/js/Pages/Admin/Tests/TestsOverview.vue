<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, usePoll } from '@inertiajs/vue3'

defineProps({
  stats: Object,
  runs: Array,
  untested_versions: Array
})

// Runs move on by themselves while a test org is built, so keep the numbers fresh
usePoll(5000, { only: ['stats', 'runs'] })

const stepColors = { pending: 'secondary', provisioning: 'info', awaiting_confirmation: 'warning', clearing: 'info', passed: 'success', failed: 'danger' }
const gateColors = { untested: 'secondary', stale: 'warning' }
const gateColor = (gate) => gateColors[gate] || 'secondary'
const stepColor = (status) => stepColors[status] || 'secondary'
</script>
<template>
  <Head>
    <title>{{ $t('admin.tests.tests') }} - Control Panel</title>
  </Head>

  <div id="stats" class="mb-4" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem">
    <va-card id="statRunning">
      <va-card-content>
        <div class="va-h3 m-0" style="color: var(--va-info)">{{ stats.running }}</div>
        <div class="va-text-secondary">{{ $t('admin.versionTests.overview.running') }}</div>
      </va-card-content>
    </va-card>
    <va-card id="statUntested">
      <va-card-content>
        <div class="va-h3 m-0">{{ stats.untested }}</div>
        <div class="va-text-secondary">{{ $t('admin.versionTests.overview.untested') }}</div>
      </va-card-content>
    </va-card>
    <va-card id="statAwaiting">
      <va-card-content>
        <div class="va-h3 m-0" :style="{ color: stats.awaiting > 0 ? 'var(--va-warning)' : '' }">{{ stats.awaiting }}</div>
        <div class="va-text-secondary">{{ $t('admin.versionTests.overview.awaiting') }}</div>
      </va-card-content>
    </va-card>
    <va-card id="statPassed">
      <va-card-content>
        <div class="va-h3 m-0" style="color: var(--va-success)">{{ stats.passed }}</div>
        <div class="va-text-secondary">{{ $t('admin.versionTests.overview.passed', { days: stats.days }) }}</div>
      </va-card-content>
    </va-card>
    <va-card id="statFailed">
      <va-card-content>
        <div class="va-h3 m-0" :style="{ color: stats.failed > 0 ? 'var(--va-danger)' : '' }">{{ stats.failed }}</div>
        <div class="va-text-secondary">{{ $t('admin.versionTests.overview.failed', { days: stats.days }) }}</div>
      </va-card-content>
    </va-card>
    <va-card id="statBypassed">
      <va-card-content>
        <div class="va-h3 m-0">{{ stats.bypassed }}</div>
        <div class="va-text-secondary">{{ $t('admin.versionTests.overview.bypassed', { days: stats.days }) }}</div>
      </va-card-content>
    </va-card>
  </div>

  <va-card>
    <va-card-title>{{ $t('admin.versionTests.overview.inProgress') }}</va-card-title>
    <va-card-content>
      <va-alert v-if="runs.length === 0" id="noRuns" color="info" outline>
        {{ $t('admin.versionTests.overview.none') }}
      </va-alert>
      <VaScrollContainer v-else color="primary" horizontal>
        <table id="runs" class="va-table va-table--hoverable" style="width: 100%">
          <thead>
            <tr>
              <th>{{ $t('admin.versionTests.run') }}</th>
              <th>{{ $t('admin.versionTests.startedBy') }}</th>
              <th>{{ $t('admin.versionTests.started') }}</th>
              <th>{{ $t('admin.versionTests.overview.progress') }}</th>
              <th>{{ $t('admin.versionTests.overview.now') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="run in runs" :key="run.id" :id="'run' + run.id">
              <td>
                <Link :href="run.url">{{ run.app }} {{ run.version }}</Link>
                <div class="va-text-secondary" style="font-size: 0.85rem">{{ $t('admin.versionTests.runNumber', { id: run.id }) }}</div>
              </td>
              <td>{{ run.started_by }}</td>
              <td>{{ run.created_at }}</td>
              <td>
                {{ $t('admin.versionTests.overview.progressOf', { passed: run.progress.passed, total: run.progress.total }) }}
                <div v-if="run.progress.pending > 0" class="va-text-secondary" style="font-size: 0.85rem">{{ $t('admin.versionTests.overview.pending', { count: run.progress.pending }) }}</div>
              </td>
              <td>
                <template v-if="run.current_step">
                  <va-badge :color="stepColor(run.current_step.status)" :text="$t('admin.versionTests.stepStatus.' + run.current_step.status)" />
                  <div class="va-text-secondary" style="font-size: 0.85rem">
                    {{ run.current_step.plan }}: {{ $t('admin.versionTests.types.' + run.current_step.type, { from: run.current_step.from_version }) }}
                  </div>
                </template>
                <span v-else class="va-text-secondary">{{ $t('admin.versionTests.overview.waitingForYou') }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </VaScrollContainer>
    </va-card-content>
  </va-card>

  <va-card class="mt-4">
    <va-card-title>{{ $t('admin.versionTests.overview.waitingTitle') }}</va-card-title>
    <va-card-content>
      <va-alert v-if="untested_versions.length === 0" id="noUntested" color="success" outline>
        {{ $t('admin.versionTests.overview.noneWaiting') }}
      </va-alert>
      <VaScrollContainer v-else color="primary" horizontal>
        <table id="untested" class="va-table va-table--hoverable" style="width: 100%">
          <thead>
            <tr>
              <th>{{ $t('admin.versionTests.overview.version') }}</th>
              <th>{{ $t('admin.versionTests.overview.state') }}</th>
              <th>{{ $t('admin.versionTests.overview.added') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="version in untested_versions" :key="version.id" :id="'untested' + version.id">
              <td><Link :href="version.url">{{ version.app }} {{ version.version }}</Link></td>
              <td><va-badge :color="gateColor(version.gate)" :text="$t('admin.versions.testGate.' + version.gate)" /></td>
              <td>{{ version.created_at }}</td>
            </tr>
          </tbody>
        </table>
      </VaScrollContainer>
    </va-card-content>
  </va-card>
</template>

<script>
export default {
  layout: (h, page) => h(AppLayout, [page])
}
</script>
