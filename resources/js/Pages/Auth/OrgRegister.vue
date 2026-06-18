<script setup lang="ts">
import AuthLayout from '@/layouts/AuthLayout.vue'
import { Head, useForm } from '@inertiajs/vue3'
</script>

<template>
  <Head>
    <title>{{ $t('auth.orgUserRegisterTitle', { org: organization.name }) }} - Control Panel</title>
  </Head>
  <form @submit.prevent="form.post('/public/org/' + organization.slug + '/register')">
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

    <div v-if="captchaProvider === 'turnstile'" ref="captchaContainer" class="cf-turnstile mb-3" :data-sitekey="captchaSiteKey" data-callback="captchaWidgetCallback"></div>
    <div v-else-if="captchaProvider === 'hcaptcha'" ref="captchaContainer" class="h-captcha mb-3" :data-sitekey="captchaSiteKey" data-callback="captchaWidgetCallback"></div>

    <div class="d-flex justify-center mt-3">
      <va-button
        type="submit"
        id="submit"
        :disabled="form.processing || (captchaProvider && !form.captcha_token)"
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
  mounted () {
    if (this.captchaProvider) {
      window.captchaWidgetCallback = (token) => {
        this.form.captcha_token = token
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
