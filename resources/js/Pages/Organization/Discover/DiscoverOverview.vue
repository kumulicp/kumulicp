<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AppCustomizations from '@/components/App/AppCustomizations.vue'
import AppSettings from '@/components/App/AppSettings.vue'
import CreditCard from '@/components/CreditCard.vue'
import PlanCard from '@/components/cards/PlanCard.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>App Plan Overview - Control Panel</title>
  </Head>
  <div class="discover-overview">
    <div class="row">
      <div class="flex xs12 lg9">
        <va-card class="mb-3">
          <va-card-title>{{ app.name }} Settings</va-card-title>
          <va-card-content>
            <template v-if="plan.payment_enabled && !hasDefaultPaymentMethod">
              <credit-card v-model:hasDefaultPaymentMethod="hasDefaultPaymentMethod" />
              <va-divider class="my-3" />
            </template>
            <form @submit.prevent="form.post('/discover/'+app.slug+'/plans/'+plan.id+'/activate')">
              <div v-if="app.parent_app" class="row">
                <div class="flex flex-col xs12 mb-2">
                  <div v-if="parent_apps.length == 0" class="mb-3">
                    <VaAlert
                      outline
                      icon="info"
                      color="primary"
                      class="mb-6"
                    >
                      This will be automatically activated along with {{ app.name }}.
                    </VaAlert>
                  </div>
                  <div v-else-if="parent_apps.length == 1" class="mb-3">
                    <VaAlert
                      outline
                      icon="info"
                      color="primary"
                      class="mb-6"
                    >
                      This will be an add-on to your {{ parent_apps[0]['text'] }} app.
                    </VaAlert>
                  </div>
                  <va-select v-else-if="parent_apps.length > 1"
                    label="Add to App"
                    class="mb-3"
                    v-model="form.parent_app"
                    :options="parent_apps"
                    value-by="value"
                    text-by="text"
                    immediateValidation
                    auto-select-first-option
                    />
                </div>
              </div>
              <div class="row">
                <div class="flex flex-col xs12 lg6 mb-2">
                  <va-input v-model="form.label"
                    label="Label"
                    messages="Helps identify this app from others. Useful if you have multiple websites for example."
                    id="label"
                    style="height:auto"
                    immediateValidation
                    :error="$page.props.errors.label"
                    :error-messages="$page.props.errors.label"
                  />
                </div>
                <div class="flex flex-col xs12 lg6 mb-2">
                  <va-select  v-model="form.organization"
                    v-if="organizations.length > 1"
                    label="Organization"
                    class="mb-3"
                    id="organization"
                    :options="organizations"
                    messages="There are multiple suborganizations you can assign this app to. Please select one."
                    value-by="id"
                    text-by="name"
                    immediateValidation
                    auto-select-first-option
                    />
                </div>
              </div>
              <div class="row">
                <div v-if="domains.length > 1" class="flex flex-col xs12 lg6 mb-2">
                  <va-select v-model="form.domain"
                    label="Domain"
                    :options="sortedDomains.sort((a,b) => a.value - b.value)"
                    text-by="text"
                    value-by="value"
                    placement="auto"
                    immediateValidation
                    :error="$page.props.errors.domain"
                    :error-messages="$page.props.errors.domain"
                  />
                </div>
                <div v-if="form.domain === 'new'" class="flex flex-col xs12 lg6 mb-2">
                  <va-input
                    v-model="form.subdomain"
                    label="Add Subdomain"
                    :messages="form.subdomain+'.'+listedParentDomains[form.parent_domain]"
                    immediateValidation
                    :error="$page.props.errors.subdomain"
                    :error-messages="$page.props.errors.subdomain"
                    placeholder="Type your subdomain"
                  >
                    <template #append>
                      <va-select
                        v-model="form.parent_domain"
                        :options="parent_domains"
                        text-by="text"
                        value-by="value"
                        :error="$page.props.errors.parent_domain"
                        :error-messages="$page.props.errors.parent_domain"
                        placeholder="Choose your primary domain"
                        auto-select-first-option
                        immediateValidation
                      >
                        <template #prepend>
                          <div class="mx-1">.</div>
                        </template>
                      </va-select>
                    </template>
                  </va-input>
                </div>
              </div>
              <h3 v-if="Object.keys(customizations).length > 0" class="va-h3 mb-3">Customizations</h3>
              <app-customizations :customizations="customizations" :customizations_form="form.customizations" @update:customizations="updateCustomizations($event)" />
              <h5 v-if="settings.length > 0" class="va-h5">App Settings</h5>
              <app-settings class="mb-3" :settings="settings" :settings_form="form.configurations" @update:settings="updateSettings($event)" />
              <template v-if="domains.length === 0 && parent_apps.length === 0 && (!plan.payment_enabled || hasDefaultPaymentMethod) && customizations.length === 0 && organizations.length <= 1">
                <div class="row m-5">
                  <div class="flex lg12 va-text-center mt-4">
                    <va-icon name="fa-thumbs-up" style="color: var(--va-list-item-label-caption-color)"  size="5rem" />
                  </div>
                  <div class="flex lg12 va-text-center mb-4">
                    <h2 class="va-h2" style="color: var(--va-list-item-label-caption-color)" >There's nothing left to do except activate!</h2>
                  </div>
                </div>
              </template>
              <va-button :disabled="plan.payment_enabled && ! hasDefaultPaymentMethod" type="submit">Activate</va-button>
            </form>
          </va-card-content>
        </va-card>
      </div>

      <div class="flex xs12 lg3">
        <plan-card :plan="plan" :app="app" />
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    stripe: Object,
    app: Object,
    plan: Object,
    customizations: Object,
    has_payment_method: Boolean,
    parent_apps: Object,
    parent_domains: Object,
    organizations: Object,
    domains: Object,
    default_label: String,
    organization: Object,
    settings: Object,
    subdomain_required: Boolean
  },
  data () {
    let parentApp = null
    if (Object.keys(this.parent_apps).length === 1) {
      parentApp = this.parent_apps[0].value
    }
    const customizationsForm = {}
    Object.keys(this.customizations).forEach((name) => {
      customizationsForm[name] = false
    })

    const parentDomains = {}

    for (const domain of Object.entries(this.parent_domains)) {
      parentDomains[domain.value] = domain.text
    }

    let processed_domain = null
    if (this.domains.length === 1) {
      processed_domain = this.domains[0].value
    } else if (this.domains.length === 0) {
      processed_domain = 'none'
    }

    const settings = {}
    for (const setting of Object.entries(this.settings)) {
      settings[setting.name] = setting.value
    }

    return {
      hasDefaultPaymentMethod: this.has_payment_method,
      sortedDomains: this.domains,
      listedParentDomains: parentDomains,
      form: useForm({
        label: this.default_label,
        organization: this.organization.id,
        parent_app: parentApp,
        domain: processed_domain,
        parent_domain: null,
        subdomain: '',
        customizations: customizationsForm,
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
    }
  }
}
</script>

<style lang="scss">
.va-input-wrapper__size-keeper {
  height: auto
}
</style>
