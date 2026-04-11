<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PlanLayout from './PlanLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>{{ t('admin.plans.editPlan') }} - Control Panel</title>
  </Head>
  <h3 class="va-h3">{{ t('admin.plans.features') }}</h3>
  <template v-if="features.length === 0">
    <div class="row m-5">
      <div class="flex lg12 va-text-center mt-4">
        <va-icon name="fa-puzzle-piece" style="color: var(--va-list-item-label-caption-color)"  size="5rem" />
      </div>
    </div>
    <div class="row">
      <div class="flex lg12 va-text-center mb-1">
        <h2 class="va-h2 mb-3 sm12" style="color: var(--va-list-item-label-caption-color)">{{ t('admin.plans.noFeatures') }}</h2>
      </div>
    </div>
  </template>
  <form v-else @submit.prevent="form.put('/admin/apps/'+app.slug+'/plans/'+plan.id+'/features')">
    <template v-for="(feature, index) in features" :key="index">
      <AdminSettings>
        <template #name>{{ feature.label }}</template>
        <template #description>{{ feature.description }}</template>
        <template #settings>
          <va-select
            v-model="form['features'][feature.value]['status']"
            :options="feature_options"
            :label="useI18n().t('options')"
            immediateValidation
            class="pb-2"
            value-by="value"
            text-by="text"
            />
          <template v-if="features[feature.value]['settings']">
            <template v-for="(setting, index) in feature.settings" :key="index">
              <va-input
                v-model="form['features'][feature.value]['settings'][setting.name]"
                immediateValidation
                :label="setting.label"
                class="pb-2"
              />
            </template>
          </template>
          <va-input
            v-model="form['features'][feature.value]['price']"
            type="number"
            :label="useI18n().t('admin.plans.price')"
            immediateValidation
            min="0"
            step=".01"
            class="pb-2"
          >
            <template #prependInner>
              {{ t('admin.plans.currencySymbol') }}
            </template>
          </va-input>
          <va-input
            v-model="form['features'][feature.value]['price_id']"
            :label="useI18n().t('admin.plans.productID')"
            immediateValidation
            class="pb-2"
          />
          <va-select
            v-model="form['features'][feature.value]['payment_type']"
            immediateValidation
            clearable
            value-by="value"
            text-by="text"
            :label="useI18n().t('admin.plans.howFeatureIsBilled')"
            :options="featurePaymentTypes"
            :error="$page.props.errors.payment_type"
            :error-messages="$page.props.errors.payment_type"
          />
        </template>
      </AdminSettings>
      <va-list-separator class="my-2" fit />
    </template>
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
    features: Object
  },
  data () {
    return {
      feature_options: [
        { text: useI18n().t('status.disabled'), value: 'disabled' },
        { text: useI18n().t('status.enabled'), value: 'enabled' },
        { text: useI18n().t('status.optional'), value: 'optional' }
      ],
      featurePaymentTypes: [
        { text: useI18n().t('admin.plans.perUser'), value: 'user' },
        { text: useI18n().t('admin.plans.addToBill'), value: 'addon' }
      ],
      form: useForm({
        features: this.plan.settings.features
      })
    }
  }
}
</script>
