<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AppCustomizations from '@/components/App/AppCustomizations.vue'
import AppSettings from '@/components/App/AppSettings.vue'
import { useForm, Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>App Settings - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ app.label }} Settings </va-card-title>
    <va-card-content>
      <va-alert
        color="primary"
        border="top"
        border-color="primary"
        class="mb-5"
        v-if="tasks.length > 0"
      >
        <div v-for="(task, index) in tasks" :key="index">
          {{ task.description }} <a v-if="task.url" :href="task.url" class="ml-5">View docs for more info...</a>
        </div>
      </va-alert>
      <form @submit.prevent="form.put('/apps/'+app.id, {onSuccess: () => appUpdated()})">
        <div v-if="app.organization" class="row">
          <div class="flex flex-col xs12 lg6">
            <div class="va-title va-text-left text-color-primary">
              Organization
            </div>
            {{ app.organization.name }}
          </div>
        </div>
        <div v-if="app.admin_access" class="row">
          <div class="flex flex-col xs12 lg6">
            <va-input
                v-model="app.admin_password"
                :type="isPasswordVisible ? 'text' : 'password'"
                :label="useI18n().t('organization.apps.loginPassword')"
                :messages="useI18n().t('organization.apps.recommendPasswordChange')"
                placeholder="#########"
                immediateValidation
                @click-append-inner="isPasswordVisible = !isPasswordVisible"
                readonly
            >
                <template #appendInner>
                <va-icon
                    :name="isPasswordVisible ? 'visibility_off' : 'visibility'"
                    size="small"
                    color="primary"
                />
                </template>
            </va-input>
          </div>
        </div>
        <div v-if="app.parent_app" class="row">
          <div class="flex flex-col xs12 lg6">
            <div class="va-title va-text-left text-color-primary">
              Addon to
            </div>
            <Link :href="'/apps/'+app.parent_app.id+'/edit'" target="_blank">{{ app.parent_app.name }}</Link>
          </div>
          <div class="flex flex-col xs12 lg6">
            <div class="va-title va-text-left text-color-primary">
              Domain
            </div>
            <a :href="app.parent_app.address" target="_blank">{{ app.parent_app.domain }}</a>
          </div>
        </div>
        <va-divider v-if="app.parent_app || app.admin_access" class="my-2"/>
        <div class="row">
          <div class="flex flex-col xs12 lg6">
            <va-input
              v-model="form.label"
              label="Label"
              immediateValidation
              :error="$page.props.errors.label"
              :error-messages="$page.props.errors.label"
            />
          </div>
        </div>
        <div class="row">
          <div v-if="domains.length > 0" class="flex flex-col xs12 lg6 mb-2">
            <va-select
              v-model="form.domain"
              label="Domain"
              :options="domains"
              text-by="text"
              value-by="value"
              placement="auto"
              immediateValidation
              :error="$page.props.errors.domain"
              :error-messages="$page.props.errors.domain"
            />
          </div>
          <div v-if="can.add_custom_subdomain && form.domain === 'connection'" class="flex flex-col xs12 lg6 mb-2">
            <va-input v-model="form.subdomain"
              label="Subdomain"
              v-if="parent_domains.length > 0"
              :messages="form.parent_domain ? form.subdomain+'.'+listedParentDomains[form.parent_domain] : ''"
              immediateValidation
              :error="$page.props.errors.subdomain"
              :error-messages="$page.props.errors.subdomain"
              class="mb-3"
              placeholder="Type your subdomain"
            >
              <template #append>
                <va-select
                  v-model="form.parent_domain"
                  :options="parent_domains"
                  text-by="text"
                  value-by="value"
                  immediateValidation
                  :error="$page.props.errors.parent_domain"
                  :error-messages="$page.props.errors.parent_domain"
                  placeholder="Choose your primary domain"
                >
                  <template #prepend>
                    <div class="mx-1">.</div>
                  </template>
                </va-select>
              </template>
            </va-input>
            <p v-else class="text-color-danger">
              Register, transfer or connect an exist domain to create a subdomain
            </p>
          </div>
        </div>
        <app-customizations v-if="Object.keys(customizations).length > 0" :customizations="customizations" :customizations_form="form.customizations" @update:customizations="updateCustomizations($event)" />
        <h5 v-if="settings.length > 0" class="va-h5">App Settings</h5>
        <app-settings :settings="settings" :settings_form="form.configurations" @update:settings="updateSettings($event)" />
        <div class="row">
          <div class="flex flex-col xs12">
            <div>
              <va-button type="submit"
                id="submit"
                :disabled="form.processing || !can.update_app"
              >
                <template v-if="can.update_app">
                  Update
                </template>
                <template v-else>
                  Unable to update
                </template>
              </va-button>
            </div>
          </div>
        </div>
      </form>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    app: Object,
    customizations: Object,
    domains: Object,
    tasks: Object,
    errors: Object,
    can: Object,
    parent_domains: Object,
    settings: Object
  },
  data () {
    const customizationsForm = {}
    for (const [name, value] of Object.entries(this.customizations)) {
      customizationsForm[name] = value.status
    }

    const settings = {}
    for (const setting of Object.entries(this.settings)) {
      settings[setting.name] = setting.value
    }

    const parentDomains = {}

    for (const domain of Object.entries(this.parent_domains)) {
      parentDomains[domain.value] = domain.text
    }

    return {
      listedParentDomains: parentDomains,
      isPasswordVisible: false,
      form: useForm({
        domain: this.app.domain,
        customizations: customizationsForm,
        label: this.app.label,
        parent_domain: null,
        subdomain: '',
        configurations: settings
      })
    }
  },
  methods: {
    updateCustomizations (event) {
      this.form.customizations = event
    },
    updateSettings (event) {
      this.form.configurations = event
    },
    appUpdated () {
      this.form.domain = this.app.domain
      this.form.subdomain = ''
      this.form.parent_domain = null
    }
  }
}
</script>

<style></style>
