<script setup lang="ts">
import AuthLayout from '@/layouts/AuthLayout.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { ref } from 'vue'
import { useInputMask, createRegexMask } from 'vuestic-ui'

const { t } = useI18n()

const contactPhoneNumber = ref()
useInputMask(createRegexMask(/(\+\d \(\d{3}\)|\d{3}) (\d){3}-(\d){4}/), contactPhoneNumber)
</script>
<template>
  <Head>
    <title>Signup - Control Panel</title>
  </Head>
    <template v-if="can.register">
    <form @submit.prevent="form.post('/register')">

      <h5 class="mb-3">User Info</h5>
      <va-input
        v-model="form.username"
        id="username"
        class="mb-3"
        :label="t('auth.username')"
        immediateValidation
        :error="$page.props.errors.username"
        :error-messages="$page.props.errors.username"
      />

      <va-input
        v-model="form.contact_email"
        id="contactEmail"
        class="mb-3"
        type="email"
        :label="t('auth.email')"
        immediateValidation
        :error="$page.props.errors.contact_email"
        :error-messages="$page.props.errors.contact_email"
      />

      <va-input
        v-model="form.password"
        id="password"
        class="mb-3"
        type="password"
        :label="t('auth.password')"
        immediateValidation
        :error="$page.props.errors.password"
        :error-messages="$page.props.errors.password"
      />

      <va-input
        v-model="form.password_confirmation"
        id="passwordConfirmation"
        class="mb-3"
        type="password"
        :label="t('auth.confirmPassword')"
        immediateValidation
        :error="$page.props.errors.password_confirmation"
        :error-messages="$page.props.errors.password_confirmation"
      />

      <va-divider class="mb-3"></va-divider>

      <va-input
        v-model="form.contact_first_name"
        id="contactFirstName"
        class="mb-3"
        :label="t('user.firstName')"
        immediateValidation
        :error="$page.props.errors.contact_first_name"
        :error-messages="$page.props.errors.contact_first_name"
      />

      <va-input
        v-model="form.contact_last_name"
        id="contactLastName"
        class="mb-3"
        :label="t('user.lastName')"
        immediateValidation
        :error="$page.props.errors.contact_last_name"
        :error-messages="$page.props.errors.contact_last_name"
      />

      <va-input
        v-model="form.contact_phone_number"
        id="contactPhoneNumber"
        ref="contactPhoneNumber"
        class="mb-3"
        :label="t('user.phoneNumber')"
        immediateValidation
        :error="$page.props.errors.contact_phone_number"
        :error-messages="$page.props.errors.contact_phone_number"
      />

      <va-divider class="mb-3" />

      <va-select
        v-model="form.type"
        :options="org_types"
        id="type"
        class="mb-3"
        text-by="name"
        value-by="value"
        immediateValidation
        :label="t('auth.orgType')"
        :error="$page.props.errors.type"
        :error-messages="$page.props.errors.type"
      />

      <va-input
        v-model="form.name"
        v-if="form.type && form.type !== 'none'"
        id="name"
        class="mb-3"
        :label="t('auth.orgName')"
        immediateValidation
        :error="$page.props.errors.name"
        :error-messages="$page.props.errors.name"
      />

      <va-input
        v-model="form.subdomain"
        id="subdomain"
        maxlength="30"
        class="mb-3"
        label="Subdomain name"
        immediateValidation
        :error="$page.props.errors.subdomain"
        :error-messages="$page.props.errors.subdomain"
        :messages="t('auth.subdomainMessage', { domain: orgURL })"
      >
        <template #appendInner>
          .{{ base_domain }}
        </template>
      </va-input>

      <div id="termsOfUse" class="auth-layout__options d-flex align-center">
        <va-checkbox
          v-model="form.terms_of_use"
          class="mb-0"
          immediateValidation
          :error="$page.props.errors.terms_of_use"
          :error-messages="$page.props.errors.terms_of_use"
        >
          <template #label>
          </template>
        </va-checkbox>
            <span class="ml-2">
              I agree to
              <a :href="terms_url" target="blank" class="va-link">{{ t('auth.terms') }}</a>
            </span>
      </div>

      <div class="d-flex justify-center mt-3">
        <va-button type="submit"
          id="submit"
          :disabled="form.processing"
          class="my-0"
        >
          Register
        </va-button>
      </div>
    </form>
  </template>
  <template v-else>
    <h1 class="va-h1 va-text-center">{{ t('auth.registrationError1') }}</h1>
    <h6 class="va-h6 va-text-center">{{ t('auth.registrationError2') }}</h6>
  </template>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AuthLayout, [page]),
  props: {
    terms_url: Object,
    org_types: Object,
    base_domain: Object,
    can: Object,
    errors: Object
  },
  data () {
    return {
      form: useForm({
        username: '',
        contact_email: '',
        password: '',
        password_confirmation: '',
        contact_first_name: '',
        contact_last_name: '',
        contact_phone_number: '',
        type: '',
        name: '',
        email: '',
        phone_number: '',
        subdomain: '',
        description: '',
        street: '',
        zipcode: '',
        city: '',
        state: '',
        country: 'US',
        terms_of_use: false
      })
    }
  },
  computed: {
    orgURL () {
      let subdomain = '___'
      if (this.form.subdomain) {
        subdomain = this.form.subdomain
      }

      return subdomain + '.' + this.base_domain
    }
  }
}
</script>

<style>
  .va-input {
    width: 100%
  }
</style>
