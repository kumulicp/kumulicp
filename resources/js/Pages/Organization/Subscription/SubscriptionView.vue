<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>Plan Overview - Control Panel</title>
  </Head>
  <va-card>
    <va-card-title>Upcoming Invoice</va-card-title>
    <va-card-content>
      <div class="row justify-center">
        <div class="flex lg3 xs12">
          <div class="row my-0 py-0">
            <div class="flex flex-col xs12 va-text-center my-0 py-0">
              <h1 class="va-h1 my-0 py-0">{{ upcoming_invoice.due_date }}</h1>
            </div>
            <div class="flex flex-col xs12 va-text-center va-text-bold">
              Due date
            </div>
          </div>
        </div>
        <va-divider vertical />
        <div class="flex lg3 xs12">
          <div class="row my-0 py-0">
            <div class="flex flex-col xs12 va-text-center my-0 py-0">
            <h1 class="va-h1 my-0 py-0">{{ upcoming_invoice.amount_due }}</h1>
            </div>
            <div class="flex flex-col xs12 va-text-center va-text-bold">
              Amount due
            </div>
          </div>
        </div>
        <va-divider vertical />
        <div class="flex lg3 xs12">
          <div class="row my-0 py-0">
            <div class="flex flex-col xs12 va-text-center my-0 py-0">
              <h1 class="va-h1 my-0 py-0">{{ upcoming_invoice.status }}</h1>
            </div>
            <div class="flex flex-col xs12 va-text-center va-text-bold">
              Subscription Status
            </div>
          </div>
        </div>
      </div>
      <div class="row va-text-center payment_note">
        <div class="flex flex-col xs12">
          *Note: Payments will come out automatically from the credit card in your billing info
        </div>
      </div>
    </va-card-content>
  </va-card>
  <div class="row">
    <div class="flex sm12 lg6 xs12">
      <va-card class="mb-4 pb-2">
        <va-card-title>Invoice Summary</va-card-title>
        <va-card-content>
          <template v-for="(item, index) in stats" :key="index">
            <div class="va-title text-align-center">
              {{ item.name }}
            </div>
            <table class="va-table va-table--striped mb-2">
              <thead>
                <tr>
                  <th>Description</th>
                  <th>Quantity</th>
                  <th>Price</th>
                  <th>For Every</th>
                  <th>Total</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(stat, index) in item.stats"
                  :key="index">
                  <td style="width: 20rem">{{ stat.label }}</td>
                  <td>{{ stat.quantity }}</td>
                  <td><span v-if="stat.price">$</span>{{ stat.price }}</td>
                  <td>{{ stat.unit }}</td>
                  <td>${{ stat.total_price }}</td>
                </tr>
              </tbody>
            </table>
          </template>
          <div v-if="discount.type === 'amount' || discount.type === 'percent'"
            class="row">
            <div class="flex xs12 bold">
              Discount: {{ discount.amount }}
            </div>
          </div>
        </va-card-content>
      </va-card>
    </div>
    <div class="flex sm12 lg6 xs12">
      <va-card class="mb-4 pb-2">
        <va-card-title>Past Invoices</va-card-title>
        <va-card-content>
          <va-list>
            <va-list-item
              v-for="(invoice, index) in invoices"
              :key="index"
              class="list__item"
              >
              <va-list-item-section>
                <va-list-item-label>
                <a :href="invoice.download">{{ invoice.created }}</a>
                </va-list-item-label>
              </va-list-item-section>
              <va-list-item-section>
                <va-list-item-label>
                  {{ invoice.total }}
                </va-list-item-label>
              </va-list-item-section>
              <va-list-item-section icon>
                {{ invoice.status }}
              </va-list-item-section>
              <va-divider />
            </va-list-item>
          </va-list>
        </va-card-content>
      </va-card>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    stats: Object,
    invoices: Object,
    upcoming_invoice: Object,
    discount: Object
  }
}
</script>

<style>
  .payment_note {
    font-size: 12px
  }
  .align-center {
    text-align: center
  }
  .bold {
    font-weight: bold
  }
</style>
