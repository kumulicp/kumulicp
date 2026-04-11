<script setup>
import AuthLayout from '@/layouts/AuthLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Recover Password - Control Panel</title>
  </Head>
  <form class="login" @submit.prevent="form.post('/password/email', {
        onSuccess: () => email_sent = 'Check your email for a link to reset your password.',
    })">
    <va-input
      v-model="form.email"
      class="mb-3"
      type="email"
      label="Email"
      immediateValidation
      :success="email_sent"
      :messages="email_sent"
      :error="$page.props.errors.email"
      :error-messages="$page.props.errors.email"
    />

    <div class="d-flex justify-center mt-3">
      <va-button type="submit"
        class="my-0"
        :disabled="form.processing"
      >
        {{ t('auth.reset_password') }}
      </va-button>
    </div>
  </form>
</template>

<script>
export default {
  layout: (h, page) => h(AuthLayout, [page]),
  props: {
    errors: Object
  },
  data () {
    return {
      email_sent: false,
      form: useForm({
        email: '',
        password: '',
        keep_logged_in: false
      })
    }
  }
}
</script>
