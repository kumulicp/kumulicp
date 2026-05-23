<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm, Link } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.plans.editPlan') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('admin.plans.editPlanTitle', { name: plan.name }) }}</va-card-title>
    <va-card-content>
      <div class="row justify-center">
        <Link v-if="!plan.archived" :href="'/admin/service/plans/'+plan.id+'/archive'"><va-button id="createUser">{{ $t('admin.plans.archivePlan') }}</va-button></Link>
        <Link v-else :href="'/admin/service/plans/'+plan.id+'/unarchive'"><va-button id="createUser">{{ $t('admin.plans.makePublic') }}</va-button></Link>
      </div>
      <form @submit.prevent="form.post('/admin/service/plans/'+plan.id)">
      <AdminSettings>
        <template #name></template>
        <template #settings>
          <va-input id="planName" v-model="form.name"
            :label="$t('admin.plans.name')"
            class="my-2"
            required-mark
            immediateValidation
            :error="$page.props.errors.name"
            :error-messages="$page.props.errors.name"
            />
          <va-checkbox v-model="form.default"
            :label="$t('admin.plans.default')"
            :messages="$t('admin.plans.defaultPlanCaption')"
            class="my-2"
            immediateValidation
            :error="$page.props.errors.default"
            :error-messages="$page.props.errors.default"
            />
          <va-select
            id="planType"
            v-model="form.type"
            :label="$t('admin.plans.planType')"
            class="my-2"
            value-by="value"
            text-by="text"
            immediateValidation
            :options="planTypes"
            :error="$page.props.errors.type"
            :error-messages="$page.props.errors.type"
          />
          <va-alert
            v-if="typeChanged"
            id="planTypeChangeWarning"
            color="warning"
            class="my-2"
          >
            {{ $t('admin.plans.planTypeChangeWarning') }}
          </va-alert>
          <va-input v-model="form.description"
            :label="$t('admin.plans.description')"
            class="my-2"
            immediateValidation
            :error="$page.props.errors.description"
            :error-messages="$page.props.errors.description"
            />
            <va-select
              v-model="form.org_type"
              :label="$t('admin.plans.organizationType')"
              value-by="value"
              text-by="name"
              immediateValidation
              :options="org_types"
              :error="$page.props.errors.org_type"
              :error-messages="$page.props.errors.org_type"
            />
          <va-checkbox v-model="form.payment_enabled"
            :label="$t('admin.plans.enablePayment')"
            class="my-2"
            immediateValidation
            :error="$page.props.errors.payment_enabled"
            :error-messages="$page.props.errors.payment_enabled"
            />
          <va-checkbox v-if="$page.props.flags.subOrganizations"
            v-model="form.suborganizations.enabled"
            :label="$t('admin.plans.enableSuborganizations')"
            class="my-2"
            immediateValidation
            :error="$page.props.errors.suborganizations_enabled"
            :error-messages="$page.props.errors.suborganizations_enabled"
            />
        </template>
      </AdminSettings>
      <va-list-separator class="my-1" fit />
      <AdminSettings>
        <template #name>{{ $t('admin.plans.displayedFeatures') }}</template>
        <template #description>{{ $t('admin.plans.displayedFeaturesDescription') }}</template>
        <template #settings>
          <template v-for="(feature, index) in plan.features" :key="index">
            <div class="row">
              <div class="flex flex-col lg4">
                <va-input v-model="form.displayed_features[index]['name']"
                  :label="$t('admin.plans.name')"
                  immediateValidation
                  :error="$page.props.errors.displayed_features"
                  :error-messages="$page.props.errors.displayed_features"
                  />
              </div>
              <div class="flex flex-col lg7">
                <va-input v-model="form.displayed_features[index]['description']"
                  :label="$t('admin.plans.description')"
                  immediateValidation
                  :error="$page.props.errors.displayed_features"
                  :error-messages="$page.props.errors.displayed_features"
                  />
              </div>
              <div class="flex lg1">
                <div class="content-center align-center">
                  <va-icon name="fa-x" color="danger" @click="removeFeature(index)" />
                </div>
              </div>
            </div>
          </template>
          <va-button @click="addNewFeature()">{{ $t('admin.plans.addFeature') }}</va-button>
        </template>
      </AdminSettings>
      <va-list-separator class="my-1" fit />
      <AdminSettings>
        <template #name>{{ $t('admin.plans.baseOptions') }}</template>
        <template #settings>
          <template v-if="form.type === 'package'">
            <va-input
              type="number"
              v-model="form.base.price"
              :label="$t('admin.plans.price')"
              class="my-2"
              :messages="$t('admin.plans.basePriceCaption')"
              immediateValidation
              min="0"
              step=".01"
            >
              <template #prependInner>
                $
              </template>
            </va-input>
            <va-input v-model="form.base.price_id"
              :label="$t('admin.plans.productID')"
              class="my-2"
              />
          </template>
          <va-input
            v-model="form.base.minimal_label"
            :label="$t('admin.plans.minimalUserLabel')"
            class="my-2"
            :messages="$t('admin.plans.minimalUserLabelCaption')"
          />
        </template>
      </AdminSettings>
      <template v-if="form.type === 'package'">
      <va-list-separator class="my-1" fit />
      <AdminSettings>
        <template #name>{{ $t('admin.plans.standardUserOptions') }}</template>
        <template #settings>
          <va-input
            type="number"
            v-model="form.standard.price"
            :label="$t('admin.plans.price')"
            class="my-2"
            :messages="$t('admin.plans.standardUserPriceCaption')"
            immediateValidation
            min="0"
            step=".01"
          >
            <template #prependInner>
              $
            </template>
          </va-input>
          <va-input
            type="number"
            v-model="form.standard.max"
            :label="$t('admin.plans.maxUsers')"
            class="my-2"
            :messages="$t('admin.plans.maxUsersCaption')"
            immediateValidation
          >
            <template #appendInner>
              {{ $t('admin.plans.users') }}
            </template>
          </va-input>
          <va-input v-model="form.standard.price_id"
            :label="$t('admin.plans.productID')"/>
          <va-input v-model="form.standard.storage"
            :label="$t('admin.plans.baseStorage')"
            class="my-2"
            :messages="$t('admin.plans.standardUserStorageCaption')"
            type="number"
            immediateValidation
            min="0"
          >
            <template #appendInner>
              GB
            </template>
          </va-input>
        </template>
      </AdminSettings>
      <va-list-separator class="my-1" fit />
      <AdminSettings>
        <template #name>{{ $t('admin.plans.basicUserOptions') }}</template>
        <template #settings>
          <va-input v-model="form.basic.name"
            :label="$t('admin.plans.name')"
            :messages="$t('admin.plans.basicUserNameCaption')"
            class="my-2"
            immediateValidation />
          <va-input
            type="number"
            v-model="form.basic.price"
            :label="$t('admin.plans.price')"
            :messages="$t('admin.plans.basicUserPriceCaption')"
            class="my-2"
            immediateValidation
            min="0"
            step=".01"
          >
            <template #prependInner>
              $
            </template>
          </va-input>
          <va-input
            type="number"
            v-model="form.basic.max"
            :label="$t('admin.plans.maxUsers')"
            :messages="$t('admin.plans.maxBasicUsersCaption')"
            class="my-2"
            immediateValidation
          >
            <template #appendInner>
              {{ $t('admin.plans.users') }}
            </template>
          </va-input>
          <va-input v-model="form.basic.price_id"
            :label="$t('admin.plans.productID')"
            class="my-2"
            immediateValidation />
          <va-input v-model="form.basic.storage"
            :label="$t('admin.plans.baseStorage')"
            :messages="$t('admin.plans.basicUserStorageCaption')"
            class="my-2"
            immediateValidation
            type="number"
            min="0"
          >
            <template #appendInner>
              GB
            </template>
          </va-input>
          <va-input v-model="form.basic.amount"
            :label="$t('admin.plans.usersPerPrice')+form.basic.price"
            :messages="$t('admin.plans.usersPerPriceCaption')"
            class="my-2"
            immediateValidation
            type="number"
            min="0"
          >
            <template #appendInner>
              GB
            </template>
          </va-input>
        </template>
      </AdminSettings>
      <va-list-separator class="my-1" fit />
      <AdminSettings>
        <template #name>{{ $t('admin.plans.additionalStorageOptions') }}</template>
        <template #settings>
          <va-input
            type="number"
            v-model="form.storage.price"
            :label="$t('admin.plans.price')"
            :messages="$t('admin.plans.additionalStoragePriceCaption')"
            class="my-2"
            immediateValidation
            min="0"
            step=".01"
          >
            <template #prependInner>
              $
            </template>
          </va-input>
          <va-input
            type="number"
            min="0"
            v-model="form.storage.max"
            :label="$t('admin.plans.maxAdditionalStorage')"
            :messages="$t('admin.plans.maxAdditionalStorageCaption')"
            class="my-2"
            immediateValidation
          >
            <template #appendInner>
              GB
            </template>
          </va-input>
          <va-input v-model="form.storage.price_id"
              :label="$t('admin.plans.price')"
            immediateValidation />
          <va-input v-model="form.storage.amount"
            :label="$t('admin.plans.quantity')"
            :messages="$t('admin.plans.additionalStorageQuantityCaption')"
            class="my-2"
            immediateValidation
            type="number"
            min="0"
          >
            <template #appendInner>
              GB
            </template>
          </va-input>
        </template>
      </AdminSettings>
      <va-list-separator v-if="$page.props.flags.emails && email_servers.length > 0" class="my-1" fit />
      <AdminSettings v-if="$page.props.flags.emails && email_servers.length > 0">
        <template #name>{{ $t('admin.plans.emailOptions') }}</template>
        <template #settings>
          <va-checkbox v-model="form.email_enabled"
            :label="$t('admin.plans.enableEmail')"
            class="my-2"
            immediateValidation
            :error="$page.props.errors.email_enabled"
            :error-messages="$page.props.errors.email_enabled"
            />
          <va-select
            v-model="form.email_server"
            :label="$t('admin.plans.emailServer')"
            class="my-2"
            value-by="value"
            text-by="text"
            immediateValidation
            :options="email_servers"
            :error="$page.props.errors.email_server"
            :error-messages="$page.props.errors.email_server"
          />
          <va-input
            type="number"
            v-model="form.email.price"
            :label="$t('admin.plans.price')"
            class="my-2"
            immediateValidation
            min="0"
            step=".01"
          >
            <template #prependInner>
              $
            </template>
          </va-input>
          <va-input
            type="number"
            min="0"
            v-model="form.email.max"
            :label="$t('admin.plans.maxEmails')"
            class="my-2"
            immediateValidation
          >
            <template #appendInner>
              {{ $t('admin.plans.users') }}
            </template>
          </va-input>
          <va-input v-model="form.email.price_id"
            :label="$t('admin.plans.productID')"
            immediateValidation />
          <va-input v-model="form.email.storage"
            :label="$t('admin.plans.baseStorage')"
            :messages="$t('admin.plans.emailStorageCaption')"
            class="my-2"
            immediateValidation
            type="number"
            min="0"
          >
            <template #appendInner>
              GB
            </template>
          </va-input>
        </template>
      </AdminSettings>
      <va-list-separator class="my-1" fit />
      <AdminSettings>
        <template #name>{{ $t('admin.plans.domains') }}</template>
        <template #settings>
          <va-checkbox v-model="form.domain_enabled"
            :label="$t('admin.plans.enableDomains')"
            :messages="$t('admin.plans.enableDomainsMessage')"
            class="my-2"
            immediateValidation
            :error="$page.props.errors.domain_enabled"
            :error-messages="$page.props.errors.domain_enabled"
            />
          <template v-if="form.domain_enabled === true">
            <va-checkbox v-model="form.domains.connect"
              :label="$t('admin.plans.connectDomains')"
              :messages="$t('admin.plans.connectDomainsMessage')"
              class="my-2"
              immediateValidation
              />
            <va-checkbox v-model="form.domains.register"
              :label="$t('admin.plans.registerDomains')"
              :messages="$t('admin.plans.registerDomainsMessage')"
              class="my-2"
              immediateValidation
              />
            <va-checkbox v-model="form.domains.transfer"
              :label="$t('admin.plans.transferDomains')"
              :messages="$t('admin.plans.transferDomainsMessage')"
              class="my-2"
              immediateValidation
              />
            <va-input
              type="number"
              :label="$t('admin.plans.maxDomains')"
              class="my-2"
              min="0"
              v-model="form.domain_max"
              immediateValidation
              :error="$page.props.errors.domain_max"
              :error-messages="$page.props.errors.domain_max"
              />
          </template>
        </template>
      </AdminSettings>
      <va-list-separator class="my-1" fit />
      <h1 class="va-h1">{{ $t('admin.plans.appSettings') }}</h1>
      <AdminSettings v-for="(app, index) in apps" :key="index">
        <template #name>{{ app.name }}</template>
        <template #settings>
          <va-select v-model="form['app_plans'][app.slug]['plans']"
            :label="$t('admin.plans.appPlan')"
            :options="app_plans[app.slug]"
            value-by="value"
            text-by="text"
            class="my-2"
            immediateValidation
            clearable
            :placeholder="$t('admin.plans.disabled')"
            :messages="$t('admin.plans.appPlanCaption')"
            />
          <va-input
            v-model="form['app_plans'][app.slug]['max']"
            :label="$t('admin.plans.maxActivations')"
            immediateValidation
            type="number"
            min="0"
            class="my-2"
            :messages="$t('admin.plans.maxActivationsCaption')"
            />
        </template>
      </AdminSettings>

        <va-button id="submit" type="submit" class="mr-2 mb-2" :disabled="form.processing">{{ $t('common.update') }}</va-button>
      </form>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    plan: Object,
    errors: Object,
    email_servers: Object,
    apps: Object,
    org_types: Array,
    control_panel: Object
  },
  data () {
    const appPlans = {}

    Object.values(this.apps).forEach((app) => {
      appPlans[app.slug] = []
      Object.values(app.plans).forEach((plan) => {
        appPlans[app.slug].push({ value: plan.id, text: plan.name })
      })
    })

    return {
      originalType: this.plan.type,
      features: this.plan.features,
      app_plans: appPlans,
      planTypes: [
        {
          text: this.$t('admin.plans.package'),
          value: 'package'
        },
        {
          text: this.$t('admin.plans.payPerApp'),
          value: 'app'
        }
      ],
      form: useForm({
        name: this.plan.name,
        description: this.plan.description,
        payment_enabled: this.plan.payment_enabled,
        type: this.plan.type,
        displayed_features: this.plan.features,
        default: this.plan.is_default,
        domain_enabled: this.plan.domain_enabled,
        domain_max: this.plan.domain_max,
        email_enabled: this.plan.email_enabled,
        email_server: this.plan.email_server,
        org_type: this.plan.org_type,
        domains: {
          connect: this.plan.domains.connect,
          register: this.plan.domains.register,
          transfer: this.plan.domains.transfer
        },
        suborganizations: {
          enabled: this.plan.settings.suborganizations.enabled || false
        },
        base: {
          price: this.plan.settings.base.price,
          price_id: this.plan.settings.base.price_id,
          minimal_label: this.plan.settings.base.minimal_label
        },
        standard: {
          price: this.plan.settings.standard.price,
          price_id: this.plan.settings.standard.price_id,
          storage: this.plan.settings.standard.storage,
          max: this.plan.settings.standard.max
        },
        basic: {
          price: this.plan.settings.basic.price,
          price_id: this.plan.settings.basic.price_id,
          storage: this.plan.settings.basic.storage,
          max: this.plan.settings.basic.max,
          name: this.plan.settings.basic.name,
          amount: this.plan.settings.basic.amount
        },
        storage: {
          price: this.plan.settings.storage.price,
          price_id: this.plan.settings.storage.price_id,
          amount: this.plan.settings.storage.amount,
          max: this.plan.settings.storage.max
        },
        email: {
          price: this.plan.settings.email.price,
          price_id: this.plan.settings.email.price_id,
          storage: this.plan.settings.email.storage,
          max: this.plan.settings.email.max
        },
        web_server: this.plan.web_server,
        app_plans: this.plan.app_plans
      })
    }
  },
  computed: {
    typeChanged () {
      return this.originalType !== null && this.form.type !== this.originalType
    }
  },
  methods: {
    addNewFeature () {
      this.form.displayed_features.push({
        name: '',
        description: ''
      })
      this.features.push({
        name: '',
        description: ''
      })
    },
    removeFeature (index) {
      this.features.splice(index, 1)
      this.form.displayed_features.splice(index, 1)
    }
  }
}
</script>

<style>
</style>
