<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PricingCard from '@/components/cards/PricingCard.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>Plans - Control Panel</title>
  </Head>
  <div class="row">
    <div class="flex flex-col xs12 lg8">
      <va-card class="mb-4 pb-2">
        <va-card-title>Plans</va-card-title>
        <va-card-content>
          <div class="va-title text-align-center text-color-primary">
            {{ organization.name }}
          </div>
          <table class="va-table va-table--hoverable mt-3">
            <thead>
              <tr>
                <th>Base/App Name</th>
                <th>Plan</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(plan, i) in plans" :key="i">
                <td v-if="plan.entity.id" class="py-3 vertical-middle">
                  <Link :href="'/apps/'+plan.entity.id+'/edit'">{{ plan.entity.name }}</Link>
                </td>
                <td v-else class="py-3 va-text-bold vertical-middle">
                  {{ plan.entity.name }}
                </td>
                <td class="vertical-middle">
                  {{ plan.name }} <span v-if="plan.status === 'retired'">(Retired)</span>
                </td>
                <td class="vertical-middle">
                  {{ plan.status }}
                </td>
                <td class="va-text-right vertical-middle">
                  <Link v-if="plan.can.change_plan" :href="plan.plans_url"><va-button color="primary" size="small" class="mr-3">{{ $t('plan.change') }}</va-button></Link>
                  <va-button v-if="plan.can.unsubscribe && plan.type === 'base'" color="danger" size="small" class="mr-3" @click="unsubscribe(plan, organization)">{{ $t('plan.unsubscribe') }}</va-button>
                  <va-button v-if="plan.can.resubscribe && plan.type === 'base'" color="success" size="small" class="mr-3" @click="resubscribe(plan, organization)">{{ $t('plan.resubscribe') }}</va-button>
                  <va-button v-if="plan.can.unsubscribe && plan.type === 'app'" color="danger" size="small" class="mr-3" @click="deactivate(plan, organization)">{{ $t('plan.deactivate') }}</va-button>
                  <va-button v-if="plan.can.resubscribe && plan.type === 'app'" color="success" size="small" class="mr-3" @click="reactivate(plan, organization)">{{ $t('plan.reactivate') }}</va-button>
                </td>
              </tr>
            </tbody>
          </table>
          <template v-for="(suborg, s) in suborgs" :key="s">
            <div class="mt-3 va-title text-align-center text-color-primary">
              {{ suborg.name }}
            </div>
            <table class="va-table va-table--hoverable mt-3">
              <thead>
                <tr>
                  <th>Base/App Name</th>
                  <th>Plan</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(plan, i) in suborg.plans" :key="i">
                  <td v-if="plan.entity.id" class="py-3 vertical-middle">
                    <Link :href="'/apps/'+plan.entity.id+'/edit'">{{ plan.entity.name }}</Link>
                  </td>
                  <td v-else class="py-3 va-text-bold vertical-middle">
                    {{ plan.entity.name }}
                  </td>
                  <td class="vertical-middle">
                    {{ plan.name }} <span v-if="plan.status === 'retired'">(Retired)</span>
                  </td>
                  <td class="vertical-middle">
                    {{ plan.status }}
                  </td>
                  <td class="va-text-right vertical-middle">
                    <Link v-if="plan.can.change_plan" :href="plan.plans_url"><va-button color="primary" size="small" class="mr-3">{{ $t('plan.change') }}</va-button></Link>
                    <va-button v-if="plan.can.unsubscribe && plan.type === 'base'" color="danger" size="small" class="mr-3" @click="unsubscribe(plan, suborg)">{{ $t('plan.unsubscribe') }}</va-button>
                    <va-button v-if="plan.can.resubscribe && plan.type === 'base'" color="success" size="small" class="mr-3" @click="resubscribe(plan, suborg)">{{ $t('plan.resubscribe') }}</va-button>
                    <va-button v-if="plan.can.unsubscribe && plan.type === 'app'" color="danger" size="small" class="mr-3" @click="deactivate(plan, suborg)">{{ $t('plan.deactivate') }}</va-button>
                    <va-button v-if="plan.can.resubscribe && plan.type === 'app'" color="success" size="small" class="mr-3" @click="reactivate(plan, suborg)">{{ $t('plan.reactivate') }}</va-button>
                  </td>
                </tr>
              </tbody>
            </table>
          </template>
        </va-card-content>
      </va-card>
      <va-modal v-model="showUnsubscribeModal" hide-default-actions title="Cancel subscription?"
        :message="$t('messages.unsubscribe', {plan: plan.name})">
        <template #footer>
          <va-button color="backgroundSecondary" @click="showUnsubscribeModal = false">
            Cancel
          </va-button>
          <va-button id="delete" color="danger"
            @click="cancel.delete('/subscription/'+selectedOrganization.id); showUnsubscribeModal = !showUnsubscribeModal">{{ $t('modal.unsubscribe') }}</va-button>
        </template>
      </va-modal>
      <va-modal v-model="showResubscribeModal" hide-default-actions title="Cancel Unsubscribe?"
        :message="$t('messages.resubscribe', {app: plan.entity.name})">
        <template #footer>
          <va-button color="backgroundSecondary" @click="showResubscribeModal = false">
            Cancel
          </va-button>
          <va-button id="delete" color="success"
            @click="cancel.post('/subscription/'+selectedOrganization.id+'/resubscribe'); showResubscribeModal = !showResubscribeModal">{{ $t('modal.resubscribe') }}</va-button>
        </template>
      </va-modal>
      <va-modal v-model="showDeactivateModal" hide-default-actions title="Deactivate App?"
        :message="$t('messages.deactivate', {app: plan.entity.name})">
        <template #footer>
          <va-button color="backgroundSecondary" @click="showDeactivateModal = false">
            Cancel
          </va-button>
          <va-button id="delete" color="danger"
            @click="cancel.delete('/apps/'+plan.entity.id); showDeactivateModal = !showDeactivateModal">{{ $t('modal.deactivate') }}</va-button>
        </template>
      </va-modal>
      <va-modal v-model="showReactivateModal" hide-default-actions title="Cancel Deactivating App?"
        :message="$t('messages.reactivate', {app: plan.entity.name})">
        <template #footer>
          <va-button color="backgroundSecondary" @click="showReactivateModal = false">
            Cancel
          </va-button>
          <va-button id="delete" color="success"
            @click="cancel.post('/apps/'+plan.entity.id+'/reactivate'); showReactivateModal = !showReactivateModal">{{ $t('modal.reactivate') }}</va-button>
        </template>
      </va-modal>
    </div>
    <div v-if="prices.length > 0" class="flex flex-col xs12 lg4">
      <pricing-card :prices="prices" />
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    organization: Object,
    plans: Object,
    prices: Object,
    suborgs: Object
  },
  data () {
    return {
      showUnsubscribeModal: false,
      showResubscribeModal: false,
      showDeactivateModal: false,
      showReactivateModal: false,
      plan: {
        entity: {}
      },
      cancel: useForm({}),
      selectedOrganization: {}
    }
  },
  methods: {
    unsubscribe (plan, org) {
      this.showUnsubscribeModal = true
      this.plan = plan
      this.selectedOrganization = org
    },
    resubscribe (plan, org) {
      this.showResubscribeModal = true
      this.plan = plan
      this.selectedOrganization = org
    },
    deactivate (plan, org) {
      this.showDeactivateModal = true
      this.plan = plan
      this.selectedOrganization = org
    },
    reactivate (plan, org) {
      this.showReactivateModal = true
      this.plan = plan
      this.selectedOrganization = org
    }
  }
}
</script>

<style lang="scss">
  .va-table td.vertical-middle {
    vertical-align: middle
  }
  .text-color-primary {
    color: var(--va-primary);
    text-align: center;
  }
</style>
