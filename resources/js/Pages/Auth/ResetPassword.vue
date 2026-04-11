<script setup>
import AuthLayout from '@/layouts/AuthLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>{{ t('auth.resetPassword') }} - Control Panel</title>
  </Head>
  <form @submit.prevent="form.post('/password/reset', {
        onFinish: () => form.reset('password', 'password_confirmation'),
        onSuccess: () => email_sent = true,
    })">
    <va-input
      v-model="form.email"
      class="mb-3"
      type="email"
      label="Email"
      immediateValidation
      :error="$page.props.errors.email"
      :error-messages="$page.props.errors.email"
    />

    <va-input
      v-model="form.password"
      class="mb-3"
      type="password"
      label="Password"
      immediateValidation
      :error="$page.props.errors.password"
      :error-messages="$page.props.errors.password"
    />

    <va-input
      v-model="form.password_confirmation"
      class="mb-3"
      type="password"
      label="Confirm Password"
      immediateValidation
      :error="$page.props.errors.password_confirmation"
      :error-messages="$page.props.errors.password_confirmation"
    />

    <div class="d-flex justify-center mt-3">
      <va-button type="submit"
        class="my-0"
        :disabled="form.processing"
      >
        Reset Password
      </va-button>
    </div>
  </form>
</template>

<script>
export default {
  layout: (h, page) => h(AuthLayout, [page]),
  props: {
    token: Object,
    email: Object,
    errors: Object
  },
  data () {
    return {
      form: useForm({
        token: this.token,
        email: this.email,
        password: '',
        password_confirmation: ''
      })
    }
  }
}
</script>
