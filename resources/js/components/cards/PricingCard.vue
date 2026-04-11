<script setup lang="ts">
const formatter = new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: 'USD'

  // These options are needed to round to whole numbers if that's what you want.
  // minimumFractionDigits: 0, // (this suffices for whole numbers, but will print 2500.10 as $2,500.1)
  // maximumFractionDigits: 0, // (causes 2500.99 to be printed as $2,501)
})
</script>
<template>
  <va-card class="mb-4 pb-2">
    <va-card-title>Pricing</va-card-title>
    <va-card-content>
      <va-list v-for="(price, index) in prices" :key="index">
        <va-list-label>{{ price.name }}</va-list-label>
        <va-list-item
          v-for="(stat, index) in price.stats"
          class="list__item"
          :key="index"
          >
          <va-list-item-section>
            <va-list-item-label>
              {{ stat.label }}
            </va-list-item-label>
          </va-list-item-section>
          <va-list-item-section>
            <va-list-item-label>
              {{ formatter.format(stat.price) }} <span v-if="stat.unit">/ {{ stat.unit }} </span>
            </va-list-item-label>
          </va-list-item-section>
        </va-list-item>
        <va-divider />
      </va-list>
      <va-list v-if="total">
        <va-list-item>
          <va-list-item-section>
            <va-list-item-label>
              <b>Total</b>
            </va-list-item-label>
          </va-list-item-section>
          <va-list-item-section>
            <va-list-item-label>
              <b>{{ formatter.format(total) }}</b>
            </va-list-item-label>
          </va-list-item-section>
        </va-list-item>
      </va-list>
      <div v-if="perApp" class="row mt-2">
        <div class="flex flex-col xs12" style="color: var(--va-secondary); font-weight: bold">
          Pricing varies depending on the plan you choose for each app you activate.
        </div>
      </div>
    </va-card-content>
  </va-card>
</template>
<script lang="ts">
export default {
  props: {
    perApp: {
      type: Boolean,
      required: false,
      default: false
    },
    prices: Object,
    total: Number
  }
}
</script>
