<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link } from '@inertiajs/vue3'

</script>

<template>
  <Head>
    <title>{{ $t('organization.subscription.planOverview') }} - Control Panel</title>
  </Head>
  <va-card>
    <va-card-title>{{ $t('organization.subscription.upcomingInvoice') }}</va-card-title>
    <va-card-content>
      <div class="row justify-center">
        <div class="flex lg3 xs12">
          <div class="row my-0 py-0">
            <div class="flex flex-col xs12 va-text-center my-0 py-0">
              <h1 class="va-h1 my-0 py-0">{{ upcoming_invoice.due_date }}</h1>
            </div>
            <div class="flex flex-col xs12 va-text-center va-text-bold">
              {{ $t('organization.subscription.dueDate') }}
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
              {{ $t('organization.subscription.amountDue') }}
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
              {{ $t('organization.subscription.subscriptionStatus') }}
            </div>
          </div>
        </div>
      </div>
      <div class="row va-text-center payment_note">
        <div class="flex flex-col xs12">
          {{ $t('organization.subscription.paymentNote') }}
        </div>
      </div>
    </va-card-content>
  </va-card>
  <div class="row">
    <div class="flex lg6 xs12">
      <va-card class="mb-4">
        <va-card-title>{{ $t('organization.subscription.organizationSummary') }}</va-card-title>
        <va-card-content>
          <va-scroll-container horizontal>
            <table class="va-table va-table--striped mb-3">
              <thead>
                <tr>
                  <th>{{ $t('organization.organization') }}</th>
                  <th>{{ $t('organization.subscription.billed') }}</th>
                  <th style="20rem">{{ $t('organization.subscription.total') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(organization, index) in organizations"
                  :key="index">
                  <td><Link :href="'/subscription/'+organization.id">{{ organization.name }}</Link></td>
                  <td>{{ organization.billing_type }}</td>
                  <td>${{ organization.total }}</td>
                </tr>
              </tbody>
            </table>
          </va-scroll-container>
        </va-card-content>
      </va-card>
    </div>
    <div class="flex sm12 lg6 xs12">
      <va-card class="mb-4">
        <va-card-title>{{ $t('organization.subscription.pastInvoices') }}</va-card-title>
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
    organizations: Object,
    invoices: Object,
    upcoming_invoice: Object
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
</style>
