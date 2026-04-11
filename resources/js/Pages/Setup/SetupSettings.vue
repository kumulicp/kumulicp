<script setup>
import BlankLayout from '@/layouts/BlankLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>{{ t('other.initialSetup') }} - Control Panel</title>
  </Head>
  <div class="groups-list">
    <div class="row justify-center align-content-center">
      <div class="flex flex-col xs12 lg4 pa-3">
        <va-card class="mb-4">
          <va-card-title>{{ t('other.initialSetup') }}</va-card-title>
          <va-card-content>
            <form @submit.prevent="form.post('/install')">
              <va-list-label class="mt-3">{{ t('user.login') }}</va-list-label>
              <va-input v-model="form.username"
                :label="t('user.username')"
                required-mark
                immediateValidation
                class="mb-3"
                :error="$page.props.errors.username"
                :error-messages="$page.props.errors.username"
                />
              <va-input v-model="form.password"
                :label="t('user.password')"
                type="password"
                required-mark
                immediateValidation
                class="mb-3"
                :error="$page.props.errors.password"
                :error-messages="$page.props.errors.password"
                />
              <va-input v-model="form.password_confirmation"
                :label="t('user.confirmPassword')"
                type="password"
                required-mark
                immediateValidation
                class="mb-3"
                :error="$page.props.errors.password_confirmation"
                :error-messages="$page.props.errors.password_confirmation"
                />
              <va-input v-model="form.contact_first_name"
                :label="t('user.firstName')"
                required-mark
                immediateValidation
                class="mb-3"
                :error="$page.props.errors.contact_first_name"
                :error-messages="$page.props.errors.contact_first_name"
                />
              <va-input v-model="form.contact_last_name"
                :label="t('user.lastName')"
                required-mark
                immediateValidation
                class="mb-3"
                :error="$page.props.errors.contact_last_name"
                :error-messages="$page.props.errors.contact_first_name"
                />
              <va-input v-model="form.contact_email"
                :label="t('user.email')"
                required-mark
                immediateValidation
                class="mb-3"
                :error="$page.props.errors.contact_email"
                :error-messages="$page.props.errors.contact_email"
                />
              <va-list-label class="mt-3">{{ t('other.other') }}</va-list-label>
              <va-input v-model="form.slug"
                :label="t('organization.slug')"
                :messages="t('setup.slugMessage')"
                required-mark
                immediateValidation
                class="mb-3"
                :error="$page.props.errors.slug"
                :error-messages="$page.props.errors.slug"
                />
              <div class="row justify-center">
                <va-button type="submit" class="mr-2 mb-2" :disabled="form.processing">{{ t('organization.createFirstAccount') }}</va-button>
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
    errors: Object
  },
  data () {
    return {
      form: useForm({
        username: '',
        password: '',
        password_confirmation: '',
        contact_first_name: '',
        contact_last_name: '',
        contact_email: '',
        contact_phone_number: '',
        name: '',
        slug: '',
        description: '',
        street: '',
        zipcode: '',
        city: '',
        state: '',
        country: 'US',
        base_domain: ''
      })
    }
  }
}
</script>
