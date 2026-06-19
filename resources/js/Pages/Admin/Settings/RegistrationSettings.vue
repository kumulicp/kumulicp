<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsLayout from './SettingsLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm } from '@inertiajs/vue3'
</script>

<template>
  <Head>
    <title>{{ $t('settings.registration') }} - Control Panel</title>
  </Head>
  <form @submit.prevent="form.put('/admin/settings/registration')">
    <AdminSettings>
      <template #name>{{ $t('settings.captcha') }}</template>
      <template #description>{{ $t('settings.captchaDescription') }}</template>
      <template #settings>
        <va-select
          v-model="form.captcha_provider"
          :label="$t('settings.captchaProvider')"
          :options="providerOptions"
          value-by="value"
          text-by="label"
          class="mb-3"
        />
        <template v-if="form.captcha_provider">
          <va-input
            v-model="form.captcha_site_key"
            :label="$t('settings.captchaSiteKey')"
            class="mb-3"
            :error="$page.props.errors.captcha_site_key"
            :error-messages="$page.props.errors.captcha_site_key"
          />
          <va-input
            v-model="form.captcha_secret_key"
            :label="$t('settings.captchaSecretKey')"
            class="mb-3"
            :error="$page.props.errors.captcha_secret_key"
            :error-messages="$page.props.errors.captcha_secret_key"
          />
        </template>
      </template>
    </AdminSettings>
    <va-button type="submit" :disabled="form.processing" class="mr-2 my-2">
      {{ $t('common.update') }}
    </va-button>
  </form>
</template>

<script>
export default {
  layout: (h, page) => h(AppLayout, () => h(SettingsLayout, () => page)),
  props: {
    settings: Object,
    errors: Object,
  },
  data () {
    return {
      providerOptions: [
        { value: null, label: this.$t('settings.captchaNone') },
        { value: 'turnstile', label: this.$t('settings.captchaTurnstile') },
        { value: 'hcaptcha', label: this.$t('settings.captchaHcaptcha') },
      ],
      form: useForm({
        captcha_provider: this.settings.captcha_provider ?? null,
        captcha_site_key: this.settings.captcha_site_key ?? '',
        captcha_secret_key: this.settings.captcha_secret_key ?? '',
      }),
    }
  },
}
</script>
