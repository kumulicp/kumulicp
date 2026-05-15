<script setup lang="ts">
import AuthLayout from '@/layouts/AuthLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('auth.login') }} - Control Panel</title>
  </Head>
  <va-alert
    v-if="verified"
    class="mb-3"
  >
    <template #icon>
      <va-icon name="info" />
    </template>
    {{ $t('auth.accountVerified') }}
  </va-alert>
  <form @submit.prevent="form.post('/login')">
    <va-input
      v-model="form.email"
      id="email"
      immediateValidation
      class="mb-3"
      type="email"
      :label="$t('auth.email')"
      :error="$page.props.errors.email"
      :error-messages="$page.props.errors.email"
      autofocus
    />

    <va-input
      v-model="form.password"
      :label="$t('auth.password')"
      id="password"
      immediateValidation
      class="mb-3"
      type="password"
      :error="$page.props.errors.password"
      :error-messages="$page.props.errors.password"
    />

    <div class="auth-layout__options d-flex align-center justify-space-between">
      <va-checkbox id="remember" v-model="form.remember" class="mb-0" :label="$t('auth.keepLoggedIn')" />
      <Link href="/password/reset">
        {{ $t('auth.forgotPassword') }}
      </Link>
    </div>

    <div class="row">
      <div class="flex flex-col xs12 mt-3">
        <div class="text-center">
          <va-button id="submit" type="submit" class="my-0">{{ $t('auth.login') }}</va-button>
        </div>
      </div>
    </div>
  </form>
  <template v-if="sso_providers.length > 0">
    <div class="row">
      <div class="flex flex-col xs12 text-center mt-3">
        {{ $t('auth.otherMethods') }}
      </div>
    </div>
    <div class="row">
      <div v-for="(provider, i) in sso_providers" class="flex flex-col xs12 text-center mt-3" :key="i">
        <a :href="'/auth/'+provider.name" class="ml-2"><va-button color="secondary" id="oauth_link">{{ $t('auth.loginWith', { provider: provider.label }) }}</va-button></a>
      </div>
    </div>
  </template>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AuthLayout, [page]),
  props: {
    verified: Object,
    auth_methods: Object,
    sso_providers: Object,
    errors: Object
  },
  data () {
    return {
      form: useForm({
        email: '',
        password: '',
        remember: false
      })
    }
  }
}
</script>
