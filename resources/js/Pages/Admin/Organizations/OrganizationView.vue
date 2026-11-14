<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import OrganizationLayout from './OrganizationLayout.vue'

</script>

<template>
  <Head>
    <title>{{ $t('admin.organizations.organizationOverview') }} - Control Panel</title>
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
            <va-list-label class="va-text-left">{{ $t('admin.organizations.address') }}</va-list-label>
            <div class="mb-3">
              <p class="mb-1">{{ organization.street }}</p>
              <p class="mb-1">{{ organization.zipcode }}</p>
              <p>{{ organization.city }}, {{ organization.state }}, {{ organization.country }}</p>
            </div>
          </div>
          <div>
            <va-list-label class="va-text-left">{{ $t('admin.organizations.primaryContact') }}</va-list-label>
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
          <va-list-label>{{ $t('admin.organizations.appsAndPlans') }}</va-list-label>
          <va-list-item
            class="list__item my-3"
            >
          <va-list-item-section>
            <va-list-item-label>
              {{ $t('admin.organizations.basePlan') }}
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
              <Link :href="'/admin/apps/'+app.id+'/plans/'+app.plan.id">{{ app.plan.name }}<span v-if="app.plan.status == 'retired'">({{ $t('admin.organizations.retired') }})</span></Link>
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
        <div class="row">
          <div class="flex flex-col xs12">
            <va-button preset="secondary" id="changePlan" class="mb-3" @click="openChangePlan">{{ $t('admin.organizations.changePlan') }}</va-button>
          </div>
        </div>
        <div class="row">
          <div class="flex flex-col xs12">
            <va-alert
              v-if="organization.deletion_scheduled_at"
              id="deletionScheduled"
              color="danger"
              border="left"
              class="mb-3"
            >
              {{ $t('admin.organizations.deletionScheduledFor', { date: formatDate(organization.deletion_scheduled_at) }) }}
            </va-alert>
            <div v-if="organization.deletion_scheduled_at" class="flex flex-wrap gap-2 mb-3">
              <va-button preset="secondary" color="danger" id="editDeletionDate" @click="openDeactivate">{{ $t('admin.organizations.editDeletionDate') }}</va-button>
              <va-button preset="secondary" id="notifyAdmins" :loading="notifyForm.processing" @click="notifyAdmins">{{ $t('admin.organizations.notifyAdmins') }}</va-button>
              <va-button preset="secondary" id="cancelDeletion" @click="cancelDeletion">{{ $t('admin.organizations.cancelDeletion') }}</va-button>
            </div>
            <va-button v-else preset="secondary" color="danger" id="deactivateOrganization" class="mb-3" @click="openDeactivate">{{ $t('admin.organizations.deactivateOrganization') }}</va-button>
          </div>
        </div>
        <div v-if="subscription_stats.length > 0" class="row">
          <div class="flex flex-col xs12">
            <form @submit.prevent="subscription.post('/admin/organizations/'+organization.id+'/update_subscription')">
              <va-input v-model="subscription.discount_code"
                :label="$t('admin.organizations.discountCode')"
                class="mb-3"
                id="discount_code"
                immediateValidation
                :error="$page.props.errors.discount_code"
                :error-messages="$page.props.errors.discount_code"
              />
              <va-button type="submit" id="updateSubscription" class="mb-2">{{ $t('admin.organizations.updateSubscription') }}</va-button>
            </form>
            <form v-if="organization.status === 'deactivated'" @submit.prevent="destroy.delete('/admin/organizations/'+organization.id)">
              <va-button type="submit" color="danger" id="updateSubscription">{{ $t('admin.organizations.permanentlyDelete') }}</va-button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <va-modal
      v-model="showChangePlan"
      :title="$t('admin.organizations.changePlan')"
      :ok-text="$t('admin.organizations.changePlan')"
      :ok-disabled="!planForm.plan_id"
      size="small"
      @ok="submitChangePlan"
    >
      <p class="plan-modal__intro">{{ $t('admin.organizations.changePlanDescription', { name: organization.name }) }}</p>
      <div class="plan-list" id="availablePlans" role="radiogroup">
        <label
          v-for="plan in available_plans"
          :key="plan.id"
          class="plan-option"
          :class="{
            'plan-option--selected': planForm.plan_id === plan.id,
            'plan-option--disabled': plan.current || plan.disabled_reason
          }"
          :title="plan.disabled_reason || undefined"
        >
          <input
            type="radio"
            name="plan_id"
            class="plan-option__radio"
            :value="plan.id"
            :disabled="plan.current || !!plan.disabled_reason"
            v-model="planForm.plan_id"
          />
          <span class="plan-option__body">
            <span class="plan-option__header">
              <span class="plan-option__name">{{ plan.name }}</span>
              <va-badge
                v-if="plan.current"
                color="primary"
                :text="$t('admin.organizations.currentPlan')"
              />
              <va-badge
                v-if="plan.org_type !== organization.type"
                color="info"
                :text="plan.org_type"
              />
              <va-badge
                :color="visibilityColors[plan.visibility]"
                :text="$t('admin.organizations.planVisibility.' + plan.visibility)"
              />
            </span>
            <span v-if="plan.description" class="plan-option__description">{{ plan.description }}</span>
            <span v-if="plan.disabled_reason && !plan.current" class="plan-option__reason">
              <va-icon name="info" size="small" />
              {{ plan.disabled_reason }}
            </span>
          </span>
        </label>
      </div>
      <va-alert v-if="$page.props.errors.plan_id" color="danger" class="mt-4" border="left">
        {{ $page.props.errors.plan_id }}
      </va-alert>
    </va-modal>

    <va-modal
      v-model="showDeactivate"
      :title="$t('admin.organizations.deactivateOrganization')"
      :ok-text="$t('admin.organizations.scheduleDeletion')"
      :ok-disabled="!deactivateForm.date"
      size="small"
      @ok="submitDeactivate"
    >
      <p class="plan-modal__intro">{{ $t('admin.organizations.deactivateOrganizationDescription', { name: organization.name }) }}</p>
      <va-alert color="danger" border="left" class="mb-4" id="deletionWarning">
        <strong>{{ $t('admin.organizations.deletionWarningTitle') }}</strong><br />
        {{ $t('admin.organizations.deletionWarning', { name: organization.name }) }}
      </va-alert>
      <div class="flex items-end gap-2 mb-2">
        <va-date-input
          v-model="deactivateDate"
          id="deletionDate"
          :label="$t('admin.organizations.deletionDate')"
          :error="!!$page.props.errors.date"
          :error-messages="$page.props.errors.date"
          @update:modelValue="updateDeactivateDate"
        />
        <va-button preset="secondary" id="setDeletionNow" @click="setDeletionNow">{{ $t('admin.organizations.setToNow') }}</va-button>
      </div>
    </va-modal>
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
    available_plans: Array,
    subscription_stats: Object
  },
  data () {
    return {
      subscription: useForm({
        discount_code: this.base_plan.discount_id
      }),
      destroy: useForm({}),
      showChangePlan: false,
      showDeactivate: false,
      deactivateDate: null,
      deactivateForm: useForm({
        date: null,
        now: false
      }),
      notifyForm: useForm({}),
      planForm: useForm({
        plan_id: null
      }),
      visibilityColors: {
        public: 'success',
        hidden: 'warning',
        archived: 'secondary'
      }
    }
  },
  methods: {
    openChangePlan () {
      this.planForm.reset()
      this.planForm.clearErrors()
      this.showChangePlan = true
    },
    formatDate (value) {
      return new Date(value).toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
    },
    defaultDeletionDate () {
      const date = new Date()
      date.setDate(date.getDate() + 30)
      return date
    },
    openDeactivate () {
      this.deactivateForm.clearErrors()
      this.setDeletionDate(this.organization.deletion_scheduled_at
        ? new Date(this.organization.deletion_scheduled_at)
        : this.defaultDeletionDate())
      this.showDeactivate = true
    },
    setDeletionDate (date) {
      this.deactivateDate = date
      this.deactivateForm.date = date.toISOString()
      this.deactivateForm.now = false
    },
    setDeletionNow () {
      // The server works out the moment: it starts when this is confirmed, not now
      this.setDeletionDate(new Date())
      this.deactivateForm.now = true
    },
    updateDeactivateDate (date) {
      this.deactivateForm.now = false
      this.deactivateForm.date = date ? new Date(date).toISOString() : null
    },
    submitDeactivate () {
      this.deactivateForm.post('/admin/organizations/' + this.organization.id + '/schedule_deletion', {
        preserveScroll: true,
        onSuccess: () => {
          this.showDeactivate = false
        },
        onError: () => {
          this.showDeactivate = true
        }
      })
    },
    notifyAdmins () {
      this.notifyForm.post('/admin/organizations/' + this.organization.id + '/notify_deletion', {
        preserveScroll: true
      })
    },
    cancelDeletion () {
      this.notifyForm.delete('/admin/organizations/' + this.organization.id + '/schedule_deletion', {
        preserveScroll: true
      })
    },
    submitChangePlan () {
      this.planForm.post('/admin/organizations/' + this.organization.id + '/change_plan', {
        preserveScroll: true,
        onSuccess: () => {
          this.showChangePlan = false
        },
        onError: () => {
          // Keep the modal open so the reason is visible
          this.showChangePlan = true
        }
      })
    }
  }
}
</script>

