<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Payment Method - Control Panel</title>
  </Head>
  <div class="organization">
    <div class="row row-equal">
      <div class="flex flex-col xs12 lg6">
        <va-card>
          <va-card-title>Payment Method</va-card-title>
          <va-card-content>
<!--             <component :is="creditCard" :hasDefaultPaymentMethod="hasDefaultPaymentMethod" /> -->
            <div id="paymentMethodWidget"></div>
          </va-card-content>
        </va-card>
      </div>
      <div class="flex flex-col xs12 lg6">
        <va-card>
          <va-card-title>Billing Managers</va-card-title>
          <va-card-content style="padding-bottom: 50px">
            <div class="row justify-center">
              <va-button
                id="addBillingManager"
                @click="showAddBillingManager = true"
              >
                Add Billing Manager
              </va-button>
            </div>
            <va-list v-if="managers.length > 0" class="mb-4">
              <template v-for="(manager, i) in managers" :key="manager.id">
                <va-list-item>
                  <va-list-item-section>
                    <Link :href="'/users/'+manager.id">{{ manager.name }}</Link>
                  </va-list-item-section>

                  <va-list-item-section icon>
                    <va-icon
                      name="entypo-cancel"
                      :id="'remove'+manager.id"
                      :title="'Remove Billing Manager '+manager.name"
                      color="danger"
                      class="clickable-icon"
                      @click="removeBillingManager(manager)"
                    />
                    <va-modal
                      v-model="showRemoveBillingManager"
                      no-padding
                    >
                      <template #content>
                        <va-card-title> Remove Billing Manager </va-card-title>
                        <va-card-content>
                          Are you sure you want to remove {{ removeForm.billing_manager.name }} as a billing manager?
                        </va-card-content>
                        <va-card-actions align="right">
                          <va-button
                            color="backgroundSecondary"
                            @click="showRemoveBillingManager = false"
                          >
                            Cancel
                          </va-button>
                          <va-button
                            id="remove"
                            color="danger"
                            :disabled="removeForm.processing"
                            @click="removeForm.delete('/subscription/billing/managers/'+removeForm.billing_manager.id, {
                              preserveScroll: true,
                              onSuccess: () => { showRemoveBillingManager = false },
                            })"
                          >
                            Remove Billing Manager
                          </va-button>
                        </va-card-actions>
                      </template>
                    </va-modal>
                  </va-list-item-section>
                </va-list-item>

                <va-list-separator v-if="i < managers.length - 1" :key="'separator' + manager.id" class="my-1" fit />
              </template>
            </va-list>
            <template v-else>
              <div class="row m-5">
                <div class="flex xs12 va-text-center mt-4">
                  <va-icon name="fa-user" style="color: var(--va-list-item-label-caption-color)"  size="5rem" />
                </div>
                <div class="flex xs12 va-text-center mb-4">
                  <h3 class="va-h3 mb-3" style="color: var(--va-list-item-label-caption-color)">No billing managers have been added</h3>
                </div>
              </div>
            </template>
            <va-card-actions
              style="position: absolute; bottom: 0; color: var(--va-secondary)"
            >
              * Billing managers are users that are sent the recurring invoice
            </va-card-actions>
          </va-card-content>
        </va-card>
        <va-modal
          v-model="showAddBillingManager"
          no-padding
        >
          <template #content="{ cancel }">
            <va-card-title> Add Billing Manager </va-card-title>
            <va-card-content>
              <va-select
                v-model="addForm.user_id"
                id="billingManager"
                label="User"
                text-by="text"
                value-by="value"
                searchable
                immediateValidation
                :options="users"
                :loading="contact_search"
                :disabled="contact_search"
              />
            </va-card-content>
            <va-card-actions align="right">
              <va-button
                color="backgroundSecondary"
                @click="cancel"
              >
                Cancel
              </va-button>
              <va-button
                color="primary"
                id="add"
                :disabled="addForm.processing"
                @click="addForm.post('/subscription/billing/managers', {
                  preserveScroll: true,
                  onSuccess: () => { showAddBillingManager = false },
                })"
              >
                Add Billing Manager
              </va-button>
            </va-card-actions>
          </template>
        </va-modal>
      </div>
    </div>
  </div>
</template>
<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    managers: Object,
    users: Object,
    errors: Object,
    hasDefaultPaymentMethod: Boolean,
    driver: String
  },
  data () {
    return {
      showAddBillingManager: false,
      showRemoveBillingManager: false,
      addForm: useForm({
        user_id: ''
      }),
      removeForm: useForm({
        billing_manager: {}
      }),
      paymentMethodWidget: new Map()
    }
  },
  mounted () {
    const el = document.getElementById('paymentMethodWidget')
    this.loadPaymentMethodComponent(this.driver, el, {
      hasDefaultPaymentMethod: this.hasDefaultPaymentMethod,
      csrf_token: this.$page.props.csrf_token
    })
  },
  unmounted () {
    const el = document.getElementById('paymentMethodWidget')
    const app = this.paymentMethodWidget.get(el)

    if (app) {
      app.unmount()
      this.paymentMethodWidget.delete(el)
    }
  },
  methods: {
    removeBillingManager (manager) {
      this.removeForm.billing_manager = manager
      this.showRemoveBillingManager = true
    },
    async loadPaymentMethodComponent (component, el, props = {}) {
      const module = await import('/widgets/' + component + '.js')
      const mount =
        module.mount ||
        module.default?.mount ||
        module.default

      if (typeof mount !== 'function') {
        throw new Error(`Widget "${component}" does not export a mount() function`)
      }

      const app = mount(el, props)

      this.paymentMethodWidget.set(el, app)
    }
  }
}
</script>

<style lang="scss">
</style>
