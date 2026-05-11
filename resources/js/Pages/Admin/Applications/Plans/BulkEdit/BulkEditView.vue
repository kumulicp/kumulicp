<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import BulkEditLayout from './BulkEditLayout.vue'

</script>
<template>
  <Head>
    <title>{{ $t('admin.plans.bulkEdit') }} - Control Panel</title>
  </Head>
  <va-scroll-container horizontal>
    <table class="va-table bulk-edit-table">
      <thead>
        <tr>
          <th class="setting-col">{{ $t('admin.plans.setting') }}</th>
          <th v-for="plan in plans" :key="plan.id" class="plan-col">{{ plan.name }}</th>
        </tr>
      </thead>
      <tbody>
        <!-- General -->
        <tr class="section-header">
          <td :colspan="plans.length + 1"><strong>{{ $t('admin.plans.generalSettings') }}</strong></td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.name') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.name }}</td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.description') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.description }}</td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.defaultPlan') }}</td>
          <td v-for="plan in plans" :key="plan.id">
            <va-icon name="fa-check" color="success" v-if="plan.is_default" />
            <va-icon name="fa-x" color="danger" v-else />
          </td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.enablePayment') }}</td>
          <td v-for="plan in plans" :key="plan.id">
            <va-icon name="fa-check" color="success" v-if="plan.payment_enabled" />
            <va-icon name="fa-x" color="danger" v-else />
          </td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.adminAccess') }}</td>
          <td v-for="plan in plans" :key="plan.id">
            <va-icon name="fa-check" color="success" v-if="plan.admin_access" />
            <va-icon name="fa-x" color="danger" v-else />
          </td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.expiresAfter') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.expires_after }} {{ $t('admin.plans.days') }}</td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.trialPeriodFor') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.trial_for }} {{ $t('admin.plans.days') }}</td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.enableDomains') }}</td>
          <td v-for="plan in plans" :key="plan.id">
            <va-icon name="fa-check" color="success" v-if="plan.domain_enabled" />
            <va-icon name="fa-x" color="danger" v-else />
          </td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.maxDomains') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.domain_max }}</td>
        </tr>

        <!-- Server Settings -->
        <tr class="section-header">
          <td :colspan="plans.length + 1"><strong>{{ $t('admin.plans.serverSettings') }}</strong></td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.serverType') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.settings.server_type }}</td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.webServer') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.web_server }}</td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.databaseServer') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.database_server }}</td>
        </tr>
        <tr v-if="app.can.sso">
          <td class="setting-label">{{ $t('admin.plans.ssoServer') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.sso_server }}</td>
        </tr>
        <tr v-if="app.can.shareable">
          <td class="setting-label">{{ $t('admin.plans.sharedApp') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.shared_app }}</td>
        </tr>

        <!-- Base Options -->
        <tr class="section-header">
          <td :colspan="plans.length + 1"><strong>{{ $t('admin.plans.baseOptions') }}</strong></td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.price') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ $t('admin.plans.currencySymbol') }}{{ plan.settings.base?.price }}</td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.productID') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.settings.base?.price_id }}</td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.baseStorage') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.settings.base?.storage }}{{ $t('admin.plans.gb') }}</td>
        </tr>

        <!-- Standard Users -->
        <tr class="section-header">
          <td :colspan="plans.length + 1"><strong>{{ $t('admin.plans.standardUsers') }}</strong></td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.price') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ $t('admin.plans.currencySymbol') }}{{ plan.settings.standard?.price }}</td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.maxUsers') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.settings.standard?.max }}{{ $t('admin.plans.users') }}</td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.productID') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.settings.standard?.price_id }}</td>
        </tr>
        <tr v-if="app.can.additional_user_storage">
          <td class="setting-label">{{ $t('admin.plans.standardUserStorage') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.settings.standard?.storage }}{{ $t('admin.plans.gb') }}</td>
        </tr>

        <!-- Basic Users -->
        <tr class="section-header">
          <td :colspan="plans.length + 1"><strong>{{ $t('admin.plans.basicUsers') }}</strong></td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.name') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.settings.basic?.name }}</td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.price') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ $t('admin.plans.currencySymbol') }}{{ plan.settings.basic?.price }}</td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.maxUsers') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.settings.basic?.max }}{{ $t('admin.plans.users') }}</td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.productID') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.settings.basic?.price_id }}</td>
        </tr>
        <tr v-if="app.can.additional_user_storage">
          <td class="setting-label">{{ $t('admin.plans.baseStorage') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.settings.basic?.storage }}{{ $t('admin.plans.gb') }}</td>
        </tr>
        <tr>
          <td class="setting-label">{{ $t('admin.plans.usersPerPrice') }}</td>
          <td v-for="plan in plans" :key="plan.id">{{ plan.settings.basic?.amount }}</td>
        </tr>

        <!-- Additional Storage -->
        <tr v-if="app.can.additional_user_storage" class="section-header">
          <td :colspan="plans.length + 1"><strong>{{ $t('admin.plans.additionalStorage') }}</strong></td>
        </tr>
        <template v-if="app.can.additional_user_storage">
          <tr>
            <td class="setting-label">{{ $t('admin.plans.price') }}</td>
            <td v-for="plan in plans" :key="plan.id">{{ $t('admin.plans.currencySymbol') }}{{ plan.settings.storage?.price }}</td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.maxAdditionalStorage') }}</td>
            <td v-for="plan in plans" :key="plan.id">{{ plan.settings.storage?.max }}{{ $t('admin.plans.gb') }}</td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.productID') }}</td>
            <td v-for="plan in plans" :key="plan.id">{{ plan.settings.storage?.price_id }}</td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.quantity') }}</td>
            <td v-for="plan in plans" :key="plan.id">{{ plan.settings.storage?.amount }}{{ $t('admin.plans.gb') }}</td>
          </tr>
        </template>

        <!-- Features -->
        <tr v-if="features.length > 0" class="section-header">
          <td :colspan="plans.length + 1"><strong>{{ $t('admin.plans.features') }}</strong></td>
        </tr>
        <template v-for="(feature, fi) in features" :key="fi">
          <tr>
            <td class="setting-label"><em>{{ feature.label }}</em> — {{ $t('admin.plans.featureStatus') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              {{ plan.settings.features?.[feature.value]?.status }}
            </td>
          </tr>
          <tr v-if="feature.settings?.length">
            <td :colspan="plans.length + 1" class="px-4 va-text-secondary" style="font-size:0.85em">{{ feature.label }} {{ $t('admin.plans.settings') }}</td>
          </tr>
          <template v-if="feature.settings?.length" v-for="(setting, si) in feature.settings" :key="si">
            <tr>
              <td class="setting-label pl-4">{{ setting.label }}</td>
              <td v-for="plan in plans" :key="plan.id">
                {{ plan.settings.features?.[feature.value]?.settings?.[setting.name] }}
              </td>
            </tr>
          </template>
          <tr>
            <td class="setting-label">{{ feature.label }} — {{ $t('admin.plans.price') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              {{ $t('admin.plans.currencySymbol') }}{{ plan.settings.features?.[feature.value]?.price }}
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ feature.label }} — {{ $t('admin.plans.productID') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              {{ plan.settings.features?.[feature.value]?.price_id }}
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ feature.label }} — {{ $t('admin.plans.howFeatureIsBilled') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              {{ plan.settings.features?.[feature.value]?.payment_type }}
            </td>
          </tr>
        </template>

        <!-- Configurations -->
        <tr v-if="Object.keys(configs).length > 0" class="section-header">
          <td :colspan="plans.length + 1"><strong>{{ $t('admin.plans.configurations') }}</strong></td>
        </tr>
        <template v-for="(config, key) in configs" :key="key">
          <tr>
            <td class="setting-label">{{ config.name }}</td>
            <td v-for="plan in plans" :key="plan.id">
              {{ plan.settings?.configurations?.[config.name] }}
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </va-scroll-container>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(BulkEditLayout, () => page))
  },
  props: {
    app: Object,
    plans: Array,
    plan_ids: Array,
    features: Array,
    configs: Object,
    web_servers: Object,
    database_servers: Object,
    sso_servers: Object,
    shared_apps: Object
  }
}
</script>

<style lang="scss">
.bulk-edit-table {
  width: 100%;
  border-collapse: collapse;

  th, td {
    padding: 8px 12px;
    vertical-align: middle;
    white-space: nowrap;
  }

  .setting-col {
    min-width: 220px;
    position: sticky;
    left: 0;
    background: var(--va-background-primary);
    z-index: 1;
  }

  .plan-col {
    min-width: 200px;
  }

  .setting-label {
    position: sticky;
    left: 0;
    background: var(--va-background-primary);
    z-index: 1;
    font-weight: 500;
  }

  .section-header td {
    background: var(--va-background-secondary);
    padding: 6px 12px;
  }

  .pl-4 {
    padding-left: 2rem !important;
  }
}
</style>
