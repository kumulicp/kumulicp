<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import BypassTestsModal from '@/components/BypassTestsModal.vue'
import { Link, useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.sharedApps.activateTitle') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('admin.sharedApps.activateTitle') }}: {{ app.label }}</va-card-title>
    <va-card-content>
      <p class="mb-4">{{ $t('admin.sharedApps.activateDescription', { app: app.label }) }}</p>
      <va-stepper v-model="step" :steps="steps" controlsHidden>
        <template #step-content-0>
          <p class="mb-3">{{ $t('admin.sharedApps.serversStep', { app: app.label }) }}</p>
          <dl class="mb-3">
            <div v-for="server in serverList" :key="server.key" class="mb-1">
              <dt class="va-text-secondary">{{ server.label }}</dt>
              <dd :id="server.key + 'Server'">{{ server.name ?? $t('admin.sharedApps.notSet') }}</dd>
            </div>
          </dl>
          <p v-if="!activation.servers_saved" class="mb-3 text-color-danger">{{ $t('admin.sharedApps.needServers') }}</p>
          <Link :href="links.plan">
            <va-button id="editServers">{{ $t('admin.sharedApps.editServers') }}</va-button>
          </Link>
        </template>

        <template #step-content-1>
          <p class="mb-3">{{ $t('admin.sharedApps.configurationStep', { app: app.label }) }}</p>
          <Link :href="links.configurations">
            <va-button id="editConfigurations">{{ $t('admin.sharedApps.editConfiguration') }}</va-button>
          </Link>
        </template>

        <template #step-content-2>
          <p class="mb-3">{{ $t('admin.sharedApps.reviewStep') }}</p>
          <Link :href="links.helm_values">
            <va-button id="reviewChart">{{ $t('admin.sharedApps.reviewChart') }}</va-button>
          </Link>
        </template>

        <template #step-content-3>
          <p class="mb-3">
            {{ $t('admin.sharedApps.testsDescription', { version: activation.version }) }}
            <va-badge class="ml-2" :color="gateColor(activation.gate)" :text="$t('admin.versions.testGate.' + activation.gate)" />
          </p>
          <va-alert v-if="activation.gate === 'stale'" id="testsStale" color="warning" class="mb-3" icon="warning">
            {{ $t('admin.sharedApps.testsStale') }}
          </va-alert>
          <va-alert v-if="activation.gate === 'bypassed' && activation.bypassed_by" color="info" class="mb-3">
            {{ $t('admin.sharedApps.testsBypassedBy', { name: activation.bypassed_by, reason: activation.bypass_reason }) }}
          </va-alert>
          <div>
            <va-button id="runTests"
              class="mr-2"
              :disabled="activation.gate === 'running' || testsForm.processing"
              @click="testsForm.post('/admin/service/shared-apps/' + app.id + '/tests')">
              {{ $t('admin.versions.runTests') }}
            </va-button>
            <va-button id="bypassTests"
              class="mr-2"
              preset="secondary"
              color="danger"
              @click="showBypass = true">
              {{ $t('admin.versions.bypassTests') }}
            </va-button>
            <Link v-if="activation.run_url" :href="activation.run_url">
              <va-button preset="secondary">{{ $t('admin.versions.viewTestRun') }}</va-button>
            </Link>
          </div>
          <BypassTestsModal v-model="showBypass" :action="'/admin/service/shared-apps/' + app.id + '/tests/bypass'" :version-name="activation.version" />
        </template>

        <template #step-content-4>
          <p class="mb-3">{{ $t('admin.sharedApps.activateStep', { app: app.label }) }}</p>
          <va-alert v-if="!activation.can_activate" id="activationBlocked" color="warning" icon="warning" class="mb-3">
            {{ $t('admin.sharedApps.activationBlocked', { version: activation.version }) }}
          </va-alert>
          <va-button id="activate"
            :disabled="!activation.can_activate || activateForm.processing"
            @click="activateForm.post('/admin/service/shared-apps/' + app.id + '/activate')">
            {{ $t('admin.sharedApps.activate') }}
          </va-button>
        </template>
      </va-stepper>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    app: Object,
    activation: Object,
    servers: Object,
    links: Object,
    errors: Object
  },
  data () {
    return {
      // The first step still to do
      step: this.activation.current_step,
      showBypass: false,
      testsForm: useForm({}),
      activateForm: useForm({})
    }
  },
  computed: {
    steps () {
      return [
        { label: this.$t('admin.sharedApps.stepServers'), icon: 'dns', completed: this.activation.servers_saved },
        { label: this.$t('admin.sharedApps.stepConfiguration'), icon: 'tune' },
        // The chart is built from the servers, and the tests run on them
        { label: this.$t('admin.sharedApps.stepReview'), icon: 'visibility', disabled: !this.activation.servers_saved },
        { label: this.$t('admin.sharedApps.stepTests'), icon: 'fact_check', disabled: !this.activation.servers_saved, completed: this.activation.can_activate },
        { label: this.$t('admin.sharedApps.stepActivate'), icon: 'rocket_launch', disabled: !this.activation.can_activate }
      ]
    },
    serverList () {
      return [
        { key: 'web', label: this.$t('admin.plans.webServer'), name: this.servers.web },
        { key: 'database', label: this.$t('admin.plans.databaseServer'), name: this.servers.database },
        { key: 'sso', label: this.$t('admin.plans.ssoServer'), name: this.servers.sso }
      ]
    }
  },
  watch: {
    // Back from a test run or after bypassing: carry on from where things stand
    'activation.current_step' (step) {
      this.step = step
    }
  },
  methods: {
    gateColor (gate) {
      return { passed: 'success', bypassed: 'warning', running: 'info', failed: 'danger', stale: 'warning', untested: 'secondary' }[gate] || 'secondary'
    }
  }
}
</script>

<style></style>
