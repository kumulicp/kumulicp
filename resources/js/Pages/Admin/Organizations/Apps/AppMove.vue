<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { router, useForm } from '@inertiajs/vue3'
import AppsLayout from './AppsLayout.vue'
</script>

<template>
  <Head>
    <title>{{ $t('admin.apps.moveTitle') }}</title>
  </Head>
  <p class="mb-3">{{ $t('admin.apps.moveIntro') }}</p>

  <va-alert v-if="awaiting" id="awaiting" color="warning" icon="warning" class="mb-3">
    <div>{{ awaiting.message }}</div>
    <va-button id="confirmStep" size="small" class="mt-2" :disabled="confirmForm.processing" @click="confirmForm.post(base + '/confirm-step')">{{ $t('admin.apps.confirmStep') }}</va-button>
  </va-alert>

  <va-stepper v-model="step" :steps="stepItems" controlsHidden>
    <template v-for="(item, index) in stepItems" :key="'button' + index" #[`step-button-${index}`]="{ isActive }">
      <va-button
        :id="'moveStep' + index"
        :preset="isActive ? 'primary' : 'secondary'"
        :icon="item.icon"
        :color="item.completed ? 'success' : 'primary'"
        :disabled="item.disabled"
        @click="step = index"
      >
        {{ item.label }}
      </va-button>
    </template>

    <!-- The app server to move to -->
    <template #step-content-0>
      <p v-if="app.web_server" class="mb-3">{{ $t('admin.apps.moveCurrentServer', { server: app.web_server }) }}</p>
      <p v-if="targets.length === 0" id="noTargets">{{ $t('admin.apps.moveNoTargets') }}</p>
      <va-select
        v-else
        id="moveTarget"
        :model-value="target ? target.value : null"
        :label="$t('admin.apps.moveTarget')"
        :options="targets"
        text-by="text"
        value-by="value"
        class="mb-3"
        @update:model-value="chooseTarget"
      />
      <va-button id="moveStart" :disabled="!target" @click="step = 1">{{ $t('admin.apps.moveNext') }}</va-button>
    </template>

    <!-- The database: kept, or moved to one the new app server can reach, along with the app -->
    <template #step-content-1>
      <template v-if="target && steps">
        <p v-if="!steps.database.server" id="databaseNone" class="mb-3">{{ $t('admin.apps.moveDatabaseNone') }}</p>
        <va-alert v-else-if="running.database" id="databaseRunning" color="info" icon="info" class="mb-3">
          {{ $t('admin.apps.moveRunning', { description: running.database.description }) }}
        </va-alert>
        <template v-else>
          <va-radio
            v-model="databaseChoice"
            id="databaseChoice"
            :options="databaseChoices"
            value-by="value"
            class="mb-2"
          />
          <p v-if="steps.database.reachable" id="databaseReachable" class="mb-2">
            {{ $t('admin.apps.moveDatabaseReachable', { server: steps.database.server, target: target.text }) }}
          </p>
          <p v-else id="databaseKeepUnavailable" class="mb-2">
            {{ $t('admin.apps.moveDatabaseKeepUnavailable', { server: steps.database.server, target: target.text }) }}
          </p>
          <template v-if="databaseChoice === 'change'">
            <p v-if="steps.database.options.length === 0" id="databaseNoOptions" class="mb-3">
              {{ $t('admin.apps.moveDatabaseNoOptions', { target: target.text }) }}
            </p>
            <template v-else>
              <va-select
                v-model="webForm.database_server_id"
                id="databaseServer"
                :label="$t('admin.apps.moveDatabaseTarget', { target: target.text })"
                :options="steps.database.options"
                text-by="text"
                value-by="value"
                class="mb-3"
                :error="!!$page.props.errors.database_server_id"
                :error-messages="$page.props.errors.database_server_id"
              />
              <p class="mb-3"><strong>{{ $t('admin.apps.moveDatabaseWarning') }}</strong></p>
            </template>
          </template>
        </template>
        <va-button preset="secondary" class="mr-2" @click="step = 0">{{ $t('admin.apps.moveBack') }}</va-button>
        <va-button id="databaseNext" :disabled="!stepDone(1)" @click="step = 2">{{ $t('admin.apps.moveNext') }}</va-button>
      </template>
      <p v-else>{{ $t('admin.apps.moveChooseFirst') }}</p>
    </template>

    <!-- The sign-in server: kept, or changed and migrated -->
    <template #step-content-2>
      <template v-if="target && steps">
        <p v-if="!steps.sso.server || !app.supports_sso" id="ssoNone" class="mb-3">{{ $t('admin.apps.moveSsoNone') }}</p>
        <va-alert v-else-if="running.sso" id="ssoRunning" color="info" icon="info" class="mb-3">
          {{ $t('admin.apps.moveRunning', { description: running.sso.description }) }}
        </va-alert>
        <template v-else>
          <va-radio
            v-model="ssoChoice"
            :options="ssoChoices"
            value-by="value"
            class="mb-2"
          />
          <p v-if="!steps.sso.reachable" id="ssoKeepUnavailable" class="mb-2">
            {{ $t('admin.apps.moveSsoKeepUnavailable', { server: steps.sso.server, target: target.text }) }}
          </p>
          <template v-if="ssoChoice === 'change'">
            <p v-if="steps.sso.options.length === 0" id="ssoNoOptions" class="mb-3">{{ $t('admin.apps.moveSsoNoOptions', { target: target.text }) }}</p>
            <template v-else>
              <va-select
                v-model="webForm.sso_server_id"
                id="ssoServer"
                :label="$t('admin.apps.moveSsoTarget')"
                :options="steps.sso.options"
                text-by="text"
                value-by="value"
                class="mb-3"
                :error="!!$page.props.errors.sso_server_id"
                :error-messages="$page.props.errors.sso_server_id"
              />
              <p class="mb-3"><strong>{{ $t('admin.apps.moveSsoWarning') }}</strong></p>
            </template>
          </template>
        </template>
        <va-button preset="secondary" class="mr-2" @click="step = 1">{{ $t('admin.apps.moveBack') }}</va-button>
        <va-button id="ssoNext" :disabled="!stepDone(2)" @click="step = 3">{{ $t('admin.apps.moveNext') }}</va-button>
      </template>
      <p v-else>{{ $t('admin.apps.moveChooseFirst') }}</p>
    </template>

    <!-- The app server, once what its volume is to be is chosen -->
    <template #step-content-3>
      <template v-if="target && steps">
        <p class="mb-3">{{ $t('admin.apps.moveWebDescription') }}</p>
        <va-alert v-if="running.web" id="webRunning" color="info" icon="info" class="mb-3">
          {{ $t('admin.apps.moveRunning', { description: running.web.description }) }}
        </va-alert>
        <template v-else>
          <p class="mb-2">{{ $t('admin.apps.moveVolumeChoose', { target: target.text }) }}</p>
          <va-radio v-model="webForm.storage.volume" id="volume" :options="volumeOptions" value-by="value" class="mb-2" />
          <template v-if="webForm.storage.volume === 'existing'">
            <p class="mb-2">{{ $t('admin.apps.volumeHelp') }}</p>
            <va-input
              v-model="webForm.storage.claim"
              id="volumeClaim"
              class="mb-2"
              :label="$t('admin.apps.volumeClaim')"
              required-mark
              :error="!!$page.props.errors['storage.claim']"
              :error-messages="$page.props.errors['storage.claim']"
            />
          </template>
          <template v-if="webForm.storage.volume">
            <va-select
              v-if="storageFields.includes('access_mode')"
              v-model="webForm.storage.access_mode"
              class="mb-2"
              :label="$t('admin.apps.volumeAccessMode')"
              :options="steps.app.storage.access_modes"
              clearable
              :error="!!$page.props.errors['storage.access_mode']"
              :error-messages="$page.props.errors['storage.access_mode']"
            />
            <va-input
              v-if="storageFields.includes('storage_class')"
              v-model="webForm.storage.storage_class"
              class="mb-2"
              :label="$t('admin.apps.volumeStorageClass')"
              :error="!!$page.props.errors['storage.storage_class']"
              :error-messages="$page.props.errors['storage.storage_class']"
            />
            <va-input
              v-if="storageFields.includes('size')"
              v-model="webForm.storage.size"
              class="mb-2"
              type="number"
              :label="$t('admin.apps.volumeSize')"
              :error="!!$page.props.errors['storage.size']"
              :error-messages="$page.props.errors['storage.size']"
            />
          </template>
          <p class="mt-3 mb-3"><strong>{{ $t('admin.apps.moveWebWarning') }}</strong></p>
          <p v-if="!(stepDone(1) && stepDone(2))" id="moveNotReady" class="mb-3">{{ $t('admin.apps.moveNotReady') }}</p>
          <p v-if="app.status !== 'active'" class="mb-3">{{ $t('admin.apps.moveWebNotActive') }}</p>
          <va-button preset="secondary" class="mr-2" @click="step = 2">{{ $t('admin.apps.moveBack') }}</va-button>
          <va-button id="moveWeb" :disabled="!canMove" @click="startMove">{{ $t('admin.apps.moveWeb') }}</va-button>
        </template>
      </template>
      <p v-else>{{ $t('admin.apps.moveChooseFirst') }}</p>
    </template>
  </va-stepper>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(AppsLayout, () => page))
  },
  props: {
    organization: Object,
    app: Object,
    targets: Array,
    target: Object,
    // What each step needs, once an app server is chosen
    steps: Object,
    running: Object,
    awaiting: Object
  },
  data () {
    const base = '/admin/organizations/' + this.organization.id + '/apps/' + this.app.id
    const target = this.target ? this.target.value : null

    return {
      base,
      step: 0,
      timer: null,
      // The database and the sign-in server are kept when the new app server can reach them, and otherwise moved
      // with the app, to one it can reach
      databaseChoice: this.steps?.database?.reachable === false ? 'change' : 'keep',
      ssoChoice: this.steps?.sso?.reachable === false ? 'change' : 'keep',
      // What the volume is to be, and the servers to move with the app, chosen before the move can start
      webForm: useForm({
        web_server_id: target,
        move_target: target,
        database_server_id: null,
        sso_server_id: null,
        storage: {
          volume: null,
          claim: '',
          access_mode: null,
          storage_class: '',
          size: null
        }
      }),
      confirmForm: useForm({
        task_id: this.awaiting ? this.awaiting.task_id : null,
        move_target: target
      })
    }
  },
  computed: {
    stepItems () {
      const data = this.steps

      return [
        { label: this.$t('admin.apps.moveStepServer'), icon: 'dns', completed: !!this.target },
        { label: this.$t('admin.apps.moveStepDatabase'), icon: 'storage', disabled: !this.target, completed: !!data && this.stepDone(1) },
        { label: this.$t('admin.apps.moveStepSso'), icon: 'vpn_key', disabled: !this.target || !this.stepDone(1), completed: !!data && this.stepDone(2) },
        { label: this.$t('admin.apps.moveStepApp'), icon: 'apps', disabled: !this.target || !this.stepDone(2), completed: !!this.running.web }
      ]
    },
    // Where to be: the first step that is not done
    firstOpenStep () {
      if (!this.target || !this.steps) {
        return 0
      }

      if (!this.stepDone(1)) {
        return 1
      }

      return this.stepDone(2) ? 3 : 2
    },
    hasRunning () {
      return !!this.awaiting || Object.values(this.running).some(Boolean)
    },
    storageFields () {
      return this.steps ? this.steps.app.storage.fields : []
    },
    databaseChoices () {
      return [
        { text: this.$t('admin.apps.moveDatabaseKeep', { server: this.steps.database.server }), value: 'keep', disabled: !this.steps.database.reachable },
        { text: this.$t('admin.apps.moveDatabaseChange'), value: 'change' }
      ]
    },
    ssoChoices () {
      return [
        { text: this.$t('admin.apps.moveSsoKeep', { server: this.steps.sso.server }), value: 'keep', disabled: !this.steps.sso.reachable },
        { text: this.$t('admin.apps.moveSsoChange'), value: 'change' }
      ]
    },
    // A chart that can't be given a volume that exists can only start with a new one
    volumeOptions () {
      const options = [{ text: this.$t('admin.apps.volumeNew'), value: 'new' }]

      return this.steps && this.steps.app.storage.supports_existing
        ? [{ text: this.$t('admin.apps.volumeExisting'), value: 'existing' }, ...options]
        : options
    },
    canMove () {
      const storage = this.webForm.storage
      const chosen = storage.volume === 'new' || (storage.volume === 'existing' && !!storage.claim)

      return !!this.steps && this.stepDone(1) && this.stepDone(2) && chosen && this.app.status === 'active' && !this.running.web && !this.webForm.processing
    }
  },
  watch: {
    // After a step has been done, on to the next
    firstOpenStep (step) {
      this.step = step
    },
    // After a server was changed by a move of its own, the new one is the one to keep
    'steps.sso.server' () {
      this.ssoChoice = this.steps?.sso?.reachable === false ? 'change' : 'keep'
    },
    'steps.database.server' () {
      this.databaseChoice = this.steps?.database?.reachable === false ? 'change' : 'keep'
    },
    // A server is only sent along with the move when it is to be changed
    databaseChoice (choice) {
      if (choice === 'keep') {
        this.webForm.database_server_id = null
      }
    },
    ssoChoice (choice) {
      if (choice === 'keep') {
        this.webForm.sso_server_id = null
      }
    }
  },
  created () {
    this.step = this.firstOpenStep
  },
  mounted () {
    // What is being done takes a while: look again until it is
    this.timer = setInterval(() => {
      if (this.hasRunning) {
        router.reload({ only: ['app', 'steps', 'running', 'awaiting'] })
      }
    }, 8000)
  },
  beforeUnmount () {
    clearInterval(this.timer)
  },
  methods: {
    // Whether a step has nothing left to do: the database and the sign-in server are kept because the new app
    // server can reach them, or a server it can reach is chosen to move them to with the app. None is being
    // moved by itself
    stepDone (index) {
      const data = this.steps

      if (!data) {
        return false
      }

      if (index === 1) {
        return !this.running.database && (!data.database.server || (this.databaseChoice === 'keep' ? data.database.reachable : !!this.webForm.database_server_id))
      }

      return !this.running.sso && (!data.sso.server || !this.app.supports_sso || (this.ssoChoice === 'keep' ? data.sso.reachable : !!this.webForm.sso_server_id))
    },
    chooseTarget (value) {
      router.get(this.base + '/move', { target: value }, { preserveState: false, preserveScroll: true })
    },
    startMove () {
      this.webForm.transform(data => ({
        ...data,
        // Only what is to be changed is sent
        database_server_id: this.databaseChoice === 'change' ? data.database_server_id : null,
        sso_server_id: this.ssoChoice === 'change' ? data.sso_server_id : null,
        // Only what the chart reads is sent
        storage: Object.fromEntries(Object.entries(data.storage).filter(([key, value]) => key === 'volume' || (value !== null && value !== '')))
      })).post(this.base + '/move-web', { preserveScroll: true })
    }
  }
}
</script>
