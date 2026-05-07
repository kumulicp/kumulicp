<script setup lang="ts">
import AuthLayout from '@/layouts/AuthLayout.vue'
import { Head, useForm } from '@inertiajs/vue3'
</script>

<template>
  <Head>
    <title>{{ $t('auth.orgRegisterCompleteTitle', { org: organization.name }) }} - Control Panel</title>
  </Head>
  <form @submit.prevent="form.post('/public/org/' + organization.slug + '/register/verify/' + token)">
    <h5 class="mb-3">{{ $t('auth.orgRegisterCompleteHeading', { org: organization.name }) }}</h5>

    <va-input
      :model-value="email"
      id="email"
      class="mb-3"
      type="email"
      :label="$t('auth.email')"
      readonly
      disabled
    />

    <va-input
      v-model="form.username"
      id="username"
      class="mb-3"
      :label="$t('auth.username')"
      immediateValidation
      :error="$page.props.errors.username"
      :error-messages="$page.props.errors.username"
      autofocus
    />

    <va-input
      v-model="form.first_name"
      id="first_name"
      class="mb-3"
      :label="$t('user.firstName')"
      immediateValidation
      :error="$page.props.errors.first_name"
      :error-messages="$page.props.errors.first_name"
    />

    <va-input
      v-model="form.last_name"
      id="last_name"
      class="mb-3"
      :label="$t('user.lastName')"
      immediateValidation
      :error="$page.props.errors.last_name"
      :error-messages="$page.props.errors.last_name"
    />

    <va-input
      v-model="form.phone_number"
      id="phone_number"
      class="mb-3"
      :label="$t('user.phoneNumber')"
      :error="$page.props.errors.phone_number"
      :error-messages="$page.props.errors.phone_number"
    />

    <div class="d-flex justify-center mt-3">
      <va-button
        type="submit"
        id="submit"
        :disabled="form.processing"
        class="my-0"
      >
        {{ $t('auth.orgRegisterCompleteSubmit') }}
      </va-button>
    </div>
  </form>
</template>

<script lang="ts">
export default {
  layout: (h: any, page: any) => h(AuthLayout, [page]),
  props: {
    organization: Object,
    token: String,
    email: String,
    errors: Object,
  },
  data () {
    return {
      form: useForm({
        username: '',
        first_name: '',
        last_name: '',
        phone_number: '',
      }),
    }
  },
}
</script>

<style>
  .va-input {
    width: 100%;
  }
</style>
