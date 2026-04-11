<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import AppCustomizations from '@/components/App/AppCustomizations.vue'
import CreditCard from '@/components/CreditCard.vue'
import PlanCard from '@/components/cards/PlanCard.vue'
import PricingCard from '@/components/cards/PricingCard.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>{{ app.label }} Subscription Overview - Control Panel</title>
  </Head>
  <div class="discover-overview">
    <div class="row">
      <div class="flex xs12 lg9">
        <va-card class="mb-3">
          <va-card-title>{{ app.label }} Plan Settings</va-card-title>
          <va-card-content>
            <credit-card v-model:hasDefaultPaymentMethod="hasDefaultPaymentMethod" v-if="plan.payment_enabled" />
            <va-list-separator class="my-3" fit />
            <form @submit.prevent="form.put('/apps/'+app.id+'/plans/'+plan.id+'/select')">
              <app-customizations :customizations="customizations" :customizations_form="form.customizations" @update:customizations="updateCustomizations($event)" />
              <va-button type="submit">Change Plan</va-button>
            </form>
          </va-card-content>
        </va-card>
      </div>

      <div class="flex sm12 lg3">
        <div class="row">
          <div class="flex lg12">
            <plan-card :plan="plan" :app="app" />
          </div>
          <div class="flex lg12">
            <pricing-card :prices="prices" :total="total" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    app: Object,
    plan: Object,
    customizations: Object,
    prices: Object,
    total: Number
  },
  data () {
    const customizationsForm = {}
    Object.keys(this.customizations).forEach((name) => {
      customizationsForm[name] = false
    })

    return {
      form: useForm({
        customizations: customizationsForm
      })
    }
  },
  methods: {
    updateCustomizations (event) {
      this.form.customizations = event
    }
  }
}
</script>

<style lang="scss"></style>
