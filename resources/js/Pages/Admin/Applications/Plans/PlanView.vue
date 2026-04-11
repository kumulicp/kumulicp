<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PlanLayout from './PlanLayout.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>{{ t('admin.plans.editPlan') }} - Control Panel</title>
  </Head>
  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>Value</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><b>{{ t('admin.plans.name') }}:</b></td>
        <td>{{ plan.name }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.defaultPlan') }}:</b></td>
        <td>{{ plan.is_default }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.description') }}:</b></td>
        <td>{{ plan.description }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.features') }}:</b></td>
        <td>{{ plan.features }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.enablePayment') }}:</b></td>
        <td>{{ plan.payment_enabled }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.adminAccess') }}:</b></td>
        <td>{{ plan.admin_access }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.expiresAfter') }}:</b></td>
        <td>{{ plan.expires_after }} {{ t('admin.plans.days') }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.trialPeriodFor') }}:</b></td>
        <td>{{ plan.trial_for }} {{ t('admin.plans.days') }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.enableDomains') }}:</b></td>
        <td>{{ plan.domain_enabled }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.maxDomains') }}:</b></td>
        <td>{{ plan.domain_max }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.webServer') }}:</b></td>
        <td>{{ plan.web_server }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.databaseServer') }}:</b></td>
        <td>{{ plan.database_server }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.ssoServer') }}:</b></td>
        <td>{{ plan.sso_server }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.parentServer') }}:</b></td>
        <td>{{ plan.parent_server }}</td>
      </tr>
      <tr>
        <td><h5 class="va-h5">{{ t('admin.plans.baseOptions') }}</h5></td>
        <td></td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.price') }}:</b></td>
        <td>{{ t('admin.plans.currencySymbol') }}{{ plan.settings.base.price }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.productID') }}:</b></td>
        <td>{{ plan.settings.base.price_id }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.baseStorage') }}:</b></td>
        <td>{{ plan.settings.base.storage }}{{ t('admin.plans.gb') }}</td>
      </tr>
      <tr>
        <td><h5 class="va-h5">{{ t('admin.plans.standardUsers') }}</h5></td>
        <td></td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.price') }}:</b></td>
        <td>{{ t('admin.plans.currencySymbol') }}{{ plan.settings.standard.price }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.maxUsers') }}:</b></td>
        <td>{{ plan.settings.standard.max }}{{ t('admin.plans.users') }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.productID') }}:</b></td>
        <td>{{ plan.settings.standard.price_id }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.standardUserStorage') }}:</b></td>
        <td>{{ plan.settings.standard.storage }}{{ t('admin.plans.gb') }}</td>
      </tr>
      <tr>
        <td><h5 class="va-h5">{{ t('admin.plans.basicUsers') }}</h5></td>
        <td></td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.name') }}:</b> </td>
        <td>{{ plan.settings.basic.name }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.price') }}:</b></td>
        <td>{{ t('admin.plans.currencySymbol') }}{{ plan.settings.basic.price }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.maxUsers') }}:</b></td>
        <td>{{ plan.settings.basic.max }} {{ t('admin.plans.users') }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.productID') }}:</b></td>
        <td>{{ plan.settings.basic.price_id }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.baseStorage') }}:</b></td>
        <td>{{ plan.settings.basic.storage }}{{ t('admin.plans.gb') }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.usersPerPrice') }}{{ plan.settings.basic.price }}:</b></td>
        <td>{{ plan.settings.basic.amount }}</td>
      </tr>
      <tr>
        <td><h5 class="va-h5">{{ t('admin.plans.additionalStorage') }}</h5></td>
        <td></td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.price') }}:</b></td>
        <td>{{ t('admin.plans.currencySymbol') }}{{ plan.settings.storage.price }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.maxAdditionalStorage') }}:</b></td>
        <td>{{ plan.settings.storage.max }}{{ t('admin.plans.gb') }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.productID') }}:</b></td>
        <td>{{ plan.settings.storage.price_id }}</td>
      </tr>
      <tr>
        <td><b>{{ t('admin.plans.quantity') }}:</b></td>
        <td>{{ plan.settings.storage.amount }}{{ t('admin.plans.gb') }}</td>
      </tr>
      <tr>
        <td><h5 class="va-h5">{{ t('admin.plans.features') }}</h5></td>
        <td></td>
      </tr>
      <tr v-if="features.length === 0">
        <td>{{ t('admin.plans.noFeatures') }}</td>
        <td></td>
      </tr>
      <template v-else v-for="(feature, index) in features" :key="index">
        <tr>
          <td>
            <h6 class="va-h6">{{ feature.label }}</h6>
          </td>
          <td>
            {{ t('status.'+plan.settings.features[feature.value].status) }}
          </td>
        </tr>
        <template v-if="feature.settings">
          <template v-for="(setting, index) in feature.settings" :key="index">
            <tr>
              <td>
                <b>{{ setting.label }}:</b>
              </td>
              <td>
                {{ plan.settings.features[feature.value]['settings'][setting.name] }}
              </td>
            </tr>
          </template>
        </template>
        <tr>
          <td>
            <b>{{ t('admin.plans.price') }}:</b>
          </td>
          <td>
            {{ t('admin.plans.currencySymbol') }}{{ plan.settings.features[feature.value].price }}
          </td>
        </tr>
        <tr>
          <td>
            <b>{{ t('admin.plans.productID') }}:</b>
          </td>
          <td>
            {{ plan.settings.features[feature.value].price_id }}
          </td>
        </tr>
        <tr>
          <td>
            <b>{{ t('admin.plans.howFeatureIsBilled') }}:</b>
          </td>
          <td>
            {{ labels[plan.settings.features[feature.value].payment_type] }}
          </td>
        </tr>
      </template>
      <tr>
        <td><h5 class="va-h5">{{ t('admin.plans.configurations') }}</h5></td>
        <td></td>
      </tr>
      <template v-for="(config, index) in configs" :key="index">
        <tr>
          <td>
            <b>{{ config.name }}:</b>
          </td>
          <td>
            {{ configurations[config.name] }}
          </td>
        </tr>
      </template>
    </tbody>
  </table>
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
    features: Object,
    configs: Object,
    web_servers: Object,
    database_servers: Object,
    sso_servers: Object,
    parent_servers: Object
  },
  data () {
    const configs = {}
    for (const [key, config] of Object.entries(this.configs)) {
      if (config) {
        configs[key] = config.value
      }
    }

    return {
      labels: {
        user: useI18n().t('admin.plans.perUser'),
        addon: useI18n().t('admin.plans.addToBill')
      },
      configurations: configs
    }
  }
}
</script>

<style>
</style>