<style>
  .organization {
    width: 100%;
  }

  .plan-modal__intro {
    margin-bottom: 1rem;
    color: var(--va-text-secondary);
  }

  .plan-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    max-height: 50vh;
    overflow-y: auto;
    padding: 2px;
  }

  .plan-option {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border: 1px solid var(--va-background-border);
    border-radius: 0.5rem;
    cursor: pointer;
    transition: border-color 0.15s, background-color 0.15s;
  }

  .plan-option:hover:not(.plan-option--disabled) {
    border-color: var(--va-primary);
  }

  .plan-option--selected {
    border-color: var(--va-primary);
    background-color: color-mix(in srgb, var(--va-primary) 8%, transparent);
    box-shadow: 0 0 0 1px var(--va-primary);
  }

  .plan-option--disabled {
    cursor: not-allowed;
    opacity: 0.6;
    background-color: var(--va-background-element);
  }

  .plan-option__radio {
    margin-top: 0.3rem;
    accent-color: var(--va-primary);
  }

  .plan-option__body {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 0.25rem;
    min-width: 0;
  }

  .plan-option__header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
  }

  .plan-option__name {
    font-weight: 600;
  }

  .plan-option__description {
    font-size: 0.875rem;
    color: var(--va-text-secondary);
  }

  .plan-option__reason {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.8125rem;
    color: var(--va-danger);
  }
</style>
