<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('organization.webDomains.connectExisting') }} - Control Panel</title>
  </Head>
  <div class="web-domains-new-register">
    <div class="row">
      <div class="flex xs12">
        <va-card>
          <form @submit.prevent="form.post('/settings/domains/connect')">
            <va-card-title>{{ $t('organization.webDomains.connectYourDomain') }}</va-card-title>
            <va-card-content>
              <div class="row justify-center">
                <va-input
                  :label="$t('organization.webDomains.domainName')"
                  v-model="form.domain_name"
                  immediateValidation
                  autofocus
                  id="name"
                  class="lg6 flex"
                  :error="$page.props.errors.domain_name"
                  :error-messages="$page.props.errors.domain_name"
                />
              </div>
              <div class="row justify-center">
                <div>
                  <va-button type="submit" id="submit" class="mb-3">{{ $t('organization.webDomains.connectDomain') }}</va-button>
                </div>
              </div>
            </va-card-content>
          </form>
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
      form: useForm({
        domain_name: ''
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
