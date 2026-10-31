<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import VersionLayout from './VersionLayout.vue'
import BypassTestsModal from '@/components/BypassTestsModal.vue'
import { Link, useForm, usePoll } from '@inertiajs/vue3'
import { watch } from 'vue'

const props = defineProps({
  app: Object,
  version: Object,
  run: Object,
  steps: Array,
  current_step: Object,
  can: Object,
  polling: Boolean,
  events: Array
})

// While a test org is being built or deleted there is nothing to do but wait,
// so the page keeps itself up to date until it is the admin's turn again.
const { start, stop } = usePoll(4000, { only: ['run', 'steps', 'current_step', 'can', 'polling', 'events'] }, { autoStart: false })

watch(() => props.polling, (polling) => polling ? start() : stop(), { immediate: true })
</script>
<template>
  <Head>
    <title>{{ $t('admin.versionTests.runNumber', { id: run.id }) }} - Control Panel</title>
  </Head>
  <div class="row items-center mb-3">
    <h5 class="va-h5 m-0">{{ $t('admin.versionTests.runNumber', { id: run.id }) }}</h5>
    <va-badge id="runStatus" class="ml-3" :color="statusColor(run.status)" :text="$t('admin.versionTests.status.' + run.status)" />
  </div>
  <div class="va-text-secondary mb-3">
    <div>{{ $t('admin.versionTests.startedBy') }}: {{ run.started_by }} ({{ run.created_at }})</div>
    <div v-if="run.baseline">{{ $t('admin.versionTests.baseline') }}: {{ run.baseline }}</div>
    <div v-else-if="run.status !== 'bypassed'">{{ $t('admin.versionTests.noBaseline') }}</div>
  </div>

  <va-alert v-if="run.status === 'passed'" id="runPassed" color="success" border="top" border-color="success" class="mb-3">
    {{ $t('admin.versionTests.runPassed') }}
    <Link :href="'/admin/apps/' + app.slug + '/versions/' + version.version">{{ $t('admin.versions.version') }} {{ version.version }}</Link>
  </va-alert>
  <va-alert v-if="run.status === 'failed'" id="runFailed" color="danger" border="top" border-color="danger" class="mb-3">
    {{ $t('admin.versionTests.runFailed') }}
  </va-alert>
  <va-alert v-if="run.status === 'bypassed'" id="runBypassed" color="warning" border="top" border-color="warning" class="mb-3">
    {{ $t('admin.versionTests.bypassedNotice', { name: run.bypassed_by, reason: run.bypass_reason }) }}
  </va-alert>

  <va-card v-if="current_step" id="currentStep" class="mb-4" outlined>
    <va-card-title>{{ $t('admin.versionTests.readyTitle', { plan: current_step.plan }) }}</va-card-title>
    <va-card-content>
      <p>{{ current_step.type === 'upgrade' ? $t('admin.versionTests.readyUpgrade', { plan: current_step.plan }) : $t('admin.versionTests.readyFreshInstall', { plan: current_step.plan }) }}</p>
      <p class="va-text-secondary">{{ $t('admin.versionTests.readyInstructions') }}</p>
      <table class="va-table mt-2">
        <tbody>
          <tr>
            <td class="font-bold">{{ $t('admin.versionTests.appUrl') }}</td>
            <td><a v-if="current_step.app_url" id="testAppUrl" :href="current_step.app_url" target="_blank" rel="noopener noreferrer">{{ current_step.app_url }}</a></td>
          </tr>
          <tr>
            <td class="font-bold">{{ $t('admin.versionTests.username') }}</td>
            <td><code id="testUsername">{{ current_step.username }}</code></td>
          </tr>
          <tr>
            <td class="font-bold">{{ $t('admin.versionTests.password') }}</td>
            <td><code id="testPassword">{{ current_step.password }}</code></td>
          </tr>
        </tbody>
      </table>
      <div class="row mt-4">
        <va-button id="itWorked" color="success" :loading="confirmForm.processing" :disabled="confirmForm.processing" @click="confirmForm.post(stepUrl(current_step.id, 'confirm'), { preserveScroll: true })">{{ $t('admin.versionTests.itWorked') }}</va-button>
        <va-button id="itDidNotWork" class="ml-2" color="danger" preset="secondary" @click="showFail = true">{{ $t('admin.versionTests.itDidNotWork') }}</va-button>
      </div>
    </va-card-content>
  </va-card>

  <va-alert v-if="polling && !current_step" id="waitingHint" color="info" outline class="mb-3">
    {{ steps.some((step) => step.status === 'clearing') ? $t('admin.versionTests.clearingHint') : $t('admin.versionTests.provisioningHint') }}
  </va-alert>

  <div class="row mb-3">
    <va-button v-if="can.start_next" id="runNext" :loading="nextForm.processing" :disabled="nextForm.processing" @click="nextForm.post(runUrl + '/next', { preserveScroll: true })">{{ $t('admin.versionTests.runNext') }}</va-button>
    <va-button v-if="can.retry_step_id" id="retryStep" color="warning" @click="retryForm.post(stepUrl(can.retry_step_id, 'retry'), { preserveScroll: true })" :disabled="retryForm.processing">{{ $t('admin.versionTests.retryStep') }}</va-button>
    <va-button v-if="run.status === 'running'" id="bypassTests" class="ml-2" preset="secondary" color="danger" @click="showBypass = true">{{ $t('admin.versions.bypassTests') }}</va-button>
    <va-button v-if="can.cancel" id="cancelRun" class="ml-2" preset="secondary" :disabled="cancelForm.processing" @click="cancelForm.post(runUrl + '/cancel', { preserveScroll: true })">{{ $t('admin.versionTests.cancel') }}</va-button>
  </div>
  <p v-if="can.start_next" class="va-text-secondary">{{ $t('admin.versionTests.runNextHint') }}</p>

  <h5 class="va-h5 mt-4">{{ $t('admin.versionTests.steps') }}</h5>
  <VaScrollContainer color="primary" horizontal>
    <table class="va-table va-table--hoverable mt-2" id="steps">
      <thead>
        <tr>
          <th>#</th>
          <th>{{ $t('admin.versionTests.plan') }}</th>
          <th>{{ $t('admin.versionTests.test') }}</th>
          <th>{{ $t('admin.versionTests.result') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="step in steps" :key="step.id" :id="'step' + step.id">
          <td>{{ step.position }}</td>
          <td>{{ step.plan }}</td>
          <td>{{ $t('admin.versionTests.types.' + step.type, { from: step.from_version }) }}</td>
          <td>
            <va-badge :color="stepColor(step.status)" :text="$t('admin.versionTests.stepStatus.' + step.status)" />
            <div v-if="step.failure_reason" class="va-text-secondary" style="font-size: 0.85rem">{{ step.failure_reason }}</div>
          </td>
        </tr>
      </tbody>
    </table>
  </VaScrollContainer>

  <h5 class="va-h5 mt-4">{{ $t('admin.versionTests.log') }}</h5>
  <p v-if="events.length === 0" class="va-text-secondary">{{ $t('admin.versionTests.noEvents') }}</p>
  <ul v-else id="eventLog" class="mt-2">
    <li v-for="event in events" :key="event.id" class="mb-2">
      <span class="va-text-secondary">{{ event.created_at }}</span>
      <span class="ml-2">{{ eventText(event) }}</span>
      <span class="va-text-secondary ml-2">({{ event.user || $t('admin.versionTests.system') }})</span>
      <div v-if="event.message" class="va-text-secondary ml-4" style="font-size: 0.85rem">{{ event.message }}</div>
    </li>
  </ul>

  <va-modal v-model="showFail" no-outside-dismiss no-padding size="small" class="p-0">
    <template #content="{ ok }">
      <form @submit.prevent="failForm.post(stepUrl(current_step.id, 'fail'), { preserveScroll: true, onSuccess: () => showFail = false })">
        <va-card-title class="m-0">{{ $t('admin.versionTests.confirmFailTitle') }}</va-card-title>
        <va-card-content class="m-0">
          <p class="mb-3">{{ $t('admin.versionTests.confirmFailText') }}</p>
          <va-textarea v-model="failForm.reason" id="failReason" :label="$t('admin.versionTests.failReason')" :min-rows="3" />
        </va-card-content>
        <va-card-actions align="right" class="">
          <va-button color="textInverted" :disabled="failForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
          <va-button type="submit" id="confirmFail" color="danger" :disabled="failForm.processing" class="mr-2 mb-2">{{ $t('admin.versionTests.itDidNotWork') }}</va-button>
        </va-card-actions>
      </form>
    </template>
  </va-modal>
  <BypassTestsModal v-model="showBypass" :app="app" :version="version" />
</template>

<script>
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(VersionLayout, () => page))
  },
  data () {
    return {
      showFail: false,
      showBypass: false,
      confirmForm: useForm({}),
      nextForm: useForm({}),
      retryForm: useForm({}),
      cancelForm: useForm({}),
      failForm: useForm({ reason: '' })
    }
  },
  computed: {
    runUrl () {
      return '/admin/apps/' + this.app.slug + '/versions/' + this.version.version + '/tests/' + this.run.id
    }
  },
  methods: {
    stepUrl (id, action) {
      return this.runUrl + '/steps/' + id + '/' + action
    },
    statusColor (status) {
      return { running: 'info', passed: 'success', bypassed: 'warning', failed: 'danger', cancelled: 'secondary' }[status] || 'secondary'
    },
    stepColor (status) {
      return { pending: 'secondary', provisioning: 'info', awaiting_confirmation: 'warning', clearing: 'info', passed: 'success', failed: 'danger' }[status] || 'secondary'
    },
    eventText (event) {
      return this.$t('admin.versionTests.events.' + event.type, {
        steps: event.context.steps,
        version: event.context.version || this.version.version,
        step: event.step_type ? this.$t('admin.versionTests.stepTypes.' + event.step_type) : '',
        plan: event.plan || ''
      })
    }
  }
}
</script>
