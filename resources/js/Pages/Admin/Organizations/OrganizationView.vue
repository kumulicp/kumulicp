<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import OrganizationLayout from './OrganizationLayout.vue'

</script>

<template>
  <Head>
    <title>{{ t('admin.organizations.organizationOverview') }} - Control Panel</title>
  </Head>
  <div class="organization-view">
    <div class="flex justify-between">
      <div class="justify-self-start">
        <VaBadge
          overlap
          :text="organization.status"
          :offset="[30, 30]"
          color="primary"
        >
          <h2 class="va-h2">{{ organization.name }}</h2>
        </VaBadge>
        <p>{{ organization.description }}</p>
      </div>
      <div class="">
        <div class="flex justify-self-end">
          <div>
            <va-list-label class="va-text-left">{{ t('admin.organizations.address') }}</va-list-label>
            <div class="mb-3">
              <p class="mb-1">{{ organization.street }}</p>
              <p class="mb-1">{{ organization.zipcode }}</p>
              <p>{{ organization.city }}, {{ organization.state }}, {{ organization.country }}</p>
            </div>
          </div>
          <div>
            <va-list-label class="va-text-left">{{ t('admin.organizations.primaryContact') }}</va-list-label>
            <div>
              <p class="mb-1">{{ organization.contact_name }}</p>
              <p class="mb-1">{{ organization.contact_email }}</p>
              <p class="mb-1">{{ organization.contact_phone_number }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
    <va-list-separator class="mb-3" fit />
    <div class="row justify-space-evenly">
      <div class="flex lg6 md12 sm12 xs12">
        <va-list>
          <va-list-label>{{ t('admin.organizations.appsAndPlans') }}</va-list-label>
          <va-list-item
            class="list__item my-3"
            >
          <va-list-item-section>
            <va-list-item-label>
              {{ t('admin.organizations.basePlan') }}
            </va-list-item-label>
          </va-list-item-section>
          <va-list-item-section>
            <va-list-item-label>
              <Link :href="'/admin/service/plans/'+base_plan.id">{{ base_plan.name }}</Link>
            </va-list-item-label>
          </va-list-item-section>
        </va-list-item>
        <va-list-separator fit v-if="(key+1) != apps.length" />
        <template
          v-for="(app, key) in apps" :key="key">
        <va-list-item
          class="list__item my-3"
          >
          <va-list-item-section >
            <va-list-item-label>
              <Link :href="'/admin/organizations/'+organization.id+'/apps/'+app.id">{{ app.name }}</Link>
            </va-list-item-label>
          </va-list-item-section>
          <va-list-item-section>
            <va-list-item-label>
              <Link :href="'/admin/apps/'+app.id+'/plans/'+app.plan.id">{{ app.plan.name }}<span v-if="app.plan.status == 'retired'">({{ t('admin.organizations.retired') }})</span></Link>
            </va-list-item-label>
          </va-list-item-section>
        </va-list-item>
        <va-list-separator fit v-if="(key+1) != apps.length" />
        </template>
      </va-list>
      </div>
      <va-divider vertical />
      <div class="flex flex-col lg5 xs12">
        <div class="row">
          <div class="flex flex-col xs12">
            <template v-for="(item, key) in subscription_stats" :key="key">
              <va-list>
                <va-list-label>{{ item.name }}</va-list-label>
                <va-list-item
                  v-for="(stat, key) in item.stats"
                  class="list__item"
                  :key="key"
                  >
                  <va-list-item-section>
                    <va-list-item-label>
                    {{ stat.label }}
                    </va-list-item-label>
                  </va-list-item-section>
                  <va-list-item-section>
                    <va-list-item-label>
                      <span v-if="stat.calculation">{{ stat.calculation }}</span>
                    </va-list-item-label>
                  </va-list-item-section>
                  <va-list-item-section icon>
                    ${{ stat.total_price }}
                  </va-list-item-section>
                </va-list-item>
              </va-list>
              <va-divider v-if="(key+1) != item.stats.length"/>
            </template>
          </div>
        </div>
        <div v-if="subscription_stats.length > 0" class="row">
          <div class="flex flex-col xs12">
            <form @submit.prevent="subscription.post('/admin/organizations/'+organization.id+'/update_subscription')">
              <va-input v-model="subscription.discount_code"
                :label="t('admin.organizations.discountCode')"
                class="mb-3"
                id="discount_code"
                immediateValidation
                :error="$page.props.errors.discount_code"
                :error-messages="$page.props.errors.discount_code"
              />
              <va-button type="submit" id="updateSubscription" class="mb-2">{{ t('admin.organizations.updateSubscription') }}</va-button>
            </form>
            <form v-if="organization.status === 'deactivated'" @submit.prevent="destroy.delete('/admin/organizations/'+organization.id)">
              <va-button type="submit" color="danger" id="updateSubscription">{{ t('admin.organizations.permanentlyDelete') }}</va-button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(OrganizationLayout, () => page))
  },
  props: {
    organization: Object,
    apps: Object,
    base_plan: Object,
    subscription_stats: Object
  },
  data () {
    return {
      subscription: useForm({
        discount_code: this.base_plan.discount_id
      }),
      destroy: useForm({})
    }
  }
}
</script>

<style>
  .organization {
    width: 100%;
  }
</style>
