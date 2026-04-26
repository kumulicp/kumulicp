<script setup>
import BlankLayout from '@/layouts/BlankLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import VuesticLogo from '@/components/VuesticLogo.vue'

</script>

<template>
  <Head>
    <title>{{ $t('auth.verifyEmailTitle') }} - Control Panel</title>
  </Head>
  <div class="auth-layout row align-content-center">
    <div class="flex xs12 pa-3 justify-center">
      <Link class="py-5 justify-center d-flex" href="/">
        <vuestic-logo height="32" />
      </Link>
    </div>

    <div class="flex xs12 pa-3">
      <div class="d-flex justify-center">
        <va-card class="auth-layout__card">
          <va-card-content>
            <div class="pa-3">
              <va-alert v-if="resent"
                class="mb-3"
              >
                <template #icon>
                  <va-icon
                    name="info"
                  />
                </template>
                {{ $t('auth.verificationEmailSent') }}
              </va-alert>
              <h4 class="va-h4"> {{ $t('auth.helloUser', { name: user.name }) }}</h4>
              <p class="mb-3">{{ $t('auth.emailNotVerified') }}</p>
              <div class="va-text-center">
                <va-button class="text-align-center" @click="form.post('/email/resend')">
                    {{ $t('auth.resendEmail') }}
                </va-button>
              </div>
            </div>
          </va-card-content>
        </va-card>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  layout: (h, page) => h(BlankLayout, [page]),
  props: {
    user: Object,
    resent: Object,
    verified: Object,
    errors: Object
  },
  data () {
    return {
      form: useForm({})
    }
  }
}
</script>
