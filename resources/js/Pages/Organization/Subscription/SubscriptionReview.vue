<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import CreditCard from '@/components/CreditCard.vue'
import PlanCard from '@/components/cards/PlanCard.vue'
import PricingCard from '@/components/cards/PricingCard.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Plan Review - Control Panel</title>
  </Head>
  <div class="row">
    <div class="flex flex-col xs12 lg8">
      <va-card clas="h-fit">
        <va-card-title>Options</va-card-title>
        <va-card-content>
          <credit-card v-if="plan.payment_enabled" v-model:hasDefaultPaymentMethod="hasDefaultPaymentMethod" />
          <template v-else>
            <div class="row m-5">
              <div class="flex flex-col xs12 va-text-center mt-4">
                <div>
                  <va-icon name="fa-thumbs-up" style="color: var(--va-list-item-label-caption-color)"  size="5rem" />
                </div>
              </div>
              <div class="flex flex-col xs12 va-text-center mb-4">
                <h2 class="va-h2" style="color: var(--va-list-item-label-caption-color)" >There's nothing left to do except subscribe!</h2>
              </div>
            </div>
          </template>
          <va-button v-if="!plan.payment_enabled || hasDefaultPaymentMethod" @click="form.post('/subscription/'+organization.id+'/plans/'+plan.id)">Subscribe</va-button>
        </va-card-content>
      </va-card>
    </div>

    <div class="flex flex-col xs12 lg4">
      <pricing-card :prices="prices" :total="total" :perApp="plan.type === 'app'" />
      <plan-card :plan="plan" />
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    organization: Object,
    plan: Object,
    prices: Object,
    total: Number
  },
  data () {
    return {
      hasDefaultPaymentMethod: false,
      form: useForm({
      })
    }
  }
}
</script>

<style lang="scss"></style>
