<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import BulkEditLayout from './BulkEditLayout.vue'
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.plans.bulkEdit') }} - Control Panel</title>
  </Head>
  <template v-if="features.length === 0">
    <div class="row m-5">
      <div class="flex lg12 va-text-center mt-4">
        <va-icon name="fa-puzzle-piece" style="color: var(--va-list-item-label-caption-color)" size="5rem" />
      </div>
    </div>
    <div class="row">
      <div class="flex lg12 va-text-center mb-1">
        <h2 class="va-h2 mb-3 sm12" style="color: var(--va-list-item-label-caption-color)">{{ $t('admin.plans.noFeatures') }}</h2>
      </div>
    </div>
  </template>
  <form v-else @submit.prevent="submit">
    <va-scroll-container horizontal>
      <table class="va-table bulk-edit-table">
        <thead>
          <tr>
            <th class="setting-col">{{ $t('admin.plans.setting') }}</th>
            <th v-for="plan in plans" :key="plan.id" class="plan-col">{{ plan.name }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="(feature, fi) in features" :key="fi">
            <!-- Feature section header -->
            <tr class="section-header">
              <td :colspan="plans.length + 1">
                <strong>{{ feature.label }}</strong>
                <span v-if="feature.description" class="va-text-secondary ml-2" style="font-weight:normal;font-size:0.85em">— {{ feature.description }}</span>
              </td>
            </tr>

            <!-- Status -->
            <tr>
              <td class="setting-label">{{ $t('admin.plans.featureStatus') }}</td>
              <td v-for="plan in plans" :key="plan.id">
                <va-select
                  v-model="form.plans[plan.id].features[feature.value].status"
                  :options="feature_options"
                  value-by="value"
                  text-by="text"
                  immediateValidation
                />
              </td>
            </tr>

            <!-- Feature-specific settings -->
            <template v-if="feature.settings?.length">
              <template v-for="(setting, index) in feature.settings" :key="index">
                <tr>
                  <td class="setting-label pl-4">{{ setting.label }}</td>
                  <td v-for="plan in plans" :key="plan.id">
                    <va-input
                      v-model="form.plans[plan.id].features[feature.value].settings[setting.name]"
                      immediateValidation
                    />
                  </td>
                </tr>
              </template>
            </template>

            <!-- Price -->
            <tr>
              <td class="setting-label">{{ $t('admin.plans.price') }}</td>
              <td v-for="plan in plans" :key="plan.id">
                <va-input
                  v-model="form.plans[plan.id].features[feature.value].price"
                  type="number"
                  min="0"
                  step=".01"
                  immediateValidation
                >
                  <template #prependInner>{{ $t('admin.plans.currencySymbol') }}</template>
                </va-input>
              </td>
            </tr>

            <!-- Product ID -->
            <tr>
              <td class="setting-label">{{ $t('admin.plans.productID') }}</td>
              <td v-for="plan in plans" :key="plan.id">
                <va-input
                  v-model="form.plans[plan.id].features[feature.value].price_id"
                  immediateValidation
                />
              </td>
            </tr>

            <!-- Payment Type -->
            <tr>
              <td class="setting-label">{{ $t('admin.plans.howFeatureIsBilled') }}</td>
              <td v-for="plan in plans" :key="plan.id">
                <va-select
                  v-model="form.plans[plan.id].features[feature.value].payment_type"
                  :options="featurePaymentTypes"
                  value-by="value"
                  text-by="text"
                  clearable
                  immediateValidation
                />
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </va-scroll-container>
    <va-button type="submit" class="mr-2 mb-2 mt-3" :disabled="form.processing">{{ $t('common.update') }}</va-button>
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
    features: Array,
    errors: Object
  },
  data () {
    const plansMap: Record<number, any> = {}
    for (const plan of this.plans) {
      const planFeatures: Record<string, any> = {}
      for (const feature of Object.values(this.features)) {
        const existing = plan.settings?.features?.[feature.value] ?? {}
        const settingsMap: Record<string, any> = {}
        if (feature.settings?.length) {
          for (const s of feature.settings) {
            settingsMap[s.name] = existing.settings?.[s.name] ?? ''
          }
        }
        planFeatures[feature.value] = {
          status: existing.status ?? 'disabled',
          price: existing.price ?? 0,
          price_id: existing.price_id ?? '',
          payment_type: existing.payment_type ?? null,
          settings: settingsMap
        }
      }
      plansMap[plan.id] = { features: planFeatures }
    }

    return {
      feature_options: [
        { text: this.$t('status.disabled'), value: 'disabled' },
        { text: this.$t('status.enabled'), value: 'enabled' },
        { text: this.$t('status.optional'), value: 'optional' }
      ],
      featurePaymentTypes: [
        { text: this.$t('admin.plans.perUser'), value: 'user' },
        { text: this.$t('admin.plans.addToBill'), value: 'addon' }
      ],
      form: useForm({
        plan_ids: this.plan_ids,
        plans: plansMap
      })
    }
  },
  methods: {
    submit () {
      this.form.put('/admin/apps/' + this.app.slug + '/plans/bulk-edit/features')
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

  .pl-4 {
    padding-left: 2rem !important;
  }
}
</style>
