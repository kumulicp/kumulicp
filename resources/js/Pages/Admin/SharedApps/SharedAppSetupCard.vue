<script setup lang="ts">
import BypassTestsModal from '@/components/BypassTestsModal.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

// Sits on top of every page of a shared app until it is activated. The steps
// that have a page of their own are links to it; the others open here.
const props = defineProps<{
  sharedApp: Record<string, any>
}>()

const { t } = useI18n()

const setup = computed(() => props.sharedApp.setup)
const base = computed(() => '/admin/service/shared-apps/' + props.sharedApp.id)

const step = ref(setup.value.current_step)
const showBypass = ref(false)

// Back from a test run or after bypassing: carry on from where things stand
watch(() => setup.value.current_step, (current) => {
  step.value = current
})

const versionForm = useForm({ version: setup.value.version.id })
watch(() => setup.value.version.id, (id) => {
  versionForm.version = id
})

const testsForm = useForm({})
const activateForm = useForm({})

const steps = computed(() => [
  { label: t('admin.sharedApps.stepVersion'), icon: 'sell' },
  { label: t('admin.sharedApps.stepServers'), icon: 'dns', url: setup.value.links.plan, completed: setup.value.servers_saved },
  { label: t('admin.sharedApps.stepConfiguration'), icon: 'tune', url: setup.value.links.configurations },
  // The chart is built from the servers, and the tests run on them
  { label: t('admin.sharedApps.stepReview'), icon: 'visibility', url: setup.value.links.helm_values, disabled: !setup.value.servers_saved },
  { label: t('admin.sharedApps.stepTests'), icon: 'fact_check', disabled: !setup.value.servers_saved, completed: setup.value.can_activate },
  { label: t('admin.sharedApps.stepActivate'), icon: 'rocket_launch', disabled: !setup.value.can_activate }
])

const serverList = computed(() => [
  { key: 'web', label: t('admin.plans.webServer'), name: setup.value.servers.web },
  { key: 'database', label: t('admin.plans.databaseServer'), name: setup.value.servers.database },
  { key: 'sso', label: t('admin.plans.ssoServer'), name: setup.value.servers.sso }
])

const gateColor = (gate: string) => ({ passed: 'success', bypassed: 'warning', running: 'info', failed: 'danger', stale: 'warning', untested: 'secondary' } as Record<string, string>)[gate] || 'secondary'
</script>

<template>
  <va-card id="sharedAppSetup" class="mb-4">
    <va-card-title>{{ $t('admin.sharedApps.setupTitle', { app: sharedApp.label }) }}</va-card-title>
    <va-card-content>
      <p class="mb-4">{{ $t('admin.sharedApps.activateDescription', { app: sharedApp.label }) }}</p>
      <va-stepper v-model="step" :steps="steps" controlsHidden>
        <template v-for="(item, index) in steps" #[`step-button-${index}`]="{ isActive }">
          <Link v-if="item.url && !item.disabled" :key="'link' + index" :href="item.url" :id="'step' + index">
            <va-button preset="secondary" :icon="item.icon" :color="item.completed ? 'success' : 'primary'">{{ item.label }}</va-button>
          </Link>
          <va-button
            v-else
            :key="'button' + index"
            :id="'step' + index"
            :preset="isActive ? 'primary' : 'secondary'"
            :icon="item.icon"
            :color="item.completed ? 'success' : 'primary'"
            :disabled="item.disabled"
            @click="step = index"
          >
            {{ item.label }}
          </va-button>
        </template>

        <template #step-content-0>
          <p class="mb-3">{{ $t('admin.sharedApps.versionStep', { app: sharedApp.label }) }}</p>
          <va-select
            v-model="versionForm.version"
            id="version"
            :label="$t('admin.sharedApps.version')"
            :options="setup.versions"
            text-by="name"
            value-by="id"
            placement="auto"
            class="mb-3"
            immediateValidation
            :error="!!versionForm.errors.version"
            :error-messages="versionForm.errors.version"
          />
          <va-button
            id="saveVersion"
            :disabled="versionForm.processing || versionForm.version === setup.version.id"
            @click="versionForm.put(base + '/version', { preserveScroll: true })"
          >
            {{ $t('admin.sharedApps.saveVersion') }}
          </va-button>
        </template>

        <template #step-content-1>
          <p class="mb-3">{{ $t('admin.sharedApps.serversStep', { app: sharedApp.label }) }}</p>
          <dl class="mb-3">
            <div v-for="server in serverList" :key="server.key" class="mb-1">
              <dt class="va-text-secondary">{{ server.label }}</dt>
              <dd :id="server.key + 'Server'">{{ server.name ?? $t('admin.sharedApps.notSet') }}</dd>
            </div>
          </dl>
          <p v-if="!setup.servers_saved" class="text-color-danger">{{ $t('admin.sharedApps.needServers') }}</p>
        </template>

        <template #step-content-2>
          <p>{{ $t('admin.sharedApps.configurationStep', { app: sharedApp.label }) }}</p>
        </template>

        <template #step-content-3>
          <p>{{ $t('admin.sharedApps.reviewStep') }}</p>
        </template>

        <template #step-content-4>
          <p class="mb-3">
            {{ $t('admin.sharedApps.testsDescription', { version: setup.version.name }) }}
            <va-badge class="ml-2" :color="gateColor(setup.gate)" :text="$t('admin.versions.testGate.' + setup.gate)" />
          </p>
          <va-alert v-if="setup.gate === 'stale'" id="testsStale" color="warning" class="mb-3" icon="warning">
            {{ $t('admin.sharedApps.testsStale') }}
          </va-alert>
          <va-alert v-if="setup.gate === 'bypassed' && setup.bypassed_by" color="info" class="mb-3">
            {{ $t('admin.sharedApps.testsBypassedBy', { name: setup.bypassed_by, reason: setup.bypass_reason }) }}
          </va-alert>
          <div>
            <va-button
              id="runTests"
              class="mr-2"
              :disabled="setup.gate === 'running' || testsForm.processing"
              @click="testsForm.post(base + '/tests')"
            >
              {{ $t('admin.versions.runTests') }}
            </va-button>
            <va-button id="bypassTests" class="mr-2" preset="secondary" color="danger" @click="showBypass = true">
              {{ $t('admin.versions.bypassTests') }}
            </va-button>
            <Link v-if="setup.run_url" :href="setup.run_url">
              <va-button preset="secondary">{{ $t('admin.versions.viewTestRun') }}</va-button>
            </Link>
          </div>
          <BypassTestsModal v-model="showBypass" :action="base + '/tests/bypass'" :version-name="setup.version.name" />
        </template>

        <template #step-content-5>
          <p class="mb-3">{{ $t('admin.sharedApps.activateStep', { app: sharedApp.label }) }}</p>
          <va-alert v-if="!setup.can_activate" id="activationBlocked" color="warning" icon="warning" class="mb-3">
            {{ $t('admin.sharedApps.activationBlocked', { version: setup.version.name }) }}
          </va-alert>
          <va-button
            id="activate"
            :disabled="!setup.can_activate || activateForm.processing"
            @click="activateForm.post(base + '/activate')"
          >
            {{ $t('admin.sharedApps.activate') }}
          </va-button>
        </template>
      </va-stepper>
    </va-card-content>
  </va-card>
</template>
