<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { useForm } from '@inertiajs/vue3'
import axios from 'axios'

</script>
<template>
  <Head>
    <title>{{ $t('organization.webDomains.domainTransfer') }} - Control Panel</title>
  </Head>
  <div class="web-domains-new-transfer">
    <div class="row">
      <div class="flex xs12">
        <va-card>
          <va-card-title>{{ $t('organization.webDomains.transferDomain') }}</va-card-title>
          <va-card-content>
            <form @submit.prevent="transfer">
              <div class="row">
                <div class="flex xs12 md8">
                  <va-input
                    v-model="form.domain_name"
                    id="domainName"
                    placeholder="example.com"
                    class="mb-3"
                    :label="$t('organization.webDomains.domainName')"
                    immediateValidation
                    :error="$page.props.errors.domain_name"
                    :error-messages="$page.props.errors.domain_name"
                    :success="domain_success"
                    @change="getPrice"
                  />
                  <va-input
                    v-model="form.epp_code"
                    id="eppCode"
                    :error="$page.props.errors.epp_code"
                    :error-messages="$page.props.errors.epp_code"
                    class="mb-3"
                    :label="$t('organization.webDomains.authEppCode')"
                    immediateValidation
                  />
                </div>
                <va-divider vertical />
                <div class="flex xs12 md3 mb-4">
                  <div>
                    <div class="row justify-center">
                      <h5 class="mb-3">{{ $t('organization.webDomains.transferPrice') }}</h5>
                    </div>
                    <div class="row justify-center">
                      <h5 class="va-h3 mb-3">{{ transfer_price }}</h5>
                    </div>
                    <div class="row justify-center">
                      <p class="mb-3">{{ $t('organization.webDomains.pricesInUsd') }}</p>
                    </div>
                  </div>
                </div>
                <div class="flex xs12">
                  <div class="row justify-center">
                    <va-button id="submit" type="submit" :disabled="!domain_success" class="mb-3">{{ $t('organization.webDomains.transferDomain') }}</va-button>
                  </div>
                </div>
              </div>
            </form>
          </va-card-content>
        </va-card>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    errors: Object
  },
  data () {
    return {
      transfer_price: '$0.00',
      price_errors: false,
      domain_success: false,
      form: useForm({
        epp_code: '',
        domain_name: ''
      })
    }
  },
  methods: {
    transfer () {
      if (this.domain_success) {
        this.form.post('/settings/domains/transfer')
      }
    },
    getPrice () {
      const vueState = this
      this.price_errors = false
      vueState.domain_success = false
      axios.post('/settings/domains/transfer/price', {
        _token: this.$page.props.csrf_token,
        domain_name: this.form.domain_name
      })
        .then(function (response) {
          if (response.data.status === 'failed_validation') {
            vueState.transfer_price = '$0.00'

            if ('domain_name' in response.data.messages) {
              vueState.price_errors = []
              const messages = response.data.messages.domain_name
              messages.forEach((item) => {
                vueState.price_errors.push(item)
              })
            }
          } else {
            vueState.domain_success = true
            vueState.transfer_price = '$' + response.data.price
          }
        })
    }
  }
}
</script>

<style lang="scss">
  .row-equal .flex {
    .va-card {
      height: 100%;
    }
  }

  .dashboard {
    .va-card {
      margin-bottom: 0 !important;

      &__title {
        display: flex;
        justify-content: space-between;
      }
    }
  }
</style>
