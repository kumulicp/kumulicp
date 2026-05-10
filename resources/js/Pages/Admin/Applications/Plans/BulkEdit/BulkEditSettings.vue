<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import BulkEditLayout from './BulkEditLayout.vue'
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.plans.bulkEdit') }} - Control Panel</title>
  </Head>
  <form @submit.prevent="submit">
    <va-scroll-container horizontal>
      <table class="va-table bulk-edit-table">
        <thead>
          <tr>
            <th class="setting-col">{{ $t('admin.plans.setting') }}</th>
            <th v-for="plan in plans" :key="plan.id" class="plan-col">{{ plan.name }}</th>
          </tr>
        </thead>
        <tbody>

          <!-- General Settings -->
          <tr class="section-header">
            <td :colspan="plans.length + 1"><strong>{{ $t('admin.plans.generalSettings') }}</strong></td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.name') }} <span class="va-text-danger">*</span></td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input
                :id="'plan-'+plan.id+'-name'"
                v-model="form.plans[plan.id].name"
                :error="!!$page.props.errors[`plans.${plan.id}.name`]"
                :error-messages="$page.props.errors[`plans.${plan.id}.name`]"
                immediateValidation
              />
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.description') }} <span class="va-text-danger">*</span></td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input
                :id="'plan-'+plan.id+'-description'"
                v-model="form.plans[plan.id].description"
                :error="!!$page.props.errors[`plans.${plan.id}.description`]"
                :error-messages="$page.props.errors[`plans.${plan.id}.description`]"
                immediateValidation
              />
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.defaultPlan') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-checkbox :id="'plan-'+plan.id+'-default'" v-model="form.plans[plan.id].default" immediateValidation />
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.enablePayment') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-checkbox :id="'plan-'+plan.id+'-payment-enabled'" v-model="form.plans[plan.id].payment_enabled" immediateValidation />
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.adminAccess') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-checkbox :id="'plan-'+plan.id+'-admin-access'" v-model="form.plans[plan.id].admin_access" immediateValidation />
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.expiresAfter') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input
                :id="'plan-'+plan.id+'-expires-after'"
                v-model="form.plans[plan.id].expires_after"
                type="number"
                min="0"
                immediateValidation
              >
                <template #appendInner>{{ $t('admin.plans.days') }}</template>
              </va-input>
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.trialPeriodFor') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input
                :id="'plan-'+plan.id+'-trial-for'"
                v-model="form.plans[plan.id].trial_for"
                type="number"
                min="0"
                immediateValidation
              >
                <template #appendInner>{{ $t('admin.plans.days') }}</template>
              </va-input>
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.enableDomains') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-checkbox :id="'plan-'+plan.id+'-domain-enabled'" v-model="form.plans[plan.id].domain_enabled" immediateValidation />
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.maxDomains') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input
                :id="'plan-'+plan.id+'-domain-max'"
                v-model="form.plans[plan.id].domain_max"
                type="number"
                min="0"
                immediateValidation
              />
            </td>
          </tr>

          <!-- Server Settings -->
          <tr class="section-header">
            <td :colspan="plans.length + 1"><strong>{{ $t('admin.plans.serverSettings') }}</strong></td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.serverType') }} <span class="va-text-danger">*</span></td>
            <td v-for="plan in plans" :key="plan.id">
              <va-select
                v-model="form.plans[plan.id].server_type"
                :options="serverTypes"
                value-by="value"
                text-by="text"
                clearable
                immediateValidation
                :error="!!$page.props.errors[`plans.${plan.id}.server_type`]"
                :error-messages="$page.props.errors[`plans.${plan.id}.server_type`]"
              />
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.webServer') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-select
                v-if="form.plans[plan.id].server_type !== 'shared'"
                v-model="form.plans[plan.id].web_server"
                :options="web_servers"
                value-by="value"
                text-by="text"
                clearable
                immediateValidation
              />
              <span v-else class="va-text-secondary">—</span>
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.databaseServer') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-select
                v-if="form.plans[plan.id].server_type !== 'shared'"
                v-model="form.plans[plan.id].database_server"
                :options="database_servers"
                value-by="value"
                text-by="text"
                clearable
                immediateValidation
              />
              <span v-else class="va-text-secondary">—</span>
            </td>
          </tr>
          <tr v-if="app.can.sso">
            <td class="setting-label">{{ $t('admin.plans.ssoServer') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-select
                v-if="form.plans[plan.id].server_type !== 'shared'"
                v-model="form.plans[plan.id].sso_server"
                :options="sso_servers"
                value-by="value"
                text-by="text"
                clearable
                immediateValidation
              />
              <span v-else class="va-text-secondary">—</span>
            </td>
          </tr>
          <tr v-if="app.can.shareable">
            <td class="setting-label">{{ $t('admin.plans.sharedApp') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-select
                v-if="form.plans[plan.id].server_type === 'shared'"
                v-model="form.plans[plan.id].shared_app"
                :options="shared_apps"
                value-by="id"
                text-by="name"
                immediateValidation
              />
              <span v-else class="va-text-secondary">—</span>
            </td>
          </tr>

          <!-- Base Options -->
          <tr class="section-header">
            <td :colspan="plans.length + 1"><strong>{{ $t('admin.plans.baseOptions') }}</strong></td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.price') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input
                :id="'plan-'+plan.id+'-base-price'"
                v-model="form.plans[plan.id].base.price"
                type="number"
                min="0"
                step=".01"
                immediateValidation
              >
                <template #prependInner>{{ $t('admin.plans.currencySymbol') }}</template>
              </va-input>
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.productID') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input :id="'plan-'+plan.id+'-base-price-id'" v-model="form.plans[plan.id].base.price_id" immediateValidation />
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.baseStorage') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input
                :id="'plan-'+plan.id+'-base-storage'"
                v-model="form.plans[plan.id].base.storage"
                type="number"
                min="0"
                immediateValidation
              >
                <template #appendInner>{{ $t('admin.plans.gb') }}</template>
              </va-input>
            </td>
          </tr>

          <!-- Standard Users -->
          <tr class="section-header">
            <td :colspan="plans.length + 1"><strong>{{ $t('admin.plans.standardUsers') }}</strong></td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.price') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input
                v-model="form.plans[plan.id].standard.price"
                type="number"
                min="0"
                step=".01"
                immediateValidation
              >
                <template #prependInner>{{ $t('admin.plans.currencySymbol') }}</template>
              </va-input>
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.maxUsers') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input
                v-model="form.plans[plan.id].standard.max"
                type="number"
                immediateValidation
              >
                <template #appendInner>{{ $t('admin.plans.users') }}</template>
              </va-input>
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.productID') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input v-model="form.plans[plan.id].standard.price_id" immediateValidation />
            </td>
          </tr>
          <tr v-if="app.can.additional_user_storage">
            <td class="setting-label">{{ $t('admin.plans.standardUserStorage') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input
                v-model="form.plans[plan.id].standard.storage"
                type="number"
                min="0"
                immediateValidation
              >
                <template #appendInner>{{ $t('admin.plans.gb') }}</template>
              </va-input>
            </td>
          </tr>

          <!-- Basic Users -->
          <tr class="section-header">
            <td :colspan="plans.length + 1"><strong>{{ $t('admin.plans.basicUsers') }}</strong></td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.name') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input v-model="form.plans[plan.id].basic.name" immediateValidation />
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.price') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input
                v-model="form.plans[plan.id].basic.price"
                type="number"
                min="0"
                step=".01"
                immediateValidation
              >
                <template #prependInner>{{ $t('admin.plans.currencySymbol') }}</template>
              </va-input>
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.maxUsers') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input
                v-model="form.plans[plan.id].basic.max"
                type="number"
                immediateValidation
              >
                <template #appendInner>{{ $t('admin.plans.users') }}</template>
              </va-input>
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.productID') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input v-model="form.plans[plan.id].basic.price_id" immediateValidation />
            </td>
          </tr>
          <tr v-if="app.can.additional_user_storage">
            <td class="setting-label">{{ $t('admin.plans.baseStorage') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input
                v-model="form.plans[plan.id].basic.storage"
                type="number"
                min="0"
                immediateValidation
              >
                <template #appendInner>{{ $t('admin.plans.gb') }}</template>
              </va-input>
            </td>
          </tr>
          <tr>
            <td class="setting-label">{{ $t('admin.plans.usersPerPrice') }}</td>
            <td v-for="plan in plans" :key="plan.id">
              <va-input
                v-model="form.plans[plan.id].basic.amount"
                type="number"
                min="0"
                immediateValidation
              />
            </td>
          </tr>

          <!-- Additional Storage -->
          <template v-if="app.can.additional_user_storage">
            <tr class="section-header">
              <td :colspan="plans.length + 1"><strong>{{ $t('admin.plans.additionalStorage') }}</strong></td>
            </tr>
            <tr>
              <td class="setting-label">{{ $t('admin.plans.price') }}</td>
              <td v-for="plan in plans" :key="plan.id">
                <va-input
                  v-model="form.plans[plan.id].storage.price"
                  type="number"
                  min="0"
                  step=".01"
                  immediateValidation
                >
                  <template #prependInner>{{ $t('admin.plans.currencySymbol') }}</template>
                </va-input>
              </td>
            </tr>
            <tr>
              <td class="setting-label">{{ $t('admin.plans.maxAdditionalStorage') }}</td>
              <td v-for="plan in plans" :key="plan.id">
                <va-input
                  v-model="form.plans[plan.id].storage.max"
                  type="number"
                  min="0"
                  immediateValidation
                >
                  <template #appendInner>{{ $t('admin.plans.gb') }}</template>
                </va-input>
              </td>
            </tr>
            <tr>
              <td class="setting-label">{{ $t('admin.plans.productID') }}</td>
              <td v-for="plan in plans" :key="plan.id">
                <va-input v-model="form.plans[plan.id].storage.price_id" immediateValidation />
              </td>
            </tr>
            <tr>
              <td class="setting-label">{{ $t('admin.plans.quantity') }}</td>
              <td v-for="plan in plans" :key="plan.id">
                <va-input
                  v-model="form.plans[plan.id].storage.amount"
                  type="number"
                  min="0"
                  immediateValidation
                >
                  <template #appendInner>{{ $t('admin.plans.gb') }}</template>
                </va-input>
              </td>
            </tr>
          </template>

        </tbody>
      </table>
    </va-scroll-container>

    <!-- Displayed Features per plan (list format, each plan in a column) -->
    <va-list-separator class="my-3" fit />
    <h4 class="va-h4 mb-2">{{ $t('admin.plans.displayedFeatures') }}</h4>
    <p class="va-text-secondary mb-3">{{ $t('admin.plans.displayedFeaturesDescription') }}</p>
    <div class="row">
      <div v-for="plan in plans" :key="plan.id" class="flex flex-col xs12 lg4">
        <va-card class="mb-3">
          <va-card-title>{{ plan.name }}</va-card-title>
          <va-card-content>
            <template v-for="(feature, index) in form.plans[plan.id].displayed_features" :key="index">
              <div class="row mb-2">
                <div class="flex flex-col xs5">
                  <va-input
                    v-model="form.plans[plan.id].displayed_features[index].name"
                    :label="$t('admin.plans.name')"
                    immediateValidation
                  />
                </div>
                <div class="flex flex-col xs6">
                  <va-input
                    v-model="form.plans[plan.id].displayed_features[index].description"
                    :label="$t('admin.plans.description')"
                    immediateValidation
                  />
                </div>
                <div class="flex xs1 content-center">
                  <va-icon name="fa-x" color="danger" style="cursor:pointer" @click="removeFeature(plan.id, index)" />
                </div>
              </div>
            </template>
            <va-button size="small" @click="addFeature(plan.id)">{{ $t('admin.plans.addFeature') }}</va-button>
          </va-card-content>
        </va-card>
      </div>
    </div>

    <va-button type="submit" class="mr-2 mb-2" :disabled="form.processing">{{ $t('common.update') }}</va-button>
  </form>
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
    web_servers: Object,
    database_servers: Object,
    sso_servers: Object,
    shared_apps: Object,
    errors: Object
  },
  data () {
    const plansMap: Record<number, any> = {}
    for (const plan of this.plans) {
      plansMap[plan.id] = {
        name: plan.name,
        description: plan.description,
        default: plan.is_default,
        payment_enabled: plan.payment_enabled,
        admin_access: plan.admin_access,
        expires_after: plan.expires_after,
        trial_for: plan.trial_for,
        domain_enabled: plan.domain_enabled,
        domain_max: plan.domain_max,
        server_type: plan.settings?.server_type ?? 'separate',
        web_server: plan.web_server,
        database_server: plan.database_server,
        sso_server: plan.sso_server,
        shared_app: plan.shared_app,
        displayed_features: plan.features ? JSON.parse(JSON.stringify(plan.features)) : [],
        base: {
          price: plan.settings?.base?.price ?? 0,
          price_id: plan.settings?.base?.price_id ?? '',
          storage: plan.settings?.base?.storage ?? 0,
          max: plan.settings?.base?.max ?? 0
        },
        standard: {
          price: plan.settings?.standard?.price ?? 0,
          price_id: plan.settings?.standard?.price_id ?? '',
          storage: plan.settings?.standard?.storage ?? 0,
          max: plan.settings?.standard?.max ?? 0
        },
        basic: {
          name: plan.settings?.basic?.name ?? '',
          price: plan.settings?.basic?.price ?? 0,
          price_id: plan.settings?.basic?.price_id ?? '',
          storage: plan.settings?.basic?.storage ?? 0,
          max: plan.settings?.basic?.max ?? 0,
          amount: plan.settings?.basic?.amount ?? 0
        },
        storage: {
          price: plan.settings?.storage?.price ?? 0,
          price_id: plan.settings?.storage?.price_id ?? '',
          amount: plan.settings?.storage?.amount ?? 0,
          max: plan.settings?.storage?.max ?? 0
        }
      }
    }

    return {
      serverTypes: [
        { text: this.$t('admin.plans.serverTypeSeparate'), value: 'separate' },
        { text: this.$t('admin.plans.serverTypeShared'), value: 'shared' }
      ],
      form: useForm({
        plan_ids: this.plan_ids,
        plans: plansMap
      })
    }
  },
  methods: {
    submit () {
      this.form.post('/admin/apps/' + this.app.slug + '/plans/bulk-edit/edit')
    },
    addFeature (planId: number) {
      this.form.plans[planId].displayed_features.push({ name: '', description: '' })
    },
    removeFeature (planId: number, index: number) {
      this.form.plans[planId].displayed_features.splice(index, 1)
    }
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
  }

  .setting-col {
    min-width: 220px;
    position: sticky;
    left: 0;
    background: var(--va-background-primary);
    z-index: 1;
  }

  .plan-col {
    min-width: 220px;
  }

  .setting-label {
    min-width: 220px;
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
}
</style>
