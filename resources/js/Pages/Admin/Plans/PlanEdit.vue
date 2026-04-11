<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm, Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Edit Plan - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>Edit {{ plan.name }} Plan</va-card-title>
    <va-card-content>
      <div class="row justify-center">
        <Link v-if="!plan.archived" :href="'/admin/service/plans/'+plan.id+'/archive'"><va-button id="createUser">Archive Plan</va-button></Link>
        <Link v-else :href="'/admin/service/plans/'+plan.id+'/unarchive'"><va-button id="createUser">Make Public</va-button></Link>
      </div>
      <form @submit.prevent="form.post('/admin/service/plans/'+plan.id)">
      <AdminSettings>
        <template #name></template>
        <template #settings>
          <va-input v-model="form.name"
            :label="t('admin.plans.name')"
            class="my-2"
            required-mark
            immediateValidation
            :error="$page.props.errors.name"
            :error-messages="$page.props.errors.name"
            />
          <va-checkbox v-model="form.default"
            :label="t('admin.plans.default')"
            messages="Checking this box will override the current default plan if another plan is set as your default"
            class="my-2"
            immediateValidation
            :error="$page.props.errors.default"
            :error-messages="$page.props.errors.default"
            />
          <va-select
            v-model="form.type"
            :label="t('admin.plans.planType')"
            class="my-2"
            value-by="value"
            text-by="text"
            immediateValidation
            :options="planTypes"
            :error="$page.props.errors.type"
            :error-messages="$page.props.errors.type"
          />
          <va-input v-model="form.description"
            :label="t('admin.plans.description')"
            class="my-2"
            immediateValidation
            :error="$page.props.errors.description"
            :error-messages="$page.props.errors.description"
            />
            <va-select
              v-model="form.org_type"
              :label="t('admin.plans.organizationType')"
              value-by="value"
              text-by="name"
              immediateValidation
              :options="org_types"
              :error="$page.props.errors.org_type"
              :error-messages="$page.props.errors.org_type"
            />
          <va-checkbox v-model="form.payment_enabled"
            :label="t('admin.plans.enablePayment')"
            class="my-2"
            immediateValidation
            :error="$page.props.errors.payment_enabled"
            :error-messages="$page.props.errors.payment_enabled"
            />
          <va-checkbox v-model="form.suborganizations.enabled"
            :label="t('admin.plans.enableSuborganizations')"
            class="my-2"
            immediateValidation
            :error="$page.props.errors.suborganizations_enabled"
            :error-messages="$page.props.errors.suborganizations_enabled"
            />
        </template>
      </AdminSettings>
      <va-list-separator class="my-1" fit />
      <AdminSettings>
        <template #name>Displayed Features</template>
        <template #description>Show additional features</template>
        <template #settings>
          <template v-for="(feature, index) in plan.features" :key="index">
            <div class="row">
              <div class="flex flex-col lg4">
                <va-input v-model="form.displayed_features[index]['name']"
                  :label="t('admin.plans.name')"
                  immediateValidation
                  :error="$page.props.errors.displayed_features"
                  :error-messages="$page.props.errors.displayed_features"
                  />
              </div>
              <div class="flex flex-col lg7">
                <va-input v-model="form.displayed_features[index]['description']"
                  :label="t('admin.plans.description')"
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
          <va-button @click="addNewFeature()">Add Feature</va-button>
        </template>
      </AdminSettings>
      <va-list-separator class="my-1" fit />
      <AdminSettings>
        <template #name>Base Options</template>
        <template #settings>
          <va-input
            type="number"
            v-model="form.base.price"
            :label="t('admin.plans.price')"
            class="my-2"
            messages="Initial price to activate app. Additional user pricing will be added on top of this."
            immediateValidation
            min="0"
            step=".01"
          >
            <template #prependInner>
              $
            </template>
          </va-input>
          <va-input v-model="form.base.price_id"
            :label="t('admin.plans.productID')"
            class="my-2"
            />
          <va-input
            v-model="form.base.minimal_label"
            :label="t('admin.plans.minimalUserLabel')"
            class="my-2"
            messages="Reserved for users that aren't directly involved in the organization such as constituents, congregants or customers"
          />
        </template>
      </AdminSettings>
      <va-list-separator class="my-1" fit />
      <AdminSettings>
        <template #name>Standard User Options</template>
        <template #settings>
          <va-input
            type="number"
            v-model="form.standard.price"
            :label="t('admin.plans.price')"
            class="my-2"
            messages="Increases price for organization per standard user"
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
            :label="t('admin.plans.maxUsers')"
            class="my-2"
            messages="The maximum standard users that organizations can have permission to access this app"
            immediateValidation
          >
            <template #appendInner>
              users
            </template>
          </va-input>
          <va-input v-model="form.standard.price_id"
            :label="t('admin.plans.productID')"/>
          <va-input v-model="form.standard.storage"
            :label="t('admin.plans.baseStorage')"
            class="my-2"
            messages="Initial storage per user. This can increase by adding additional storage"
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
        <template #name>Basic User Options</template>
        <template #settings>
          <va-input v-model="form.basic.name"
            :label="t('admin.plans.name')"
            messages="For example, for charities, you might want to call basic users Volunteers"
            class="my-2"
            immediateValidation />
          <va-input
            type="number"
            v-model="form.basic.price"
            :label="t('admin.plans.price')"
            messages="Increases price for organization per basic user"
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
            :label="t('admin.plans.maxUsers')"
            messages="The maximum basic users that organizations can have permission to access this app"
            class="my-2"
            immediateValidation
          >
            <template #appendInner>
              users
            </template>
          </va-input>
          <va-input v-model="form.basic.price_id"
            :label="t('admin.plans.productID')"
            class="my-2"
            immediateValidation />
          <va-input v-model="form.basic.storage"
            :label="t('admin.plans.baseStorage')"
            messages="Initial storage per user. This can increase by adding additional storage"
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
            :label="t('admin.plans.usersPerPrice')+form.basic.price"
            messages="Basic users are batched at a cheaper price. (Ex: 10 users per $5)"
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
        <template #name>Additional Storage Options</template>
        <template #settings>
          <va-input
            type="number"
            v-model="form.storage.price"
            :label="t('admin.plans.price')"
            messages="Increases price for organization per basic user"
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
            :label="t('admin.plans.maxAdditionalStorage')"
            messages="The maximum amount of additional storage a organization can have. (If you set the storage amount below to 5GB and set the max to 5, your organizations can only have 25GB)"
            class="my-2"
            immediateValidation
          >
            <template #appendInner>
              GB
            </template>
          </va-input>
          <va-input v-model="form.storage.price_id"
              :label="t('admin.plans.price')"
            immediateValidation />
          <va-input v-model="form.storage.amount"
            :label="t('admin.plans.quantity')"
            messages="The amount of GB that organizations can increment by (Ex: 5, 10, 15, 20)"
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
      <va-list-separator v-if="email_servers.length > 0" class="my-1" fit />
      <AdminSettings v-if="email_servers.length > 0">
        <template #name>Email Options</template>
        <template #settings>
          <va-checkbox v-model="form.email_enabled"
            :label="t('admin.plans.enableEmail')"
            class="my-2"
            immediateValidation
            :error="$page.props.errors.email_enabled"
            :error-messages="$page.props.errors.email_enabled"
            />
          <va-select
            v-model="form.email_server"
            :label="t('admin.plans.emailServer')"
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
            :label="t('admin.plans.price')"
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
            :label="t('admin.plans.maxEmails')"
            class="my-2"
            immediateValidation
          >
            <template #appendInner>
              users
            </template>
          </va-input>
          <va-input v-model="form.email.price_id"
            :label="t('admin.plans.productID')"
            immediateValidation />
          <va-input v-model="form.email.storage"
            :label="t('admin.plans.baseStorage')"
            messages="The amount storage per email account"
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
        <template #name>Domains</template>
        <template #settings>
          <va-checkbox v-model="form.domain_enabled"
            :label="t('admin.plans.enableDomains')"
            :messages="t('admin.plans.enableDomainsMessage')"
            class="my-2"
            immediateValidation
            :error="$page.props.errors.domain_enabled"
            :error-messages="$page.props.errors.domain_enabled"
            />
          <template v-if="form.domain_enabled === true">
            <va-checkbox v-model="form.domains.connect"
              :label="t('admin.plans.connectDomains')"
              :messages="t('admin.plans.connectDomainsMessage')"
              class="my-2"
              immediateValidation
              />
            <va-checkbox v-model="form.domains.register"
              :label="t('admin.plans.registerDomains')"
              :messages="t('admin.plans.registerDomainsMessage')"
              class="my-2"
              immediateValidation
              />
            <va-checkbox v-model="form.domains.transfer"
              :label="t('admin.plans.transferDomains')"
              :messages="t('admin.plans.transferDomainsMessage')"
              class="my-2"
              immediateValidation
              />
            <va-input
              type="number"
              :label="t('admin.plans.maxDomains')"
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
      <h1 class="va-h1">App Settings</h1>
      <AdminSettings v-for="(app, index) in apps" :key="index">
        <template #name>{{ app.name }}</template>
        <template #settings>
          <va-select v-model="form['app_plans'][app.slug]['plans']"
            :label="t('admin.plans.appPlan')"
            :options="app_plans[app.slug]"
            value-by="value"
            text-by="text"
            class="my-2"
            immediateValidation
            multiple
            clearable
            placeholder="Disabled"
            messages="Choose from any app plan or select a specific plan"
            />
          <va-input
            v-model="form['app_plans'][app.slug]['max']"
            :label="t('admin.plans.maxActivations')"
            immediateValidation
            type="number"
            min="0"
            class="my-2"
            messages="Choose the maximum number of times this app can be activated"
            />
        </template>
      </AdminSettings>

        <va-button type="submit" class="mr-2 mb-2" :disabled="form.processing">Update</va-button>
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
      features: this.plan.features,
      app_plans: appPlans,
      planTypes: [
        {
          text: 'Package',
          value: 'package'
        },
        {
          text: 'Pay per App',
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
