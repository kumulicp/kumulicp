<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PlanLayout from './PlanLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm, Link } from '@inertiajs/vue3'
import { currencyByCode } from '@/constants/currencies'

</script>
<template>
  <Head>
    <title>{{ $t('admin.plans.editPlan') }} - Control Panel</title>
  </Head>
  <div class="row justify-center">
    <Link v-if="!plan.archived" :href="'/admin/apps/'+app.slug+'/plans/'+plan.id+'/archive'"><va-button id="createUser">{{ $t('admin.plans.archivePlan') }}</va-button></Link>
    <Link v-else :href="'/admin/apps/'+app.slug+'/plans/'+plan.id+'/unarchive'"><va-button id="createUser">{{ $t('admin.plans.makePublic') }}</va-button></Link>
  </div>
  <form @submit.prevent="form.post('/admin/apps/'+app.slug+'/plans/'+plan.id)">
    <AdminSettings>
      <template #name></template>
      <template #settings>
        <va-input v-model="form.name"
          id="name"
          class="my-2"
          :label="$t('admin.plans.name')"
          required-mark
          immediateValidation
          :error="$page.props.errors.name"
          :error-messages="$page.props.errors.name"
          />
        <va-checkbox v-model="form.default"
          class="my-2"
          :label="$t('admin.plans.defaultPlan') "
          :messages="$t('admin.plans.defaultPlanCaption')"
          immediateValidation
          :error="$page.props.errors.default"
          :error-messages="$page.props.errors.default"
        />
        <va-input v-model="form.description"
          class="my-2"
          id="description"
          :label="$t('admin.plans.description')"
          immediateValidation
          :error="$page.props.errors.description"
          :error-messages="$page.props.errors.description"
          />
        <va-checkbox v-model="form.payment_enabled"
          class="my-2"
          :label="$t('admin.plans.enablePayment')"
          immediateValidation
          :error="$page.props.errors.payment_enabled"
          :error-messages="$page.props.errors.payment_enabled"
        />
        <va-checkbox v-model="form.admin_access"
          class="my-2"
          :label="$t('admin.plans.adminAccess')"
          :messages="$t('admin.plans.adminAccessCaption')"
          immediateValidation
          :error="$page.props.errors.admin_access"
          :error-messages="$page.props.errors.admin_access"
        />
        <va-checkbox v-model="form.self_registration_enabled"
          class="my-2"
          :label="$t('admin.plans.selfRegistration')"
          :messages="$t('admin.plans.selfRegistrationCaption')"
          immediateValidation
          :error="$page.props.errors.self_registration_enabled"
          :error-messages="$page.props.errors.self_registration_enabled"
        />
        <va-input
          type="number"
          class="my-2"
          min="0"
          v-model="form.expires_after"
          :label="$t('admin.plans.expiresAfter')"
          :messages="$t('admin.plans.expiresAfterCaption')"
          id="expiresAfter"
          immediateValidation
          :error="$page.props.errors.expires_after"
          :error-messages="$page.props.errors.expires_after"
        >
          <template #appendInner>
            {{ $t('admin.plans.days') }}
          </template>
        </va-input>
        <va-input
          type="number"
          class="my-2"
          min="0"
          v-model="form.trial_for"
          id="trialFor"
            :label="$t('admin.plans.trialPeriodFor')"
            :messages="$t('admin.plans.trialPeriodForCaption')"
          immediateValidation
          :error="$page.props.errors.trial_for"
          :error-messages="$page.props.errors.trial_for"
        >
          <template #appendInner>
            {{ $t('admin.plans.days') }}
          </template>
        </va-input>
        <va-checkbox
          v-model="form.domain_enabled"
          class="my-2"
          :label="$t('admin.plans.enableDomains')"
          :messages="$t('admin.plans.enableDomainsCaption')"
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
          :label="$t('admin.plans.maxDomains')"
          immediateValidation
          :error="$page.props.errors.domain_max"
          :error-messages="$page.props.errors.domain_max"
        />
      </template>
    </AdminSettings>
    <va-list-separator class="my-1" fit />
    <AdminSettings>
      <template #name>{{ $t('admin.plans.serverSettings') }}</template>
      <template #settings>
        <va-select
          v-model="form.server_type"
          class="my-2"
          :label="$t('admin.plans.serverType')"
          :messages="$t('admin.plans.serverTypeCaption')"
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
            v-if="app.can.shareable && $page.props.flags.sharedApps"
            v-model="form.shared_app"
            class="my-2"
            :label="$t('admin.plans.sharedApp')"
            :messages="$t('admin.plans.sharedAppCaption')"
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
            :label="$t('admin.plans.webServer')"
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
            :label="$t('admin.plans.databaseServer')"
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
            :label="$t('admin.plans.ssoServer')"
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
        <template v-for="currency in enabled_currencies" :key="currency">
          <div class="row">
            <div class="flex lg6 xs12">
              <va-input
                :id="'base-price-' + currency"
                type="number"
                v-model="form.prices.base[currency].amount"
                :label="$t('admin.plans.price') + ' (' + currency + ')'"
                :messages="$t('admin.plans.basePriceCaption')"
                class="my-2"
                immediateValidation
                min="0"
                step=".01"
              >
                <template #prependInner>{{ currencySymbol(currency) }}</template>
              </va-input>
            </div>
            <div class="flex lg6 xs12">
              <va-input :id="'base-price-id-' + currency"
                v-model="form.prices.base[currency].price_id"
                :label="$t('admin.plans.productID') + ' (' + currency + ')'"
                :messages="$t('admin.plans.productIDCaption')"
                class="my-2"
              />
            </div>
          </div>
        </template>
        <va-input v-model="form.base.storage"
          :label="$t('admin.plans.baseStorage')"
          :messages="$t('admin.plans.baseStorageCaption')"
          id="baseStorage"
          immediateValidation
          type="number"
          min="0"
        >
          <template #appendInner>
            {{ $t('admin.plans.gb') }}
          </template>
        </va-input>
      </template>
    </AdminSettings>
    <va-list-separator class="my-1" fit />
    <AdminSettings>
      <template #name>{{ $t('admin.plans.standardUsers') }}</template>
      <template #settings>
        <template v-for="currency in enabled_currencies" :key="currency">
          <div class="row">
            <div class="flex lg6 xs12">
              <va-input
                :id="'standard-price-' + currency"
                type="number"
                v-model="form.prices.standard[currency].amount"
                :label="$t('admin.plans.price') + ' (' + currency + ')'"
                :messages="$t('admin.plans.standardUserPriceCaption')"
                class="my-2"
                immediateValidation
                min="0"
                step=".01"
              >
                <template #prependInner>{{ currencySymbol(currency) }}</template>
              </va-input>
            </div>
            <div class="flex lg6 xs12">
              <va-input :id="'standard-price-id-' + currency"
                v-model="form.prices.standard[currency].price_id"
                :label="$t('admin.plans.productID') + ' (' + currency + ')'"
                :messages="$t('admin.plans.productIDCaption')"
                class="my-2"
              />
            </div>
          </div>
        </template>
        <va-input
          type="number"
          class="my-2"
          v-model="form.standard.max"
          :label="$t('admin.plans.maxUsers')"
          :messages="$t('admin.plans.maxUsersCaption')"
          immediateValidation
          id="standardMax"
        >
          <template #appendInner>
            {{ $t('admin.plans.users') }}
          </template>
        </va-input>
        <va-input
          v-if="app.can.additional_user_storage"
          v-model="form.standard.storage"
          class="my-2"
          :label="$t('admin.plans.standardUserStorage')"
          :messages="$t('admin.plans.standardUserStorageCaption')"
          id="standardStorage"
          immediateValidation
          type="number"
          min="0"
        >
          <template #appendInner>
            {{ $t('admin.plans.gb') }}
          </template>
        </va-input>
      </template>
    </AdminSettings>
    <va-list-separator class="my-1" fit />
    <AdminSettings>
      <template #name>{{ $t('admin.plans.basicUsers') }}</template>
      <template #settings>
        <va-input
          v-model="form.basic.name"
          class="my-2"
          :label="$t('admin.plans.name')"
          :messages="$t('admin.plans.basicUserNameCaption')"
          immediateValidation
          id="basicName" />
        <template v-for="currency in enabled_currencies" :key="currency">
          <div class="row">
            <div class="flex lg6 xs12">
              <va-input
                :id="'basic-price-' + currency"
                type="number"
                v-model="form.prices.basic[currency].amount"
                :label="$t('admin.plans.price') + ' (' + currency + ')'"
                :messages="$t('admin.plans.basicUserPriceCaption')"
                class="my-2"
                immediateValidation
                min="0"
                step=".01"
              >
                <template #prependInner>{{ currencySymbol(currency) }}</template>
              </va-input>
            </div>
            <div class="flex lg6 xs12">
              <va-input :id="'basic-price-id-' + currency"
                v-model="form.prices.basic[currency].price_id"
                :label="$t('admin.plans.productID') + ' (' + currency + ')'"
                :messages="$t('admin.plans.productIDCaption')"
                class="my-2"
              />
            </div>
          </div>
        </template>
        <va-input
          type="number"
          class="my-2"
          v-model="form.basic.max"
          :label="$t('admin.plans.maxUsers')"
          :messages="$t('admin.plans.maxBasicUsersCaption')"
          immediateValidation
          id="basicMax"
        >
          <template #appendInner>
            {{ $t('admin.plans.users') }}
          </template>
        </va-input>
        <va-input
          v-if="app.can.additional_user_storage"
          v-model="form.basic.storage"
          class="my-2"
          :label="$t('admin.plans.baseStorage')"
          :messages="$t('admin.plans.basicUserStorageCaption')"
          id="basicStorage"
          immediateValidation
          type="number"
          min="0"
        >
          <template #appendInner>
            {{ $t('admin.plans.gb') }}
          </template>
        </va-input>
        <va-input
          v-model="form.basic.amount"
          class="my-2"
          :label="$t('admin.plans.usersPerPriceCaption')"
          :messages="$t('admin.plans.usersPerPriceCaption')"
          id="basicAmount"
          immediateValidation
          type="number"
          min="0"
        >
          <template #appendInner>
            {{ $t('admin.plans.gb') }}
          </template>
        </va-input>
      </template>
    </AdminSettings>
    <va-list-separator v-if="app.can.additional_storage" class="my-1" fit />
    <AdminSettings v-if="app.can.additional_user_storage">
      <template #name>{{ $t('admin.plans.additionalStorage') }}</template>
      <template #settings>
        <template v-for="currency in enabled_currencies" :key="currency">
          <div class="row">
            <div class="flex lg6 xs12">
              <va-input
                :id="'storage-price-' + currency"
                type="number"
                v-model="form.prices.storage[currency].amount"
                :label="$t('admin.plans.price') + ' (' + currency + ')'"
                :messages="$t('admin.plans.additionalStoragePriceCaption')"
                class="my-2"
                immediateValidation
                min="0"
                step=".01"
              >
                <template #prependInner>{{ currencySymbol(currency) }}</template>
              </va-input>
            </div>
            <div class="flex lg6 xs12">
              <va-input :id="'storage-price-id-' + currency"
                v-model="form.prices.storage[currency].price_id"
                :label="$t('admin.plans.productID') + ' (' + currency + ')'"
                :messages="$t('admin.plans.productIDCaption')"
                class="my-2"
              />
            </div>
          </div>
        </template>
        <va-input
          type="number"
          class="my-2"
          min="0"
          v-model="form.storage.max"
          :label="$t('admin.plans.maxAdditionalStorage')"
          :messages="$t('admin.plans.maxAdditionalStorageCaption')"
          immediateValidation
          id="storageMax"
        >
          <template #appendInner>
            {{ $t('admin.plans.gb') }}
          </template>
        </va-input>
        <va-input
          v-model="form.storage.amount"
          class="my-2"
          :label="$t('admin.plans.quantity')"
          :messages="$t('admin.plans.additionalStorageQuantityCaption')"
          id="storageAmount"
          immediateValidation
          type="number"
          min="0"
        >
          <template #appendInner>
            {{ $t('admin.plans.gb') }}
          </template>
        </va-input>
      </template>
    </AdminSettings>
    <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">{{ $t('common.update') }}</va-button>
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
    shared_apps: Object,
    enabled_currencies: {
      type: Array,
      default: () => ['USD']
    }
  },
  data () {
    const buildCurrencyPrices = (component) => {
      const result = {}
      for (const currency of this.enabled_currencies) {
        const perCurrency = this.plan.settings?.[component]?.prices?.[currency]
        result[currency] = {
          amount: perCurrency?.amount ?? this.plan.settings?.[component]?.price ?? null,
          price_id: perCurrency?.price_id ?? (currency === this.enabled_currencies[0] ? (this.plan.settings?.[component]?.price_id ?? '') : '')
        }
      }
      return result
    }

    return {
      features: this.plan.features,
      feature_options: [
        { text: this.$t('status.disabled'), value: 'disabled' },
        { text: this.$t('status.enabled'), value: 'enabled' },
        { text: this.$t('status.optional'), value: 'optional' }
      ],
      featurePaymentTypes: [
        { text: this.$t('admin.plans.perUser'), value: 'user' },
        { text: this.$t('admin.plans.addToBill'), value: 'addon' }
      ],
      serverTypes: [
        { text: this.$t('admin.plans.serverTypeSeparate'), value: 'separate' },
        { text: this.$t('admin.plans.serverTypeShared'), value: 'shared' }
      ],
      form: useForm({
        name: this.plan.name,
        description: this.plan.description,
        payment_enabled: this.plan.payment_enabled,
        admin_access: this.plan.admin_access,
        self_registration_enabled: this.plan.self_registration_enabled,
        displayed_features: this.plan.features,
        default: this.plan.is_default,
        domain_enabled: this.plan.domain_enabled,
        domain_max: this.plan.domain_max,
        base: {
          storage: this.plan.settings.base.storage,
          max: this.plan.settings.base.max
        },
        standard: {
          storage: this.plan.settings.standard.storage,
          max: this.plan.settings.standard.max
        },
        basic: {
          storage: this.plan.settings.basic.storage,
          max: this.plan.settings.basic.max,
          name: this.plan.settings.basic.name,
          amount: this.plan.settings.basic.amount
        },
        storage: {
          amount: this.plan.settings.storage.amount,
          max: this.plan.settings.storage.max
        },
        prices: {
          base: buildCurrencyPrices('base'),
          standard: buildCurrencyPrices('standard'),
          basic: buildCurrencyPrices('basic'),
          storage: buildCurrencyPrices('storage')
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
    },
    currencySymbol (code) {
      return currencyByCode(code)?.symbol ?? code
    }
  }
}
</script>

<style>
</style>
