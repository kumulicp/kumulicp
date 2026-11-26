<script setup lang="ts">
import AuthLayout from '@/layouts/AuthLayout.vue'
import { Head, useForm } from '@inertiajs/vue3'
</script>

<template>
  <Head>
    <title>{{ $t('auth.orgUserRegisterTitle', { org: organization.name }) }} - Control Panel</title>
  </Head>
  <div v-if="fullMessage">
    <h5 class="mb-3">{{ $t('auth.orgUserRegisterHeading', { org: organization.name }) }}</h5>
    <va-alert color="warning">{{ fullMessage }}</va-alert>
  </div>
  <form v-else @submit.prevent="submit">
    <h5 class="mb-3">{{ $t('auth.orgUserRegisterHeading', { org: organization.name }) }}</h5>
    <p class="mb-4">{{ $t('auth.orgUserRegisterSubheading') }}</p>

    <va-input
      v-model="form.email"
      id="email"
      class="mb-3"
      type="email"
      :label="$t('auth.email')"
      immediateValidation
      :error="$page.props.errors.email"
      :error-messages="$page.props.errors.email"
      autofocus
    />

    <div v-if="captchaProvider === 'turnstile'" class="cf-turnstile mb-3" :data-sitekey="captchaSiteKey" data-callback="captchaWidgetCallback" data-expired-callback="captchaWidgetExpired"></div>
    <div v-else-if="captchaProvider === 'hcaptcha'" class="h-captcha mb-3" :data-sitekey="captchaSiteKey" data-callback="captchaWidgetCallback" data-expired-callback="captchaWidgetExpired"></div>

    <div class="d-flex justify-center mt-3">
      <va-button
        type="submit"
        id="submit"
        :disabled="form.processing || !!(captchaProvider && !form.captcha_token)"
        class="my-0"
      >
        {{ $t('auth.orgUserRegisterContinue') }}
      </va-button>
    </div>
  </form>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AuthLayout, [page]),
  props: {
    organization: Object,
    errors: Object,
    fullMessage: String,
    captchaProvider: String,
    captchaSiteKey: String,
  },
  data () {
    return {
      form: useForm({
        email: '',
        captcha_token: '',
      }),
    }
  },
  methods: {
    submit () {
      this.form.post('/public/org/' + this.organization.slug + '/register', {
        // A token works once, whatever the answer was: a mistyped address needs a fresh one
        onFinish: () => this.resetCaptcha(),
      })
    },
    resetCaptcha () {
      if (!this.captchaProvider) {
        return
      }
      this.form.captcha_token = ''
      const w = window as any
      const widget = this.captchaProvider === 'turnstile' ? w.turnstile : w.hcaptcha
      widget?.reset()
    },
  },
  mounted () {
    // Nothing to protect when there is no form to send
    if (this.captchaProvider && !this.fullMessage) {
      const w = window as any
      w.captchaWidgetCallback = (token: string) => {
        this.form.captcha_token = token
      }
      // The provider calls this when an unused token runs out
      w.captchaWidgetExpired = () => {
        this.form.captcha_token = ''
      }
      const script = document.createElement('script')
      script.src = this.captchaProvider === 'turnstile'
        ? 'https://challenges.cloudflare.com/turnstile/v0/api.js'
        : 'https://js.hcaptcha.com/1/api.js'
      script.async = true
      script.defer = true
      document.head.appendChild(script)
    }
  },
}
</script>
