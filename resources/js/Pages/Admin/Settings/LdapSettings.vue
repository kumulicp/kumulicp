<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsLayout from './SettingsLayout.vue'
import AdminSettings from '@/components/AdminSettings.vue'
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useInputMask, createRegexMask } from 'vuestic-ui'


const phoneNumber = ref()
useInputMask(createRegexMask(/(\+\d \(\d{3}\)|\d{3}) (\d){3}-(\d){4}/), phoneNumber)
</script>
<template>
  <Head>
    <title>{{ $t('settings.editServerSettings') }} - Control Panel</title>
  </Head>
  <form @submit.prevent="form.put('/admin/settings/ldap')">
    <AdminSettings>
      <template #name>{{ $t('settings.ldapAttributes') }}</template>
      <template #settings>
        <va-input v-model="form.name"
          :label="$t('settings.ldapFullName')"
          class="mb-3"
          id="fullName"
          placeholder="displayName"
          immediateValidation
          :error="$page.props.errors.name"
          :error-messages="$page.props.errors.name"
          />
        <va-input v-model="form.first_name"
          :label="$t('user.firstName')"
          class="mb-3"
          id="firstName"
          placeholder="givenName"
          immediateValidation
          :error="$page.props.errors.first_name"
          :error-messages="$page.props.errors.first_name"
          />
        <va-input v-model="form.last_name"
          :label="$t('user.lastName')"
          class="mb-3"
          id="lastName"
          placeholder="sn"
          immediateValidation
          :error="$page.props.errors.last_name"
          :error-messages="$page.props.errors.last_name"
          />
        <va-input v-model="form.phone_number"
          :label="$t('user.phoneNumber')"
          class="mb-3"
          id="phoneNumber"
          placeholder="telephoneNumber"
          immediateValidation
          :error="$page.props.errors.phone_number"
          :error-messages="$page.props.errors.phone_number"
          />
        <va-input v-model="form.username"
          :label="$t('user.username')"
          class="mb-3"
          id="username"
          placeholder="uid"
          immediateValidation
          :error="$page.props.errors.username"
          :error-messages="$page.props.errors.username"
          />
        <va-input v-model="form.personal_email"
          :label="$t('settings.ldapPersonalEmail')"
          class="mb-3"
          id="personalEmail"
          placeholder="mail"
          immediateValidation
          :error="$page.props.errors.personal_email"
          :error-messages="$page.props.errors.personal_email"
          />
        <va-input v-model="form.org_email"
          :label="$t('settings.ldapOrganizationEmail')"
          class="mb-3"
          id="orgEmail"
          placeholder="mail"
          immediateValidation
          :error="$page.props.errors.org_email"
          :error-messages="$page.props.errors.org_email"
          />
        <va-input v-model="form.access_type"
          :label="$t('admin.plans.accessType')"
          class="mb-3"
          id="accessType"
          placeholder="employeeType"
          immediateValidation
          :error="$page.props.errors.access_type"
          :error-messages="$page.props.errors.access_type"
          />
        <va-input v-model="form.password"
          :label="$t('user.password')"
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
