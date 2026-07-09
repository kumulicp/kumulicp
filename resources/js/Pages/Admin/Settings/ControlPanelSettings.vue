<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsLayout from './SettingsLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import TinymceEditor from '@/components/FormInputs/TinymceEditor.vue'
import { useForm } from '@inertiajs/vue3'
import { useColors } from 'vuestic-ui'
import { CURRENCIES } from '@/constants/currencies'

</script>
<template>
  <Head>
    <title>{{ $t('settings.editServerSettings') }} - Control Panel</title>
  </Head>
  <form @submit.prevent="form.put('/admin/settings')">
    <AdminSettings>
      <template #name>{{ $t('settings.general') }}</template>
      <template #settings>
        <va-input v-model="form.base_domain"
          :label="$t('settings.baseDomain')"
          id="baseDomain"
          class="mb-3"
          immediateValidation
          :error="$page.props.errors.base_domain"
          :error-messages="$page.props.errors.base_domain"
        />
        <va-input v-model="form.terms_url"
          :label="$t('settings.termsUrl')"
          id="termsUrl"
          class="mb-3"
          immediateValidation
          :error="$page.props.errors.terms_url"
          :error-messages="$page.props.errors.terms_url"
        />
        <va-input v-model="form.docs_url"
          :label="$t('settings.docsUrl')"
          id="docsUrl"
          class="mb-3"
          immediateValidation
          :error="$page.props.errors.docs_url"
          :error-messages="$page.props.errors.docs_url"
        />
        <va-input v-model="form.support_email"
          :label="$t('settings.supportEmail')"
          id="supportEmail"
          class="mb-3"
          immediateValidation
          :error="$page.props.errors.support_email"
          :error-messages="$page.props.errors.support_email"
        />
        <va-input v-model="form.error_email"
          :label="$t('settings.errorEmail')"
          id="errorEmail"
          class="mb-3"
          immediateValidation
          :error="$page.props.errors.error_email"
          :error-messages="$page.props.errors.error_email"
        />
        <va-select v-model="form.default_locale"
          :label="$t('settings.defaultLanguage')"
          id="defaultLocale"
          :options="localeOptions"
          value-by="code"
          text-by="name"
          clearable
          class="mb-3"
          immediateValidation
          :error="$page.props.errors.default_locale"
          :error-messages="$page.props.errors.default_locale"
        />
      </template>
    </AdminSettings>
    <va-list-separator class="my-1" fit />
    <AdminSettings>
      <template #name>{{ $t('settings.theme') }}</template>
      <template #settings>
        <va-color-input v-model="form.primary_color"
          :label="$t('settings.primaryColor')"
          id="baseDomain"
          class="mb-3"
          immediateValidation
          @update:modelValue="changeColors()"
          :error="$page.props.errors.primary_color"
          :error-messages="$page.props.errors.primary_color"
        />
        <va-color-input v-model="form.secondary_color"
          :label="$t('settings.secondaryColor')"
          id="baseDomain"
          class="mb-3"
          immediateValidation
          @update:modelValue="changeColors"
          :error="$page.props.errors.secondary_color"
          :error-messages="$page.props.errors.secondary_color"
        />
      </template>
    </AdminSettings>
    <va-list-separator class="my-1" fit />
    <AdminSettings>
      <template #name>{{ $t('settings.currency') }}</template>
      <template #description>{{ $t('settings.currencyDescription') }}</template>
      <template #settings>
        <va-select
          v-model="form.default_currency"
          :label="$t('settings.defaultCurrency')"
          :options="currencyOptions"
          value-by="value"
          text-by="text"
          class="mb-3"
          id="defaultCurrency"
          immediateValidation
          :error="!!$page.props.errors.default_currency"
          :error-messages="$page.props.errors.default_currency"
        />
        <va-select
          v-model="form.enabled_currencies"
          :label="$t('settings.enabledCurrencies')"
          :options="currencyOptions"
          value-by="value"
          text-by="text"
          multiple
          class="mb-3"
          id="enabledCurrencies"
          immediateValidation
          :error="!!$page.props.errors.enabled_currencies"
          :error-messages="$page.props.errors.enabled_currencies"
        />
        <p class="va-text-secondary mt-1">{{ $t('settings.currencyNote') }}</p>
      </template>
    </AdminSettings>
    <va-list-separator class="my-1" fit />
    <h6 class="va-h6 my-3">{{ $t('settings.welcomePage') }}</h6>
    <tinymce-editor v-model:htmlContent="form.welcome_page" />
    <va-button type="submit"
      id="submit"
      :disabled="form.processing"
      class="mr-2 my-2"
    >
      {{ $t('common.update') }}
    </va-button>
  </form>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(SettingsLayout, () => page))
  },
  props: {
    settings: Object,
    locales: Object,
    errors: Object
  },
  data () {
    const { getColors } = useColors()
    const primary = getColors().primary
    const secondary = getColors().secondary

    return {
      defaultPrimaryColor: primary,
      defaultSecondaryColor: secondary,
      currencyOptions: CURRENCIES.map(c => ({ value: c.code, text: `${c.code} — ${c.label}` })),
      localeOptions: Object.entries(this.locales || {}).map(([code, name]) => ({ code, name })),
      form: useForm({
        base_domain: this.settings.base_domain,
        terms_url: this.settings.terms_url,
        docs_url: this.settings.docs_url,
        welcome_page: this.settings.welcome_page,
        primary_color: this.settings.primary_color,
        secondary_color: this.settings.secondary_color,
        support_email: this.settings.support_email,
        error_email: this.settings.error_email,
        default_currency: this.settings.default_currency ?? 'USD',
        enabled_currencies: this.settings.enabled_currencies ?? ['USD'],
        default_locale: this.settings.default_locale
      })
    }
  },
  methods: {
    changeColors () {
      const { setColors } = useColors()

      setColors({
        primary: this.form.primary_color && this.form.primary_color != '' ? this.form.primary_color : this.defaultPrimaryColor,
        secondary: this.form.secondary_color && this.form.secondary_color != '' ? this.form.secondary_color : this.defaultSecondaryColor
      })
    }
  }
}
</script>

<style></style>
