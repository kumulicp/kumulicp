<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import VersionLayout from './VersionLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import BypassTestsModal from '@/components/BypassTestsModal.vue'
import { Link, useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.versions.editVersion') }} - Control Panel</title>
  </Head>
  <div class="app-profile">
    <div class="row justify-center">
      <va-button v-if="version.toggle.state == 'disable' || version.test.can_enable" id="showEnableDisable" @click="showEnableDisable = !showEnableDisable">{{ version.toggle.label }}</va-button>
      <template v-else>
        <Link v-if="version.test.gate === 'running'" :href="'/admin/apps/'+app.slug+'/versions/'+version.id+'/tests/'+version.test.run_id"><va-button id="viewTestRun">{{ $t('admin.versions.viewTestRun') }}</va-button></Link>
        <va-button v-else id="runTests" :loading="testForm.processing" :disabled="testForm.processing" @click="testForm.post('/admin/apps/'+app.slug+'/versions/'+version.id+'/tests')">{{ $t('admin.versions.runTests') }}</va-button>
        <va-button v-if="version.test.can_bypass" id="bypassTests" class="ml-2" preset="secondary" color="danger" @click="showBypass = true">{{ $t('admin.versions.bypassTests') }}</va-button>
      </template>
      <va-button v-if="version.is_active && mass_upgrade.count > 0" id="massUpgrade" class="ml-2" color="success" @click="showMassUpgrade = true">{{ $t('admin.versions.massUpgradeButton', { count: mass_upgrade.count, version: version.version }) }}</va-button>
      <Link class="ml-2" :href="'/admin/apps/'+app.slug+'/versions/'+version.id+'/tests'"><va-button id="testResults" preset="secondary">{{ $t('admin.versions.testResults') }}</va-button></Link>
      <va-modal v-model="showEnableDisable" no-outside-dismiss no-padding size="small" class="p-0">
        <template #content="{ ok }">
          <form @submit.prevent="form.get('/admin/apps/'+app.slug+'/versions/'+version.id+'/'+version.toggle.state)">
            <va-card-title class="m-0"> {{ version.toggle.label }} {{ $t('admin.versions.version') }} </va-card-title>
            <va-card-content class="m-0">
              <div v-if="version.toggle.state == 'disable'">
                {{ $t('admin.versions.disablingVersionWarning') }}
              </div>
              <div v-if="version.toggle.state == 'enable'">
                {{ $t('admin.versions.enablingVersionWarning') }}
              </div>
            </va-card-content>
            <va-card-actions align="right" class="">
              <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
              <va-button type="submit" id="enableDisable" :disabled="form.processing" class="mr-2 mb-2">{{ version.toggle.label }}</va-button>
            </va-card-actions>
          </form>
        </template>
      </va-modal>
      <va-modal v-model="showMassUpgrade" no-outside-dismiss no-padding size="small" class="p-0">
        <template #content="{ ok }">
          <form @submit.prevent="massUpgradeForm.post('/admin/apps/'+app.slug+'/versions/'+version.id+'/mass-upgrade', { onSuccess: () => showMassUpgrade = false })">
            <va-card-title class="m-0">{{ $t('admin.versions.massUpgradeTitle') }}</va-card-title>
            <va-card-content class="m-0">
              {{ $t('admin.versions.massUpgradeWarning', { count: mass_upgrade.count, version: version.version }) }}
            </va-card-content>
            <va-card-actions align="right" class="">
              <va-button color="textInverted" :disabled="massUpgradeForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
              <va-button type="submit" id="confirmMassUpgrade" :disabled="massUpgradeForm.processing" class="mr-2 mb-2">{{ $t('admin.versions.massUpgradeConfirm') }}</va-button>
            </va-card-actions>
          </form>
        </template>
      </va-modal>
      <BypassTestsModal v-model="showBypass" :app="app" :version="version" />
    </div>
    <va-alert v-if="!version.is_active && !version.test.can_enable && version.test.gate !== 'running'" id="testRequired" color="warning" border="top" border-color="warning" class="my-3">
      {{ version.test.gate === 'stale' ? $t('admin.versions.testStale') : $t('admin.versions.testRequired') }}
    </va-alert>
    <va-alert v-if="version.test.gate === 'bypassed' && version.test.bypassed_by" id="testBypassed" color="warning" outline class="my-3">
      {{ $t('admin.versions.testBypassedBy', { name: version.test.bypassed_by }) }} {{ version.test.bypass_reason }}
    </va-alert>
    <va-alert v-if="version.is_active && version.test.gate === 'stale'" id="testChanged" color="info" outline class="my-3">
      {{ $t('admin.versions.testChangedEnabled') }}
    </va-alert>
    <va-alert v-if="recommendations.length > 0" outline>
      <va-accordion
        v-model="showRecommendations"
        class="lg12"
      >
        <va-collapse
          :header="$t('admin.versions.recommendations')"
        >
          <template #content>
            <div v-for="(recommendation, index) in recommendations" :key="index">
              <span class="font-bold">{{ recommendation.name }}:</span> {{ recommendation.value }}
            </div>
          </template>
        </va-collapse>
      </va-accordion>
    </va-alert>
    <div class="row">
      <div class="flex xs12 lg12">
      <form @submit.prevent="form.post('/admin/apps/'+app.slug+'/versions/'+version.id)">
        <AdminSettings v-if="can.helm_chart">
          <template #name>{{ $t('admin.versions.helmChart') }}</template>
          <template #settings>
                <va-input v-model="form.version"
                  :label="$t('admin.versions.version')"
                  id="version"
                  class="my-2"
                  required-mark
                  immediateValidation
                  :error="$page.props.errors.version"
                  :error-messages="$page.props.errors.version"
                  />
                <va-select
                  v-if="groups.length > 1"
                  v-model="form.version_group_id"
                  id="versionGroup"
                  :label="$t('admin.versions.group')"
                  :messages="$t('admin.versions.groupMoveCaption')"
                  class="my-2"
                  :disabled="version.is_active"
                  :options="groups"
                  value-by="id"
                  text-by="name"
                  :error="$page.props.errors.version_group_id"
                  :error-messages="$page.props.errors.version_group_id"
                  />
                <va-input v-model="form.chart_version"
                  :label="$t('admin.versions.helmChartVersion')"
                  id="chartVersion"
                  class="my-2"
                  immediateValidation
                  :error="$page.props.errors.chart_version"
                  :error-messages="$page.props.errors.chart_version"
                  />
                <div v-if="chart" id="chartSummary" class="my-3">
                  <div class="va-text-secondary mb-1" style="font-size: 0.85rem">{{ $t('admin.versions.chart') }}</div>
                  <va-card outlined>
                    <va-card-content>
                      <div class="row align-start justify-space-between">
                        <dl class="chart-details">
                          <dt>{{ $t('admin.versions.helmChartName') }}</dt>
                          <dd id="chartDetailName">{{ chart.chart_name || '—' }}</dd>
                          <dt>{{ $t('admin.versions.helmChartRepo') }}</dt>
                          <dd id="chartDetailRepo">{{ chart.helm_repo || '—' }}</dd>
                          <dt>{{ $t('admin.versions.helmChartRepoAuth') }}</dt>
                          <dd id="chartDetailLogin">{{ chart.helm_repo_secret || $t('admin.versions.helmRepoNone') }}</dd>
                        </dl>
                        <va-button id="editChart" preset="secondary" size="small" @click="openEditChart">{{ $t('admin.versions.editChart') }}</va-button>
                      </div>
                      <div class="va-text-secondary mt-2" style="font-size: 0.85rem">{{ $t('admin.versions.chartSharedNote') }}</div>
                    </va-card-content>
                  </va-card>
                </div>
                <va-select
                  v-model="form.image_registry_id"
                  :label="$t('admin.versions.containerImageRegistry')"
                  id="imageRegistryId"
                  class="my-2"
                  immediateValidation
                  required-mark
                  :options="image_registries"
                  text-by="name"
                  value-by="id"
                  :error="$page.props.errors.image_registry_id"
                  :error-messages="$page.props.errors.image_registry_id"
                  />
                <va-button id="addImageRegistry" preset="secondary" size="small" class="my-2" @click="showAddImageRegistry = !showAddImageRegistry">{{ $t('admin.versions.addImageRegistry') }}</va-button>
                <va-modal v-model="showAddImageRegistry" no-outside-dismiss no-padding size="small" class="p-0">
                  <template #content="{ ok }">
                    <form @submit.prevent="imageRegistryForm.post('/admin/settings/repo-secrets', { onSuccess: () => { showAddImageRegistry = false; imageRegistryForm.reset() } })">
                      <va-card-title class="m-0"> {{ $t('admin.versions.addImageRegistry') }} </va-card-title>
                      <va-card-content class="m-0">
                        <va-input v-model="imageRegistryForm.name"
                          immediateValidation
                          id="imageRegistryName"
                          required-mark
                          :label="$t('admin.repoSecrets.name')"
                          class="mb-3"
                          :messages="$t('admin.repoSecrets.nameMessage')"
                          :error="$page.props.errors.name"
                          :error-messages="$page.props.errors.name" />
                        <va-input v-model="imageRegistryForm.registry"
                          immediateValidation
                          id="imageRegistryRegistry"
                          required-mark
                          :label="$t('admin.repoSecrets.registry')"
                          class="mb-3"
                          :messages="$t('admin.repoSecrets.registryMessage')"
                          :error="$page.props.errors.registry"
                          :error-messages="$page.props.errors.registry" />
                        <va-input v-model="imageRegistryForm.username"
                          immediateValidation
                          id="imageRegistryUsername"
                          :label="$t('admin.repoSecrets.username')"
                          :messages="$t('admin.repoSecrets.usernameMessage')"
                          class="mb-3"
                          :error="$page.props.errors.username"
                          :error-messages="$page.props.errors.username" />
                        <va-input v-model="imageRegistryForm.password"
                          type="password"
                          immediateValidation
                          id="imageRegistryPassword"
                          :label="$t('admin.repoSecrets.password')"
                          class="mb-3"
                          :messages="$t('admin.repoSecrets.passwordMessage')"
                          :error="$page.props.errors.password"
                          :error-messages="$page.props.errors.password" />
                      </va-card-content>
                      <va-card-actions align="right" class="">
                        <va-button color="textInverted" :disabled="imageRegistryForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
                        <va-button type="submit" :disabled="imageRegistryForm.processing" id="submitImageRegistry" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
                      </va-card-actions>
                    </form>
                  </template>
                </va-modal>
                <va-input v-model="form.image_repo_name"
                  :label="$t('admin.versions.containerImageRepo')"
                  id="imageRepoName"
                  class="my-2"
                  immediateValidation
                  :error="$page.props.errors.image_repo_name"
                  :error-messages="$page.props.errors.image_repo_name"
                  />
                <div v-if="version.extra_images" id="extraImages" class="va-text-secondary mb-2" style="font-size: 0.85rem">
                  {{ $t('admin.versions.extraImages', { count: version.extra_images }) }}
                  <Link :href="'/admin/apps/' + app.slug + '/versions/' +version.id + '/images'">{{ $t('admin.versions.manageImages') }}</Link>
                </div>
                <va-input v-model="form.port"
                  type="number"
                  :label="$t('admin.versions.servicePort')"
                  id="port"
                  class="my-2"
                  immediateValidation
                  :messages="$t('admin.versions.servicePortMessage')"
                  :error="$page.props.errors.port"
                  :error-messages="$page.props.errors.port"
                  />
          </template>
        </AdminSettings>
        <va-list-separator v-if="can.helm_chart" class="my-1" fit />
        <AdminSettings>
          <template #name>{{ $t('admin.announcement.title') }}</template>
          <template #settings>
                <va-select
                  v-model="form.announcement_location"
                  :label="$t('admin.versions.announcementLocation')"
                  id="announcementLocation"
                  class="my-2"
                  immediateValidation
                  :options="announcement_locations"
                  text-by="text"
                  value-by="value"
                  :error="$page.props.errors.announcement_location"
                  :error-messages="$page.props.errors.announcement_location"
                />
                <va-select
                  v-if="form.announcement_location == 'local'"
                  v-model="form.announcement_id"
                  :label="$t('admin.versions.announcementName')"
                  id="announcementId"
                  class="my-2"
                  immediateValidation
                  :options="announcements"
                  text-by="text"
                  value-by="value"
                  :error="$page.props.errors.announcement_id"
                  :error-messages="$page.props.errors.announcement_id"
                />
                <va-input
                  v-if="form.announcement_location == 'remote'"
                  v-model="form.announcement_url"
                  :label="$t('admin.versions.announcementUrl')"
                  id="announcementUrl"
                  class="my-2"
                  immediateValidation
                  :error="$page.props.errors.announcement_url"
                  :error-messages="$page.props.errors.announcement_url"
                />
          </template>
        </AdminSettings>
        <va-list-separator class="my-1" fit />
        <AdminSettings>
          <template #name>{{ $t('admin.apps.appSettings') }}</template>
          <template #settings>
                <va-input v-model="form.admin_path"
                  :label="$t('admin.versions.adminPath')"
                  id="adminPath"
                  class="my-2"
                  immediateValidation
                  :error="$page.props.errors.admin_path"
                  :error-messages="$page.props.errors.admin_path"
                  />
                <va-select
                  v-model="form.default_admin_roles"
                  :label="$t('admin.versions.defaultAdminRoles')"
                  id="defaultAdminRoles"
                  class="my-2"
                  immediateValidation
                  value-by="value"
                  text-by="text"
                  multiple
                  searchable
                  clearable
                  :options="roles"
                  :error="$page.props.errors.default_admin_roles"
                  :error-messages="$page.props.errors.default_admin_roles"
                />
                <va-select
                  v-model="form.default_user_roles"
                  :label="$t('admin.versions.defaultUserRoles')"
                  id="defaultUserRoles"
                  class="my-2"
                  immediateValidation
                  value-by="value"
                  text-by="text"
                  multiple
                  searchable
                  clearable
                  :options="roles"
                  :error="$page.props.errors.default_user_roles"
                  :error-messages="$page.props.errors.default_user_roles"
                />
          </template>
        </AdminSettings>
        <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">{{ $t('common.submit') }}</va-button>
      </form>
      </div>
    </div>
  </div>
  <va-modal v-if="chart" v-model="showEditChart" no-outside-dismiss no-padding size="small" class="p-0">
    <template #content="{ ok }">
      <form @submit.prevent="chartForm.put('/admin/apps/' + app.slug + '/charts/' + chart.id, { onSuccess: () => showEditChart = false })">
        <va-card-title class="m-0">{{ $t('admin.versions.editChart') }}</va-card-title>
        <va-card-content class="m-0">
          <div class="va-text-secondary mb-3" style="font-size: 0.85rem">{{ $t('admin.versions.chartSharedNote') }}</div>
          <va-input v-model="chartForm.chart_name"
            :label="$t('admin.versions.helmChartName')"
            id="chartName"
            class="mb-3"
            immediateValidation
            :error="$page.props.errors.chart_name"
            :error-messages="$page.props.errors.chart_name"
            />
          <va-input v-model="chartForm.helm_repo"
            :label="$t('admin.versions.helmChartRepo')"
            :messages="$t('admin.versions.helmChartRepoMessage')"
            id="helmRepo"
            class="mb-3"
            immediateValidation
            :error="$page.props.errors.helm_repo"
            :error-messages="$page.props.errors.helm_repo"
            />
          <va-select
            v-model="chartForm.helm_repo_secret_id"
            :label="$t('admin.versions.helmChartRepoAuth')"
            id="helmRepoSecretId"
            class="mb-1"
            immediateValidation
            clearable
            :placeholder="$t('admin.versions.helmRepoNone')"
            :options="helm_repo_secrets"
            text-by="name"
            value-by="id"
            :error="$page.props.errors.helm_repo_secret_id"
            :error-messages="$page.props.errors.helm_repo_secret_id"
            />
          <va-button id="addHelmRepo" preset="secondary" size="small" @click="showAddHelmRepo = true">{{ $t('admin.versions.addHelmRepo') }}</va-button>
        </va-card-content>
        <va-card-actions align="right" class="">
          <va-button color="textInverted" :disabled="chartForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
          <va-button type="submit" id="submitChart" :disabled="chartForm.processing" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
        </va-card-actions>
      </form>
    </template>
  </va-modal>
  <va-modal v-model="showAddHelmRepo" no-outside-dismiss no-padding size="small" class="p-0">
    <template #content="{ ok }">
      <form @submit.prevent="helmRepoSecretForm.post('/admin/settings/repo-secrets', { onSuccess: () => { showAddHelmRepo = false; helmRepoSecretForm.reset() } })">
        <va-card-title class="m-0"> {{ $t('admin.versions.addHelmRepo') }} </va-card-title>
        <va-card-content class="m-0">
          <va-input v-model="helmRepoSecretForm.name"
            immediateValidation
            id="helmRepoSecretName"
            required-mark
            :label="$t('admin.repoSecrets.name')"
            class="mb-3"
            :messages="$t('admin.repoSecrets.nameMessage')"
            :error="$page.props.errors.name"
            :error-messages="$page.props.errors.name" />
          <va-input v-model="helmRepoSecretForm.registry"
            immediateValidation
            id="helmRepoSecretRegistry"
            required-mark
            :label="$t('admin.repoSecrets.registry')"
            class="mb-3"
            :messages="$t('admin.repoSecrets.registryMessage')"
            :error="$page.props.errors.registry"
            :error-messages="$page.props.errors.registry" />
          <va-input v-model="helmRepoSecretForm.username"
            immediateValidation
            id="helmRepoSecretUsername"
            :label="$t('admin.repoSecrets.username')"
            :messages="$t('admin.repoSecrets.usernameMessage')"
            class="mb-3"
            :error="$page.props.errors.username"
            :error-messages="$page.props.errors.username" />
          <va-input v-model="helmRepoSecretForm.password"
            type="password"
            immediateValidation
            id="helmRepoSecretPassword"
            :label="$t('admin.repoSecrets.password')"
            class="mb-3"
            :messages="$t('admin.repoSecrets.passwordMessage')"
            :error="$page.props.errors.password"
            :error-messages="$page.props.errors.password" />
        </va-card-content>
        <va-card-actions align="right" class="">
          <va-button color="textInverted" :disabled="helmRepoSecretForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
          <va-button type="submit" :disabled="helmRepoSecretForm.processing" id="submitHelmRepoSecret" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
        </va-card-actions>
      </form>
    </template>
  </va-modal>
</template>

<script>
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(VersionLayout, () => page))
  },
  props: {
    version: Object,
    groups: {
      type: Array,
      default: () => []
    },
    errors: Object,
    announcements: Object,
    roles: Object,
    app: Object,
    chart: Object,
    can: Object,
    recommendations: Object,
    mass_upgrade: Object,
    image_registries: Array,
    helm_repo_secrets: Array
  },
  methods: {
    // Starts from what the chart has, not from what an earlier edit left unsaved
    openEditChart () {
      this.chartForm.defaults({
        chart_name: this.chart.chart_name,
        helm_repo: this.chart.helm_repo,
        helm_repo_secret_id: this.chart.helm_repo_secret_id
      }).reset()
      this.showEditChart = true
    }
  },
  data () {
    return {
      announcement_locations: [
        { value: 'none', text: this.$t('admin.versions.noNotifications') },
        { value: 'local', text: this.$t('admin.versions.local') },
        { value: 'remote', text: this.$t('admin.versions.remote') }
      ],
      showEnableDisable: false,
      showBypass: false,
      showMassUpgrade: false,
      testForm: useForm({}),
      massUpgradeForm: useForm({}),
      showRecommendations: false,
      showAddImageRegistry: false,
      showAddHelmRepo: false,
      showEditChart: false,
      chartForm: useForm({
        chart_name: this.chart?.chart_name,
        helm_repo: this.chart?.helm_repo,
        helm_repo_secret_id: this.chart?.helm_repo_secret_id
      }),
      imageRegistryForm: useForm({
        type: 'image',
        name: '',
        registry: '',
        username: '',
        password: ''
      }),
      helmRepoSecretForm: useForm({
        type: 'helm',
        name: '',
        registry: '',
        username: '',
        password: ''
      }),
      form: useForm({
        id: this.version.id,
        version: this.version.version,
        admin_path: this.version.admin_path,
        version_group_id: this.version.version_group_id,
        chart_version: this.version.chart_version,
        image_repo_name: this.version.image_repo_name,
        port: this.version.port,
        image_registry_id: this.version.image_registry_id,
        announcement_location: this.version.announcement_location,
        announcement_id: this.version.announcement_id ? this.version.announcement_id : '',
        default_admin_roles: this.version.default_admin_roles,
        default_user_roles: this.version.default_user_roles,
        announcement_url: this.version.announcement_url
      })
    }
  }
}
</script>

<style>
.chart-details {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 0.25rem 1.5rem;
  margin: 0;
}

.chart-details dt {
  color: var(--va-secondary);
}

.chart-details dd {
  margin: 0;
  word-break: break-all;
}
</style>
