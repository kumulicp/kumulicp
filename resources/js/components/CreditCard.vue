<script setup>
import { Link } from '@inertiajs/vue3'
import axios from 'axios'
import { Stripe } from '@vue-stripe/vue-stripe'
</script>
<template>
  <div>
    <template v-if="has_default_payment_method">
      <va-list>
        <va-list-item class="list__item">
          <va-list-item-section icon>
            <va-inner-loading :loading="!brand_image">
              <img
                fit="contain"
                style="width:100px"
                :src="brand_image"
              />
            </va-inner-loading>
          </va-list-item-section>
          <va-list-item-section>
            <va-list-item-label v-if="payment_method.brand" lines="2">
              {{ payment_method.brand }} ****{{ payment_method.last4 }}
              <br />
              Expires {{ payment_method.exp_month }}/{{ payment_method.exp_year }}
            </va-list-item-label>
          </va-list-item-section>
          <va-list-item-section icon>
            <va-list-item-label v-if="payment_method.brand">
              <va-icon
                name="fa-trash"
                title="Delete Credit Card"
                color="danger"
                class="clickable-icon"
                @click="showDeletePaymentMethodModal = true"
              />
              <va-modal
                v-model="showDeletePaymentMethodModal"
                hide-default-actions
                title="Delete Payment Method"
                message="If you are subscribed to a paid plan, this will also cancel that plan unless you add a new payment method before your next payment date or change your subscription to a free plan. If you want to remain subscribed, we recommend just updating your current payment method. Are you sure you want to to delete this payment method?"
              >
                <template #footer="{ cancel }">
                  <va-button
                    color="backgroundSecondary"
                    @click="cancel"
                  >
                    Cancel
                  </va-button>
                  <Link href="/subscription/payment/method/delete"><va-button color="danger">Delete</va-button></Link>
                </template>
              </va-modal>
            </va-list-item-label>
          </va-list-item-section>
        </va-list-item>
      </va-list>
      <va-divider />
    </template>
    <template v-if="!cardSubmittedSuccessfully">
      <div class="row">
        <div class="flex flex-col xs12">
          <div class="item">
            <i v-if="hasDefaultPaymentMethod">Is this the right payment info? If not, update it below.</i>
            <i v-else>Please add payment information to proceed.</i>
          </div>
        </div>
      </div>
      <div class="row justify-center">
        <div class="flex xs12 lg8">
          <div class="row">
            <!-- Stripe Elements Placeholder -->
            <div class="flex flex-col xs6 mr-1">
              <div class="va-title text-color-primary">Card Number</div>
              <div class="va-input-wrapper va-input-wrapper--focused va-input">
                <fieldset class="va-input-wrapper__fieldset va-input-wrapper__size-keeper">
                  <div class="va-input-wrapper__container">
                    <div class="va-input-wrapper__field">
                      <div class="va-input-wrapper__text">
                        <div id="card-number" style="width:100%"></div>
                      </div>
                    </div>
                  </div>
                </fieldset>
              </div>
            </div>
            <div class="flex flex-col xs3 mr-1">
              <div class="va-title text-color-primary">Expiry</div>
              <div class="va-input-wrapper va-input-wrapper--focused va-input">
                <fieldset class="va-input-wrapper__fieldset va-input-wrapper__size-keeper">
                  <div class="va-input-wrapper__container">
                    <div class="va-input-wrapper__field">
                      <div class="va-input-wrapper__text">
                        <div id="card-expiry" style="width: 100%"></div>
                      </div>
                    </div>
                  </div>
                </fieldset>
              </div>
            </div>
            <div class="flex flex-col xs2">
              <div class="va-title text-color-primary">CVC</div>
              <div class="va-input-wrapper va-input-wrapper--focused va-input">
                <fieldset class="va-input-wrapper__fieldset va-input-wrapper__size-keeper">
                  <div class="va-input-wrapper__container">
                    <div class="va-input-wrapper__field">
                      <div class="va-input-wrapper__text">
                        <div id="card-cvc" style="width: 100%"></div>
                      </div>
                    </div>
                  </div>
                </fieldset>
              </div>
            </div>
