<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PlanLayout from './PlanLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm, Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>{{ t('admin.plans.editPlan') }} - Control Panel</title>
  </Head>
  <div class="row justify-center">
    <Link v-if="!plan.archived" :href="'/admin/apps/'+app.slug+'/plans/'+plan.id+'/archive'"><va-button id="createUser">{{ t('admin.plans.archivePlan') }}</va-button></Link>
    <Link v-else :href="'/admin/apps/'+app.slug+'/plans/'+plan.id+'/unarchive'"><va-button id="createUser">{{ t('admin.plans.makePublic') }}</va-button></Link>
  </div>
  <form @submit.prevent="form.post('/admin/apps/'+app.slug+'/plans/'+plan.id)">
    <AdminSettings>
      <template #name></template>
      <template #settings>
        <va-input v-model="form.name"
          id="name"
          class="my-2"
          :label="t('admin.plans.name')"
          required-mark
          immediateValidation
          :error="$page.props.errors.name"
          :error-messages="$page.props.errors.name"
          />
        <va-checkbox v-model="form.default"
          class="my-2"
          :label="t('admin.plans.defaultPlan') "
          :messages="t('admin.plans.defaultPlanCaption')"
          immediateValidation
          :error="$page.props.errors.default"
          :error-messages="$page.props.errors.default"
        />
        <va-input v-model="form.description"
          class="my-2"
          id="description"
          :label="t('admin.plans.description')"
          immediateValidation
          :error="$page.props.errors.description"
          :error-messages="$page.props.errors.description"
          />
        <va-checkbox v-model="form.payment_enabled"
          class="my-2"
          :label="t('admin.plans.enablePayment')"
          immediateValidation
          :error="$page.props.errors.payment_enabled"
          :error-messages="$page.props.errors.payment_enabled"
        />
        <va-checkbox v-model="form.admin_access"
          class="my-2"
          :label="t('admin.plans.adminAccess')"
          :messages="t('admin.plans.adminAccessCaption')"
          immediateValidation
          :error="$page.props.errors.admin_access"
          :error-messages="$page.props.errors.admin_access"
        />
        <va-input
          type="number"
          class="my-2"
          min="0"
          v-model="form.expires_after"
          :label="t('admin.plans.expiresAfter')"
          :messages="t('admin.plans.expiresAfterCaption')"
          id="expiresAfter"
          immediateValidation
          :error="$page.props.errors.expires_after"
          :error-messages="$page.props.errors.expires_after"
        >
          <template #appendInner>
            {{ t('admin.plans.days') }}
          </template>
        </va-input>
        <va-input
          type="number"
          class="my-2"
          min="0"
          v-model="form.trial_for"
          id="trialFor"
            :label="t('admin.plans.trialPeriodFor')"
            :messages="t('admin.plans.trialPeriodForCaption')"
          immediateValidation
          :error="$page.props.errors.trial_for"
          :error-messages="$page.props.errors.trial_for"
        >
          <template #appendInner>
            {{ t('admin.plans.days') }}
          </template>
        </va-input>
        <va-checkbox
          v-model="form.domain_enabled"
          class="my-2"
          :label="t('admin.plans.enableDomains')"
          :messages="t('admin.plans.enableDomainsCaption')"
          immediateValidation
          :error="$page.props.errors.domain_enabled"
          :error-messages="$page.props.errors.domain_enabled"
        />
        <va-input
          type="number"
          class="my-2"
          min="0"
          v-model="form.domain_max"
          id="domainMax"
          :label="t('admin.plans.maxDomains')"
          immediateValidation
          :error="$page.props.errors.domain_max"
          :error-messages="$page.props.errors.domain_max"
        />
      </template>
    </AdminSettings>
    <va-list-separator class="my-1" fit />
    <AdminSettings>
      <template #name>{{ t('admin.plans.serverSettings') }}</template>
      <template #settings>
        <va-select
          v-model="form.server_type"
          class="my-2"
          :label="t('admin.plans.serverType')"
          :messages="t('admin.plans.serverTypeCaption')"
          immediateValidation
          clearable
          value-by="value"
          text-by="text"
          :options="serverTypes"
          :error="$page.props.errors.server_type"
          :error-messages="$page.props.errors.server_type"
        />
        <template v-if="form.server_type == 'shared'">
          <va-select
            v-if="app.can.shareable"
            v-model="form.shared_app"
            class="my-2"
            :label="t('admin.plans.sharedApp')"
            :messages="t('admin.plans.sharedAppCaption')"
            immediateValidation
            value-by="id"
            text-by="name"
            :options="shared_apps"
            :error="$page.props.errors.shared_app"
            :error-messages="$page.props.errors.shared_app"
          />
        </template>
        <template v-else>
          <va-select
            v-model="form.web_server"
            class="my-2"
            :label="t('admin.plans.webServer')"
            immediateValidation
            clearable
            value-by="value"
            text-by="text"
            :options="web_servers"
            :error="$page.props.errors.web_server"
            :error-messages="$page.props.errors.web_server"
          />
          <va-select
            v-model="form.database_server"
            class="my-2"
            :label="t('admin.plans.databaseServer')"
            immediateValidation
            clearable
            value-by="value"
            text-by="text"
            :options="database_servers"
            :error="$page.props.errors.database_server"
            :error-messages="$page.props.errors.database_server"
          />
          <va-select
            v-if="app.can.sso"
            v-model="form.sso_server"
            class="my-2"
            :label="t('admin.plans.ssoServer')"
            immediateValidation
            clearable
            value-by="value"
            text-by="text"
            :options="sso_servers"
            :error="$page.props.errors.sso_server"
            :error-messages="$page.props.errors.sso_server"
          />
        </template>
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
      <template #name>{{ t('admin.plans.baseOptions') }}</template>
      <template #settings>
        <va-input
          type="number"
          class="my-2"
          v-model="form.base.price"
          :label="t('admin.plans.price')"
          :messages="t('admin.plans.basePriceCaption')"
          immediateValidation
          id="basePrice"
          min="0"
          step=".01"
        >
          <template #prependInner>
            {{ t('admin.plans.currencySymbol') }}
          </template>
        </va-input>
        <va-input
          id="baseStripeId"
          class="my-2"
          :label="t('admin.plans.productID')"
          :messages="t('admin.plans.productIDCaption')"
          immediateValidation
          v-model="form.base.price_id" />
        <va-input v-model="form.base.storage"
          :label="t('admin.plans.baseStorage')"
          :messages="t('admin.plans.baseStorageCaption')"
          id="baseStorage"
          immediateValidation
          type="number"
          min="0"
        >
          <template #appendInner>
            {{ t('admin.plans.gb') }}
          </template>
        </va-input>
      </template>
    </AdminSettings>
    <va-list-separator class="my-1" fit />
    <AdminSettings>
      <template #name>{{ t('admin.plans.standardUsers') }}</template>
      <template #settings>
        <va-input
          type="number"
          class="my-2"
          v-model="form.standard.price"
          :label="t('admin.plans.price')"
          :messages="t('admin.plans.standardUserPriceCaption')"
          immediateValidation
          id="standardPrice"
          min="0"
          step=".01"
        >
          <template #prependInner>
            {{ t('admin.plans.currencySymbol') }}
          </template>
        </va-input>
        <va-input
          type="number"
          class="my-2"
          v-model="form.standard.max"
          :label="t('admin.plans.maxUsers')"
          :messages="t('admin.plans.maxUsersCaption')"
          immediateValidation
          id="standardMax"
        >
          <template #appendInner>
            {{ t('admin.plans.users') }}
          </template>
        </va-input>
        <va-input
          v-model="form.standard.price_id"
          class="my-2"
          :label="t('admin.plans.productID')"
          :messages="t('admin.plans.productIDCaption')"
          immediateValidation
          id="standardStripeId" />
        <va-input
          v-if="app.can.additional_user_storage"
          v-model="form.standard.storage"
          class="my-2"
          :label="t('admin.plans.standardUserStorage')"
          :messages="t('admin.plans.standardUserStorageCaption')"
          id="standardStorage"
          immediateValidation
          type="number"
          min="0"
        >
          <template #appendInner>
            {{ t('admin.plans.gb') }}
          </template>
        </va-input>
      </template>
    </AdminSettings>
    <va-list-separator class="my-1" fit />
    <AdminSettings>
      <template #name>{{ t('admin.plans.basicUsers') }}</template>
      <template #settings>
        <va-input
          v-model="form.basic.name"
          class="my-2"
          :label="t('admin.plans.name')"
          :messages="t('admin.plans.basicUserNameCaption')"
          immediateValidation
          id="basicName" />
        <va-input
          type="number"
          class="my-2"
          v-model="form.basic.price"
          :label="t('admin.plans.price')"
          :messages="t('admin.plans.basicUserPriceCaption')"
          immediateValidation
          id="basicPrice"
          min="0"
          step=".01"
        >
          <template #prependInner>
            {{ t('admin.plans.currencySymbol') }}
          </template>
        </va-input>
        <va-input
          type="number"
          class="my-2"
          v-model="form.basic.max"
          :label="t('admin.plans.maxUsers')"
          :messages="t('admin.plans.maxBasicUsersCaption')"
          immediateValidation
          id="basicMax"
        >
          <template #appendInner>
            {{ t('admin.plans.users') }}
          </template>
        </va-input>
        <va-input
          v-model="form.basic.price_id"
          class="my-2"
          :label="t('admin.plans.productID')"
          :messages="t('admin.plans.productIDCaption')"
          immediateValidation
          id="basicStripeId" />
        <va-input
          v-if="app.can.additional_user_storage"
          v-model="form.basic.storage"
          class="my-2"
          :label="t('admin.plans.baseStorage')"
          :messages="t('admin.plans.basicUserStorageCaption')"
          id="basicStorage"
          immediateValidation
          type="number"
          min="0"
        >
          <template #appendInner>
            {{ t('admin.plans.gb') }}
          </template>
        </va-input>
        <va-input
          v-model="form.basic.amount"
          class="my-2"
          :label="t('admin.plans.usersPerPrice')+' '+form.basic.price"
          :messages="t('admin.plans.usersPerPriceCaption')"
          id="basicAmount"
          immediateValidation
          type="number"
          min="0"
        >
          <template #appendInner>
            {{ t('admin.plans.gb') }}
          </template>
        </va-input>
      </template>
    </AdminSettings>
    <va-list-separator v-if="app.can.additional_storage" class="my-1" fit />
    <AdminSettings v-if="app.can.additional_user_storage">
      <template #name>{{ t('admin.plans.additionalStorage') }}</template>
      <template #settings>
        <va-input
          type="number"
          class="my-2"
          v-model="form.storage.price"
          :label="t('admin.plans.price')"
          :messages="t('admin.plans.additionalStoragePriceCaption')"
          id="storagePrice"
          immediateValidation
          min="0"
          step=".01"
        >
          <template #prependInner>
            {{ t('admin.plans.currencySymbol') }}
          </template>
        </va-input>
        <va-input
          type="number"
          class="my-2"
          min="0"
          v-model="form.storage.max"
          :label="t('admin.plans.maxAdditionalStorage')"
          :messages="t('admin.plans.maxAdditionalStorageCaption')"
          immediateValidation
          id="storageMax"
        >
          <template #appendInner>
            {{ t('admin.plans.gb') }}
          </template>
        </va-input>
        <va-input
          v-model="form.storage.price_id"
          class="my-2"
          :label="t('admin.plans.productID')"
          :messages="t('admin.plans.productIDCaption')"
          immediateValidation
          id="storageStripeId" />
        <va-input
          v-model="form.storage.amount"
          class="my-2"
          :label="t('admin.plans.quantity')"
          :messages="t('admin.plans.additionalStorageQuantityCaption')"
          id="storageAmount"
          immediateValidation
          type="number"
          min="0"
        >
          <template #appendInner>
            {{ t('admin.plans.gb') }}
          </template>
        </va-input>
      </template>
    </AdminSettings>
    <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">{{ t('form.update') }}</va-button>
  </form>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(PlanLayout, () => page))
  },
  props: {
    plan: Object,
    errors: Object,
    app: Object,
    web_servers: Object,
    database_servers: Object,
    sso_servers: Object,
    shared_apps: Object
  },
  data () {
    return {
      features: this.plan.features,
      feature_options: [
        { text: useI18n().t('status.disabled'), value: 'disabled' },
        { text: useI18n().t('status.enabled'), value: 'enabled' },
        { text: useI18n().t('status.optional'), value: 'optional' }
      ],
      featurePaymentTypes: [
        { text: useI18n().t('admin.plans.perUser'), value: 'user' },
        { text: useI18n().t('admin.plans.addToBill'), value: 'addon' }
      ],
      serverTypes: [
        { text: useI18n().t('admin.plans.serverTypeSeparate'), value: 'separate' },
        { text: useI18n().t('admin.plans.serverTypeShared'), value: 'shared' }
      ],
      form: useForm({
        name: this.plan.name,
        description: this.plan.description,
        payment_enabled: this.plan.payment_enabled,
        admin_access: this.plan.admin_access,
        displayed_features: this.plan.features,
        default: this.plan.is_default,
        domain_enabled: this.plan.domain_enabled,
        domain_max: this.plan.domain_max,
        base: {
          price: this.plan.settings.base.price,
          price_id: this.plan.settings.base.price_id,
          storage: this.plan.settings.base.storage,
          max: this.plan.settings.base.max
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
        server_type: this.plan.settings.server_type,
        web_server: this.plan.web_server,
        database_server: this.plan.database_server,
        sso_server: this.plan.sso_server,
        shared_app: this.plan.shared_app,
        expires_after: this.plan.expires_after,
        trial_for: this.plan.trial_for
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
