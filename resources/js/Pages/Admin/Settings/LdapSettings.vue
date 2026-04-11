<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsLayout from './SettingsLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useInputMask, createRegexMask } from 'vuestic-ui'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const phoneNumber = ref()
useInputMask(createRegexMask(/(\+\d \(\d{3}\)|\d{3}) (\d){3}-(\d){4}/), phoneNumber)
</script>
<template>
  <Head>
    <title>Edit Server Settings - Control Panel</title>
  </Head>
  <form @submit.prevent="form.put('/admin/settings/ldap')">
    <AdminSettings>
      <template #name>{{ t('settings.ldapAttributes') }}</template>
      <template #settings>
        <va-input v-model="form.name"
          label="Full Name"
          class="mb-3"
          id="fullName"
          placeholder="displayName"
          immediateValidation
          :error="$page.props.errors.name"
          :error-messages="$page.props.errors.name"
          />
        <va-input v-model="form.first_name"
          label="First name"
          class="mb-3"
          id="firstName"
          placeholder="givenName"
          immediateValidation
          :error="$page.props.errors.first_name"
          :error-messages="$page.props.errors.first_name"
          />
        <va-input v-model="form.last_name"
          label="Last name"
          class="mb-3"
          id="lastName"
          placeholder="sn"
          immediateValidation
          :error="$page.props.errors.last_name"
          :error-messages="$page.props.errors.last_name"
          />
        <va-input v-model="form.phone_number"
          label="Phone number"
          class="mb-3"
          id="phoneNumber"
          placeholder="telephoneNumber"
          immediateValidation
          :error="$page.props.errors.phone_number"
          :error-messages="$page.props.errors.phone_number"
          />
        <va-input v-model="form.username"
          label="Username"
          class="mb-3"
          id="username"
          placeholder="uid"
          immediateValidation
          :error="$page.props.errors.username"
          :error-messages="$page.props.errors.username"
          />
        <va-input v-model="form.personal_email"
          label="Personal Email"
          class="mb-3"
          id="personalEmail"
          placeholder="mail"
          immediateValidation
          :error="$page.props.errors.personal_email"
          :error-messages="$page.props.errors.personal_email"
          />
        <va-input v-model="form.org_email"
          label="Organization Email"
          class="mb-3"
          id="orgEmail"
          placeholder="mail"
          immediateValidation
          :error="$page.props.errors.org_email"
          :error-messages="$page.props.errors.org_email"
          />
        <va-input v-model="form.access_type"
          label="Access Type"
          class="mb-3"
          id="accessType"
          placeholder="employeeType"
          immediateValidation
          :error="$page.props.errors.access_type"
          :error-messages="$page.props.errors.access_type"
          />
        <va-input v-model="form.password"
          label="Password"
          class="mb-3"
          id="password"
          placeholder="userPassword"
          immediateValidation
          :error="$page.props.errors.password"
          :error-messages="$page.props.errors.password"
          />
      </template>
    </AdminSettings>
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
    return {
      form: useForm({
        first_name: this.settings.first_name,
        last_name: this.settings.last_name,
        email: this.settings.email,
        phone_number: this.settings.phone_number,
        username: this.settings.username,
        personal_email: this.settings.personal_email,
        name: this.settings.name,
        org_email: this.settings.org_email,
        access_type: this.settings.access_type,
        password: this.settings.password
      })
    }
  }
}
</script>

<style></style>
