<script setup>
import axios from 'axios'
</script>
<template>
  <div>
    <template v-if="showDefaultPaymentMethod">
      <va-list>
        <va-list-item class="list__item">
          <va-list-item-section icon>
            <va-icon name="fa-credit-card" size="2rem" color="primary" />
          </va-list-item-section>
          <va-list-item-section>
            <va-list-item-label lines="2">
              {{ paymentMethod.brand }} ****{{ paymentMethod.last4 }}
              <br />
              Expires {{ paymentMethod.exp_month }}/{{ paymentMethod.exp_year }}
            </va-list-item-label>
          </va-list-item-section>
          <va-list-item-section icon>
            <va-list-item-label>
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
                message="If you are subscribed to a paid plan, this will also cancel that plan unless you add a new payment method before your next payment date or change your subscription to a free plan. Are you sure you want to delete this payment method?"
              >
                <template #footer="{ cancel }">
                  <va-button color="backgroundSecondary" @click="cancel">Cancel</va-button>
                  <va-button color="danger" @click="deletePaymentMethod">Delete</va-button>
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

      <div v-if="error" class="row mb-2">
        <div class="flex flex-col xs12">
          <va-alert color="danger" icon="warning">{{ error }}</va-alert>
        </div>
      </div>

      <div class="row justify-center">
        <div class="flex xs12 lg8">
          <div class="row">
            <div class="flex flex-col xs6 mr-1">
              <va-input
                v-model="cardNumber"
                label="Card Number"
                placeholder="4242 4242 4242 4242"
                :error="!!cardNumberError"
                :error-messages="cardNumberError"
                maxlength="19"
                @input="formatCardNumber"
              />
            </div>
            <div class="flex flex-col xs3 mr-1">
              <va-input
                v-model="cardExpiry"
                label="Expiry"
                placeholder="MM/YY"
                :error="!!cardExpiryError"
                :error-messages="cardExpiryError"
                maxlength="5"
                @input="formatExpiry"
              />
            </div>
            <div class="flex flex-col xs2">
              <va-input
                v-model="cardCvc"
                label="CVC"
                placeholder="123"
                :error="!!cardCvcError"
                :error-messages="cardCvcError"
                maxlength="4"
              />
            </div>
          </div>
        </div>
      </div>

      <div class="row justify-center mb-2">
        <va-button @click="updatePaymentMethod" :disabled="processing">
          <template v-if="hasDefaultPaymentMethod">Update Payment Method</template>
          <template v-else>Add Payment Method</template>
        </va-button>
      </div>
    </template>

    <div v-else class="row">
      <div class="flex flex-col xs12 va-text-center">
        <div>
          <va-icon name="fa-thumbs-up" color="success" class="mr-2" />
          Payment Method Added Successfully
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    hasDefaultPaymentMethod: Boolean,
    csrf_token: String,
    onUpdateDefaultPaymentMethod: Function
  },
  data () {
    return {
      showDeletePaymentMethodModal: false,
      cardSubmittedSuccessfully: false,
      showDefaultPaymentMethod: this.hasDefaultPaymentMethod,
      paymentMethod: {
        brand: 'Visa',
        last4: '4242',
        exp_month: '12',
        exp_year: '99'
      },
      cardNumber: '',
      cardExpiry: '',
      cardCvc: '',
      cardNumberError: '',
      cardExpiryError: '',
      cardCvcError: '',
      processing: false,
      error: ''
    }
  },
  methods: {
    formatCardNumber () {
      const digits = this.cardNumber.replace(/\D/g, '').slice(0, 16)
      this.cardNumber = digits.replace(/(.{4})/g, '$1 ').trim()
    },
    formatExpiry () {
      const digits = this.cardExpiry.replace(/\D/g, '').slice(0, 4)
      if (digits.length >= 3) {
        this.cardExpiry = digits.slice(0, 2) + '/' + digits.slice(2)
      } else {
        this.cardExpiry = digits
      }
    },
    validate () {
      this.cardNumberError = ''
      this.cardExpiryError = ''
      this.cardCvcError = ''

      const digits = this.cardNumber.replace(/\s/g, '')
      if (digits.length < 13 || digits.length > 16) {
        this.cardNumberError = 'Please enter a valid card number.'
      }

      const expParts = this.cardExpiry.split('/')
      if (expParts.length !== 2 || expParts[0].length !== 2 || expParts[1].length !== 2) {
        this.cardExpiryError = 'Please enter expiry as MM/YY.'
      }

      if (this.cardCvc.length < 3) {
        this.cardCvcError = 'Please enter a valid CVC.'
      }

      return !this.cardNumberError && !this.cardExpiryError && !this.cardCvcError
    },
    updatePaymentMethod () {
      if (!this.validate()) return

      this.processing = true
      this.error = ''

      const expParts = this.cardExpiry.split('/')
      const payload = {
        card_number: this.cardNumber.replace(/\s/g, ''),
        exp_month: expParts[0],
        exp_year: expParts[1],
        cvc: this.cardCvc,
        _token: this.csrf_token
      }

      axios.post('/subscription/payment/method/fake', payload)
        .then(() => {
          const digits = this.cardNumber.replace(/\s/g, '')
          this.paymentMethod = {
            brand: this.detectBrand(digits),
            last4: digits.slice(-4),
            exp_month: expParts[0],
            exp_year: expParts[1]
          }
          this.showDefaultPaymentMethod = true
          this.cardSubmittedSuccessfully = true
          this.onUpdateDefaultPaymentMethod?.(true)
        })
        .catch(() => {
          this.error = 'There was an error saving your payment method. Please try again.'
        })
        .finally(() => {
          this.processing = false
        })
    },
    deletePaymentMethod () {
      window.location.href = '/subscription/payment/method/delete'
    },
    detectBrand (number) {
      if (/^4/.test(number)) return 'Visa'
      if (/^5[1-5]/.test(number)) return 'Mastercard'
      if (/^3[47]/.test(number)) return 'American Express'
      if (/^6(?:011|5)/.test(number)) return 'Discover'
      return 'Card'
    }
  }
}
</script>

<style>
div.va-input-wrapper__field {
  border-color: #DDE5F2 !important
}
</style>
