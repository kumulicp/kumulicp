<script setup>
import BlankLayout from '@/layouts/BlankLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import VuesticLogo from '@/components/VuesticLogo.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>Verify Email - Control Panel</title>
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
                A new verification email has been sent
              </va-alert>
              <h4 class="va-h4"> Hello {{ user.name }},</h4>
              <p class="mb-3">You're email hasn't been verified yet. Please check your email for your verification link.</p>
              <div class="va-text-center">
                <va-button class="text-align-center" @click="form.post('/email/resend')">
                    Resend Email
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
