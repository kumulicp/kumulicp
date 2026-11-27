<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PlanCard from '@/components/cards/PlanCard.vue'
import AppReadiness from '@/components/App/AppReadiness.vue'
import ScreenshotGallery from '@/components/ScreenshotGallery.vue'
import { Link, router } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('organization.discover.discoverApp') }} - Control Panel</title>
  </Head>
  <div class="discover-app">
    <div class="row">
      <div :class="'flex xs12 '+card_size">
        <va-card class="mb-4">
          <va-card-title>{{ app.name }}</va-card-title>
          <va-card-content>
            <app-readiness :readiness="readiness" />
            <va-alert
              v-if="authorization.deny.message && authorization.deny.code !== 'app_not_ready'"
              color="primary"
              outline
              class="mb-4"
            >
              <template #icon>
                <va-icon name="info" color="primary" />
              </template>
              <template v-if="authorization.deny.message">
                <div style="float:left" class="pt-1 pb-1">
                  {{ authorization.deny.message }}
                </div>
                <div v-if="authorization.deny.code == 'missing_parent_app'">
                  <Link :href="'/discover/'+app.parent_app.slug">
                    <va-button
                      preset="primary"
                      class="ml-4"
                      size="small"
                    >
                      {{ $t('organization.discover.activateParentApp', { name: app.parent_app.name }) }}
                    </va-button>
                  </Link>
                </div>
                <div v-else-if="authorization.deny.code == 'plan_limit_reached'">
                  <Link href="/subscription/options">
                    <va-button
                      preset="primary"
                      class="ml-4"
                      size="small"
                    >
                      {{ $t('organization.discover.upgradePlanHere') }}
                    </va-button>
                  </Link>
                </div>
              </template>
            </va-alert>
            <div id="description" v-html="app.description" style="overflow-x: auto; overflow-y: hidden"></div>
            <screenshot-gallery :screenshots="app.screenshots" class="mb-3" />
            <va-divider class="mb-3"></va-divider>
            <div v-if="can.activate" class="row">
              <div class="flex flex-col xs12">
                <div v-if="can.has_suborganizations && !can.choose_organization" id="activating-for" class="mb-3 va-text-secondary">
                  <va-icon name="info" size="small" class="mr-1" />
                  {{ $t('organization.discover.activatingFor', { name: target.name }) }}.
                  {{ $t('organization.discover.otherOrganizationsUnavailable') }}
                </div>
                <template v-if="can.choose_organization">
                  <div>
                    <va-button id="select-plan" class="mb-3" @click="openOrganizationModal">
                      {{ app.plan_count == 1 ? $t('organization.discover.select') : $t('organization.discover.chooseYourPlan') }}
                    </va-button>
                  </div>
                </template>
                <template v-else>
                  <Link v-if="app.plan_count == 1" id="select-plan" :href="plan.url"><va-button class="mb-3">{{ $t('organization.discover.select') }}</va-button></Link>
                  <Link v-if="app.plan_count > 1" :href="app.plans_url"><va-button class="mb-3">{{ $t('organization.discover.chooseYourPlan') }}</va-button></Link>
                </template>
              </div>
            </div>
          </va-card-content>
        </va-card>
      </div>
      <div v-if="can.activate && app.plan_count == 1" class="flex xs12 lg3">
        <plan-card :plan="plan" select :emit-select="can.choose_organization" @select="openOrganizationModal" />
      </div>
    </div>
    <va-modal
      v-model="showOrganizationModal"
      :title="$t('organization.discover.chooseOrganization')"
      :ok-text="$t('organization.discover.continue')"
      :ok-disabled="!selectedOrganization"
      size="small"
      @ok="continueWithOrganization"
    >
      <p class="org-modal__intro">{{ $t('organization.discover.chooseOrganizationMessage') }}</p>
      <div class="org-list" id="availableOrganizations" role="radiogroup">
        <label
          v-for="option in organizations"
          :key="option.id"
          class="org-option"
          :class="{
            'org-option--selected': selectedOrganization === option.id,
            'org-option--disabled': option.disabled
          }"
          :title="option.note || undefined"
        >
          <input
            type="radio"
            name="organization"
            class="org-option__radio"
            :value="option.id"
            :disabled="option.disabled"
            v-model="selectedOrganization"
          />
          <span class="org-option__body">
            <span class="org-option__name">{{ option.name }}</span>
            <span v-if="option.note" class="org-option__reason">
              <va-icon name="info" size="small" />
              {{ option.note }}
            </span>
          </span>
        </label>
      </div>
    </va-modal>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    organization: Object,
    organizations: Array,
    target: Object,
    app: Object,
    plan: Object,
    readiness: Object,
    can: Object,
    authorization: Object
  },
  data () {
    return {
      showOrganizationModal: false,
      selectedOrganization: null
    }
  },
  methods: {
    openOrganizationModal () {
      this.selectedOrganization = null
      this.showOrganizationModal = true
    },
    // The plans on offer are the chosen organization's own, so the plans (or the plan, when it has one) come after
    continueWithOrganization () {
      this.showOrganizationModal = false
      router.visit('/discover/' + this.app.slug + '/plans?organization=' + this.selectedOrganization)
    }
  },
  computed: {
    card_size () {
      if (this.can.activate && this.app.plan_count === 1) {
        return 'lg9'
      }

      return 'lg12'
    }
  }
}
</script>

<style lang="scss">
  .org-modal__intro {
    margin-bottom: 1rem;
    color: var(--va-text-secondary);
  }

  .org-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    max-height: 50vh;
    overflow-y: auto;
    padding: 2px;
  }

  .org-option {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    border: 1px solid var(--va-background-border);
    border-radius: 0.5rem;
    cursor: pointer;
    transition: border-color 0.15s, background-color 0.15s;
  }

  .org-option:hover:not(.org-option--disabled) {
    border-color: var(--va-primary);
  }

  .org-option--selected {
    border-color: var(--va-primary);
    background-color: color-mix(in srgb, var(--va-primary) 8%, transparent);
    box-shadow: 0 0 0 1px var(--va-primary);
  }

  .org-option--disabled {
    cursor: not-allowed;
    opacity: 0.6;
    background-color: var(--va-background-element);
  }

  .org-option__radio {
    margin-top: 0.3rem;
    accent-color: var(--va-primary);
  }

  .org-option__body {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 0.25rem;
    min-width: 0;
  }

  .org-option__name {
    font-weight: 600;
  }

  .org-option__reason {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.8125rem;
    color: var(--va-danger);
  }

  .va-table {
    width: 100%;
  }
  #description ol,
  #description li,
  #description ul {
    all: revert
  }
  #description hr {
    border-color: #DEE5F2;
    background-color: #DEE5F2;
    color: #DEE5F2;
    border-bottom: 0px
  }
</style>
