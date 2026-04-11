<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsLayout from './SettingsLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import TinymceEditor from '@/components/FormInputs/TinymceEditor.vue'
import { useForm } from '@inertiajs/vue3'
import { useColors } from 'vuestic-ui'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Edit Server Settings - Control Panel</title>
  </Head>
  <form @submit.prevent="form.put('/admin/settings')">
    <AdminSettings>
      <template #name>{{ t('settings.general') }}</template>
      <template #settings>
        <va-input v-model="form.base_domain"
          :label="t('settings.baseDomain')"
          id="baseDomain"
          class="mb-3"
          immediateValidation
          :error="$page.props.errors.base_domain"
          :error-messages="$page.props.errors.base_domain"
        />
        <va-input v-model="form.terms_url"
          :label="t('settings.termsUrl')"
          id="termsUrl"
          class="mb-3"
          immediateValidation
          :error="$page.props.errors.terms_url"
          :error-messages="$page.props.errors.terms_url"
        />
        <va-input v-model="form.docs_url"
          :label="t('settings.docsUrl')"
          id="docsUrl"
          class="mb-3"
          immediateValidation
          :error="$page.props.errors.docs_url"
          :error-messages="$page.props.errors.docs_url"
        />
        <va-input v-model="form.support_email"
          :label="t('settings.supportEmail')"
          id="supportEmail"
          class="mb-3"
          immediateValidation
          :error="$page.props.errors.support_email"
          :error-messages="$page.props.errors.support_email"
        />
        <va-input v-model="form.error_email"
          :label="t('settings.errorEmail')"
          id="errorEmail"
          class="mb-3"
          immediateValidation
          :error="$page.props.errors.error_email"
          :error-messages="$page.props.errors.error_email"
        />
      </template>
    </AdminSettings>
    <va-list-separator class="my-1" fit />
    <AdminSettings>
      <template #name>{{ t('settings.theme') }}</template>
      <template #settings>
        <va-color-input v-model="form.primary_color"
          :label="t('settings.primaryColor')"
          id="baseDomain"
          class="mb-3"
          immediateValidation
          @update:modelValue="changeColors()"
          :error="$page.props.errors.primary_color"
          :error-messages="$page.props.errors.primary_color"
        />
        <va-color-input v-model="form.secondary_color"
          :label="t('settings.secondaryColor')"
          id="baseDomain"
          class="mb-3"
          immediateValidation
          @update:modelValue="changeColors"
          :error="$page.props.errors.secondary_color"
          :error-messages="$page.props.errors.secondary_color"
        />
      </template>
    </AdminSettings>
    <h6 class="va-h6 my-3">{{ t('settings.welcomePage') }}</h6>
    <tinymce-editor v-model:htmlContent="form.welcome_page" />
    <va-button type="submit"
      id="submit"
      :disabled="form.processing"
      class="mr-2 my-2"
    >
      {{ t('form.update') }}
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
    errors: Object
  },
  data () {
    const { getColors } = useColors()
    const primary = getColors().primary
    const secondary = getColors().secondary

    return {
      defaultPrimaryColor: primary,
      defaultSecondaryColor: secondary,
      form: useForm({
        base_domain: this.settings.base_domain,
        terms_url: this.settings.terms_url,
        docs_url: this.settings.docs_url,
        welcome_page: this.settings.welcome_page,
        primary_color: this.settings.primary_color,
        secondary_color: this.settings.secondary_color,
        support_email: this.settings.support_email,
        error_email: this.settings.error_email
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