<!--             <div id="card-element"></div> -->
          </div>
        </div>
      </div>
      <div class="row justify-center mb-2">
        <va-button @click="updatePaymentMethod"
          id="updateCreditCard"
          :disabled="stripe_processing"
          >
          <template v-if="hasDefaultPaymentMethod">Update Payment Method</template>
          <template v-else>Add Payment Method</template>
        </va-button>
      </div>
    </template>
    <div v-else class="row">
      <div class="flex flex-col xs12 va-text-center">
        <div>
          <va-icon name="fa-thumbs-up" color="success" class="mr-2" />
          Update Payment Method Successfully
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    hasDefaultPaymentMethod: Boolean
  },
  data () {
    return {
      showDeletePaymentMethodModal: false,
      cardSubmittedSuccessfully: false,
      has_default_payment_method: this.hasDefaultPaymentMethod,
      currentUrl: window.location.href,
      intent: {
        client_secret: ''
      },
      payment_method: {
        brand: '',
        last4: '',
        exp_month: '',
        exp_year: ''
      },
      brand_image: '',
      stripe: null,
      cardHolderName: '',
      stripe_key: '',
      error: false,
      stripe_processing: false,
      cardElement: '',
      cardNumber: '',
      cardExpiry: '',
      cardCvc: ''
    }
  },
  emits: ['update:hasDefaultPaymentMethod'],
  computed: {
    default_payment_method: {
      get () {
        return this.hasDefaultPaymentMethod
      },
      set (value) {
        this.$emit('update:hasDefaultPaymentMethod', value)
      }
    }
  },
  mounted () {
    this.getCardInformation()
  },
  methods: {
    getCardInformation () {
      const vueState = this
      const url = '/subscription/payment/method'
      axios.get(url)
        .then((response) => {
          const data = response.data
          vueState.has_default_payment_method = data.hasDefaultPaymentMethod
          vueState.default_payment_method = data.hasDefaultPaymentMethod
          vueState.stripe_key = data.stripe_key
          vueState.cardHolderName = ''
          vueState.brand_image = data.brand_image
          vueState.payment_method = data.defaultPaymentMethod.card
          vueState.intent.client_secret = data.intent.client_secret
        }).then(() => {
          this.mountStripe()
        }).catch(function () {
        })
    },
    mountStripe () {
      // Style Object documentation here: https://stripe.com/docs/js/appendix/style
      const style = {
        base: {
          color: 'black',
          fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
          fontSmoothing: 'antialiased',
          fontSize: '12px',
          '::placeholder': {
            color: '#aab7c4'
          }
        },
        invalid: {
          color: '#fa755a',
          iconColor: '#fa755a'
        }
      }

      this.stripe = Stripe(this.stripe_key)

      const elements = this.stripe.elements()
      // this.cardElement = elements.create('card')
      // this.cardElement.mount('#card-element')
      this.cardNumber = elements.create('cardNumber', { style })
      this.cardNumber.mount('#card-number')
      this.cardExpiry = elements.create('cardExpiry', { style })
      this.cardExpiry.mount('#card-expiry')
      this.cardCvc = elements.create('cardCvc', { style })
      this.cardCvc.mount('#card-cvc')
    },
    updatePaymentMethod () {
      const vueState = this
      this.error = false
      this.stripe_processing = true
      this.stripe.confirmCardSetup(vueState.intent.client_secret, {
        payment_method: {
          card: vueState.cardNumber,
          billing_details: { name: vueState.cardHolderName }
        }
      }).then((response) => {
        axios.post('/subscription/payment/method', {
          paymentMethod: response.setupIntent.payment_method,
          _token: vueState.$page.props.csrf_token
        }).then(() => {
          this.getCardInformation()
          vueState.stripe_processing = false
          vueState.cardSubmittedSuccessfully = true
        }).catch(() => {
          vueState.error = 'There has been an error updating your payment method. Try reloading this page and trying again. If that still fails, please click the question mark at the top of the screen and let us know so we can fix it right away.'
          vueState.stripe_processing = false
        })
      }).catch(() => {
        this.getCardInformation()
        vueState.error = 'There has been an error updating your payment method. Please reload and try again'
        vueState.stripe_processing = false
      })
    },
    submit () {
      // this will trigger the process
      this.$refs.elementRef.submit()
    }
  }
}
</script>

<style>
div.va-input-wrapper__field {
border-color: #DDE5F2 !important
}
</style>
