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

    <div class="d-flex justify-center mt-3">
      <va-button
        type="submit"
        id="submit"
        :disabled="form.processing"
        class="my-0"
      >
        {{ $t('auth.orgUserRegisterContinue') }}
      </va-button>
    </div>
  </form>
</template>

<script lang="ts">
export default {
  layout: (h: any, page: any) => h(AuthLayout, [page]),
  props: {
    organization: Object,
    errors: Object,
  },
  data () {
    return {
      form: useForm({
        email: '',
      }),
    }
  },
}
</script>
