<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import VersionLayout from './VersionLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.versions.editVersion') }} - Control Panel</title>
  </Head>
  <div class="app-profile">
    <div class="row justify-center">
      <va-button id="showEnableDisable" @click="showEnableDisable = !showEnableDisable">{{ version.toggle.label }}</va-button>
      <va-modal v-model="showEnableDisable" no-outside-dismiss no-padding size="small" class="p-0">
        <template #content="{ ok }">
          <form @submit.prevent="form.get('/admin/apps/'+app.slug+'/versions/'+version.version+'/'+version.toggle.state)">
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
    </div>
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
      <form @submit.prevent="form.post('/admin/apps/'+app.slug+'/versions/'+version.version)">
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
                <va-input v-model="form.chart_version"
                  :label="$t('admin.versions.helmChartVersion')"
                  id="chartVersion"
                  class="my-2"
                  immediateValidation
                  :error="$page.props.errors.chart_version"
                  :error-messages="$page.props.errors.chart_version"
                  />
                <va-input v-model="form.chart_name"
                  :label="$t('admin.versions.helmChartName')"
                  id="chartVersion"
                  class="my-2"
                  immediateValidation
                  :error="$page.props.errors.chart_name"
                  :error-messages="$page.props.errors.chart_name"
                  />
                <va-input v-model="form.helm_repo_name"
                  :label="$t('admin.versions.helmChartRepo')"
                  id="helmRepoName"
                  class="my-2"
                  immediateValidation
                  :error="$page.props.errors.helm_repo_name"
                  :error-messages="$page.props.errors.helm_repo_name"
                  />
                <va-select
                  v-model="form.helm_repo_secret_id"
                  :label="$t('admin.versions.helmChartRepoAuth')"
                  id="helmRepoSecretId"
                  class="my-2"
                  immediateValidation
                  clearable
                  :placeholder="$t('admin.versions.helmRepoSecretNone')"
                  :options="helm_repo_secrets"
                  text-by="name"
                  value-by="id"
                  :error="$page.props.errors.helm_repo_secret_id"
                  :error-messages="$page.props.errors.helm_repo_secret_id"
                  />
                <va-button id="addHelmRepoSecret" preset="secondary" size="small" class="my-2" @click="showAddHelmRepoSecret = !showAddHelmRepoSecret">{{ $t('admin.versions.addHelmRepoSecret') }}</va-button>
                <va-modal v-model="showAddHelmRepoSecret" no-outside-dismiss no-padding size="small" class="p-0">
                  <template #content="{ ok }">
                    <form @submit.prevent="helmRepoSecretForm.post('/admin/settings/repo-secrets', { onSuccess: () => { showAddHelmRepoSecret = false; helmRepoSecretForm.reset() } })">
                      <va-card-title class="m-0"> {{ $t('admin.versions.addHelmRepoSecret') }} </va-card-title>
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
                <va-select
                  v-model="form.pull_secret_id"
                  :label="$t('admin.versions.containerImageRegistry')"
                  id="pullSecretId"
                  class="my-2"
                  immediateValidation
                  clearable
                  :placeholder="$t('admin.versions.pullSecretNone')"
                  :options="pull_secrets"
                  text-by="name"
                  value-by="id"
                  :error="$page.props.errors.pull_secret_id"
                  :error-messages="$page.props.errors.pull_secret_id"
                  />
                <va-button id="addPullSecret" preset="secondary" size="small" class="my-2" @click="showAddPullSecret = !showAddPullSecret">{{ $t('admin.versions.addPullSecret') }}</va-button>
                <va-modal v-model="showAddPullSecret" no-outside-dismiss no-padding size="small" class="p-0">
                  <template #content="{ ok }">
                    <form @submit.prevent="pullSecretForm.post('/admin/settings/repo-secrets', { onSuccess: () => { showAddPullSecret = false; pullSecretForm.reset() } })">
                      <va-card-title class="m-0"> {{ $t('admin.versions.addPullSecret') }} </va-card-title>
                      <va-card-content class="m-0">
                        <va-input v-model="pullSecretForm.name"
                          immediateValidation
                          id="pullSecretName"
                          required-mark
                          :label="$t('admin.repoSecrets.name')"
                          class="mb-3"
                          :messages="$t('admin.repoSecrets.nameMessage')"
                          :error="$page.props.errors.name"
                          :error-messages="$page.props.errors.name" />
                        <va-input v-model="pullSecretForm.registry"
                          immediateValidation
                          id="pullSecretRegistry"
                          required-mark
                          :label="$t('admin.repoSecrets.registry')"
                          class="mb-3"
                          :messages="$t('admin.repoSecrets.registryMessage')"
                          :error="$page.props.errors.registry"
                          :error-messages="$page.props.errors.registry" />
                        <va-input v-model="pullSecretForm.username"
                          immediateValidation
                          id="pullSecretUsername"
                          :label="$t('admin.repoSecrets.username')"
                          class="mb-3"
                          :error="$page.props.errors.username"
                          :error-messages="$page.props.errors.username" />
                        <va-input v-model="pullSecretForm.password"
                          type="password"
                          immediateValidation
                          id="pullSecretPassword"
                          :label="$t('admin.repoSecrets.password')"
                          class="mb-3"
                          :messages="$t('admin.repoSecrets.passwordMessage')"
                          :error="$page.props.errors.password"
                          :error-messages="$page.props.errors.password" />
                      </va-card-content>
                      <va-card-actions align="right" class="">
                        <va-button color="textInverted" :disabled="pullSecretForm.processing" @click="ok">{{ $t('common.cancel') }}</va-button>
                        <va-button type="submit" :disabled="pullSecretForm.processing" id="submitPullSecret" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
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
</template>

<script>
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(VersionLayout, () => page))
  },
  props: {
    version: Object,
    errors: Object,
    announcements: Object,
    roles: Object,
    app: Object,
    can: Object,
    recommendations: Object,
    pull_secrets: Array,
    helm_repo_secrets: Array
  },
  data () {
    return {
      announcement_locations: [
        { value: 'none', text: this.$t('admin.versions.noNotifications') },
        { value: 'local', text: this.$t('admin.versions.local') },
        { value: 'remote', text: this.$t('admin.versions.remote') }
      ],
      showEnableDisable: false,
      showRecommendations: false,
      showAddPullSecret: false,
      showAddHelmRepoSecret: false,
      pullSecretForm: useForm({
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
        chart_version: this.version.chart_version,
        chart_name: this.version.chart_name,
        helm_repo_name: this.version.helm_repo_name,
        helm_repo_secret_id: this.version.helm_repo_secret_id,
        image_repo_name: this.version.image_repo_name,
        port: this.version.port,
        pull_secret_id: this.version.pull_secret_id,
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
</style>
