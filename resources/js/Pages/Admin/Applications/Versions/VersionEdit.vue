<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import VersionLayout from './VersionLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Edit Version - Control Panel</title>
  </Head>
  <div class="app-profile">
    <div class="row justify-center">
      <va-button id="showEnableDisable" @click="showEnableDisable = !showEnableDisable">{{ version.toggle.label }}</va-button>
      <va-modal v-model="showEnableDisable" no-outside-dismiss no-padding size="small" class="p-0">
        <template #content="{ ok }">
          <form @submit.prevent="form.get('/admin/apps/'+app.slug+'/versions/'+version.version+'/'+version.toggle.state)">
            <va-card-title class="m-0"> {{ version.toggle.label }} Version </va-card-title>
            <va-card-content class="m-0">
              <div v-if="version.toggle.state == 'disable'">
                {{ t('admin.versions.disablingVersionWarning') }}
              </div>
              <div v-if="version.toggle.state == 'enable'">
                {{ t('admin.versions.enablingVersionWarning') }}
              </div>
            </va-card-content>
            <va-card-actions align="right" class="">
              <va-button color="textInverted" :disabled="form.processing" @click="ok">{{ t('form.cancel') }}</va-button>
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
          :header="t('admin.versions.recommendations')"
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
          <template #name>{{ t('admin.versions.helmChart') }}</template>
          <template #settings>
                <va-input v-model="form.version"
                  :label="t('admin.versions.version')"
                  id="version"
                  class="my-2"
                  required-mark
                  immediateValidation
                  :error="$page.props.errors.version"
                  :error-messages="$page.props.errors.version"
                  />
                <va-input v-model="form.chart_version"
                  :label="t('admin.versions.helmChartVersion')"
                  id="chartVersion"
                  class="my-2"
                  immediateValidation
                  :error="$page.props.errors.chart_version"
                  :error-messages="$page.props.errors.chart_version"
                  />
                <va-input v-model="form.chart_name"
                  :label="t('admin.versions.helmChartName')"
                  id="chartVersion"
                  class="my-2"
                  immediateValidation
                  :error="$page.props.errors.chart_name"
                  :error-messages="$page.props.errors.chart_name"
                  />
                <va-input v-model="form.helm_repo_name"
                  :label="t('admin.versions.helmChartRepo')"
                  id="helmRepoName"
                  class="my-2"
                  immediateValidation
                  :error="$page.props.errors.helm_repo_name"
                  :error-messages="$page.props.errors.helm_repo_name"
                  />
                <va-input v-model="form.image_registry"
                  :label="t('admin.versions.containerImageRegistry')"
                  id="imageRegistry"
                  class="my-2"
                  immediateValidation
                  :error="$page.props.errors.image_registry"
                  :error-messages="$page.props.errors.image_registry"
                  />
                <va-input v-model="form.image_repo_name"
                  :label="t('admin.versions.containerImageRepo')"
                  id="imageRepoName"
                  class="my-2"
                  immediateValidation
                  :error="$page.props.errors.image_repo_name"
                  :error-messages="$page.props.errors.image_repo_name"
                  />
          </template>
        </AdminSettings>
        <va-list-separator v-if="can.helm_chart" class="my-1" fit />
        <AdminSettings>
          <template #name>{{ t('admin.announcement.title') }}</template>
          <template #settings>
                <va-select
                  v-model="form.announcement_location"
                  :label="t('admin.versions.announcementLocation')"
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
                  :label="t('admin.versions.announcementName')"
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
                  :label="t('admin.versions.announcementUrl')"
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
          <template #name>{{ t('admin.apps.appSettings') }}</template>
          <template #settings>
                <va-input v-model="form.admin_path"
                  :label="t('admin.versions.adminPath')"
                  id="adminPath"
                  class="my-2"
                  immediateValidation
                  :error="$page.props.errors.admin_path"
                  :error-messages="$page.props.errors.admin_path"
                  />
                <va-select
                  v-model="form.default_admin_roles"
                  :label="t('admin.versions.defaultAdminRoles')"
                  id="defaultAdminRoles"
                  class="my-2"
                  immediateValidation
                  value-by="value"
                  text-by="text"
                  multiple
                  searchable
                  clearable
                  :options="groups"
                  :error="$page.props.errors.default_admin_roles"
                  :error-messages="$page.props.errors.default_admin_roles"
                />
                <va-select
                  v-model="form.default_user_roles"
                  :label="t('admin.versions.defaultUserRoles')"
                  id="defaultUserRoles"
                  class="my-2"
                  immediateValidation
                  value-by="value"
                  text-by="text"
                  multiple
                  searchable
                  clearable
                  :options="groups"
                  :error="$page.props.errors.default_user_roles"
                  :error-messages="$page.props.errors.default_user_roles"
                />
          </template>
        </AdminSettings>
        <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">{{ t('form.submit') }}</va-button>
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
    groups: Object,
    app: Object,
    can: Object,
    recommendations: Object
  },
  data () {
    return {
      announcement_locations: [
        { value: 'none', text: useI18n().t('admin.versions.noNotifications') },
        { value: 'local', text: useI18n().t('admin.versions.local') },
        { value: 'remote', text: useI18n().t('admin.versions.remote') }
      ],
      showEnableDisable: false,
      showRecommendations: false,
      form: useForm({
        id: this.version.id,
        version: this.version.version,
        admin_path: this.version.admin_path,
        chart_version: this.version.chart_version,
        chart_name: this.version.chart_name,
        helm_repo_name: this.version.helm_repo_name,
        image_repo_name: this.version.image_repo_name,
        image_registry: this.version.image_registry,
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
