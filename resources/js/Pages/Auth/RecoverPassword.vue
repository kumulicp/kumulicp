<script setup>
import AuthLayout from '@/layouts/AuthLayout.vue'
import { useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('auth.recoverPassword') }} - Control Panel</title>
  </Head>
  <form class="login" @submit.prevent="form.post('/password/email', {
        onSuccess: () => email_sent = emailSentMessage,
    })">
    <va-input
      v-model="form.email"
      class="mb-3"
      type="email"
      :label="$t('auth.email')"
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
        {{ $t('auth.resetPassword') }}
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
  },
  computed: {
    emailSentMessage () {
      return this.$t('auth.checkEmailForReset')
    }
  }
}
</script>
