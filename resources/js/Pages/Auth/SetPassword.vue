<script setup>
import BlankLayout from '@/layouts/BlankLayout.vue'
import VuesticLogo from '../../components/VuesticLogo.vue'
import PasswordChecker from '@/components/FormInputs/PasswordChecker.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>Set Password - Control Panel</title>
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
          <va-card-title>Set your new password!</va-card-title>
          <va-card-content>
            <form @submit.prevent="form.post('/public/setpassword/'+code+'/save', {
                  onFinish: () => form.reset('password', 'password_confirmation'),
              })">
              <p class="mb-3">
                You are changing the password for {{ user.email }}.<br />
                If this is not your email, leave this page.
              </p>
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
              <password-checker :password="form.password" :passwordConfirmation="form.password_confirmation" />
              <div class="d-flex justify-center mt-3">
                <va-button type="submit" class="my-0">{{ t('auth.setPassword') }}</va-button>
              </div>
            </form>
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
    code: Object,
    errors: Object
  },
  data () {
    return {
      form: useForm({
        password: '',
        password_confirmation: ''
      })
    }
  }
}
</script>

<style lang="scss">
  .auth-layout {
    min-height: 100vh;
    background-image: linear-gradient(to right, var(--va-background-primary), var(--va-white));

    &__card {
      width: 100%;
      max-width: 600px;
    }
  }
</style>
