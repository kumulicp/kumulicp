<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { useForm, Link } from '@inertiajs/vue3'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Domain Registration - Control Panel</title>
  </Head>
  <div class="web-domains-new-register">
    <div class="row">
      <div class="flex xs12">
        <va-card>
          <va-card-title>Web Domain Setup</va-card-title>
          <va-card-content>
            <va-alert v-if="showAlert" color="danger" class="mb-6" icon="info"
              >There has been an error confirming the availability of the domain name. This does not mean that the
              domain isn't available. Only that there is a problem with our ability to check it's availability. If the
              problem persists, please click the "?" in the top right corner and let us know so we can look into it
              immediately.</va-alert
            >

            <div class="row justify-center">
              <div class="flex xs6">
                <form @submit.prevent="check">
                  <va-button-group grow>
                    <va-input
                      v-model="form.domain_name"
                      class="mb-4"
                      immediateValidation
                      placeholder="example.com"
                      :error="error"
                      :success="domain_available"
                    >
                      <template #append>
                        <va-button color="info" type="submit">Check Availability</va-button>
                      </template>
                    </va-input>
                  </va-button-group>
                  <div class="row justify-end mb-3">
                    <p>*At this time, we are not able to register domain names ending in: .us, .eu, .nu, .co.uk, .me.uk, .org.uk, .com.au, .net.au, .org.au, .es, .nom.es, .com.es, .org.es, .de, or .fe. Sorry for any incovenience this might cause. If you would like one of these domain names, you are still able to register with a separate domain registrar and connect your domain <Link href="/settings/domains/connect">here</Link>.</p>
                  </div>
                </form>
              </div>
            </div>
            <div v-if="domain_available" class="row justify-center mb-3">
              <div class="flex xs3">
                <div>
                  <div class="flex-col xs12 text-center">
                    <va-icon name="fa-check-circle" size="100px" color="success" />
                  </div>

                  <div class="flex-col xs12">
                    <p class="va-h5 text-center">{{ message }}</p>
                  </div>
                </div>
              </div>
              <va-divider vertical />
              <div class="flex xs3">
                <div>
                  <div class="row justify-center">
                    <p class="va-h2">${{ price }}</p>
                  </div>

                  <div class="row justify-center">
                    <p class="va-h5 text-center-aligned">for first year</p>
                  </div>
                  <div class="row justify-center">
                    <p class="text-center-aligned">*all prices are in USD</p>
                  </div>
                </div>
              </div>
            </div>
            <div v-if="domain_available" class="row justify-center mb-3">
              <va-button @click="form.post('/settings/domains/availability')" size="large">Checkout Domain</va-button>
            </div>
            <template v-else-if="error">
              <div class="row justify-center mb-3">
                <va-icon name="fa-check-circle" size="100px" color="danger" />
              </div>
              <div class="row justify-center mb-3">
                <h4>{{ message }}</h4>
              </div>
            </template>
          </va-card-content>
        </va-card>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  data () {
    return {
      showAlert: false,
      message: '',
      domain_available: false,
      price: 0,
      search: false,
      form: useForm({
        domain_name: ''
      }),
      error: false,
      success: false
    }
  },
  methods: {
    check () {
      const vueState = this
      this.error = false
      this.success = false
      this.showAlert = false
      axios.post('/settings/domains/check', {
        domain_name: vueState.form.domain_name,
        _token: this.$page.props.csrf_token
      })
        .then((response) => {
          vueState.domain_available = response.data.availability
          vueState.price = response.data.price
          vueState.message = response.data.message
          vueState.search = true
          vueState.error = !response.data.availability
        }).catch(() => {
          vueState.search = false
          vueState.domain_available = false
          vueState.error = true
          vueState.showAlert = true
        })
    }
  }
}
</script>

<style lang="scss" scoped>
  .row-equal .flex {
    .va-card {
      height: 100%;
    }
  }

  .va-button {
    max-width: 200px;
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
