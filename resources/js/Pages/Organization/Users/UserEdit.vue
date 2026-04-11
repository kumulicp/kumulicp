<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import UserLayout from './UserLayout.vue'
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
    <title>Edit User - Control Panel</title>
  </Head>
  <div class="user-profile">
    <div class="row">
      <div class="flex xs12">
        <h5 class="va-h5 mt-0 pt-0">{{ user.name }}</h5>
      </div>
    </div>
    <form @submit.prevent="form.put('/users/'+user.id)">
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.id"
            label="Username"
            id="userName"
            readonly
            />
        </div>
        <div v-if="organizations.length > 1" class="flex flex-col xs12 lg6 mb-2">
          <va-select
            v-model="form.organization"
            label="Organization"
            :options="organizations"
            text-by="name"
            value-by="id"
            placement="auto"
            immediateValidation
            :errors="$page.props.errors.organization"
            :error-messages="$page.props.errors.organization"
            />
        </div>
      </div>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.first_name"
            label="First Name"
            id="firstName"
            required-mark
            immediateValidation
            :error="$page.props.errors.first_name"
            :error-messages="$page.props.errors.first_name"
            />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.last_name"
            label="Last Name"
            id="lastName"
            required-mark
            immediateValidation
            :error="$page.props.errors.last_name"
            :error-messages="$page.props.errors.last_name"
            />
        </div>
      </div>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.personal_email"
            label="Personal Email"
            id="personalEmail"
            required-mark
            immediateValidation
            :error="$page.props.errors.personal_email"
            :error-messages="$page.props.errors.personal_email"
            />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.phone_number"
            label="Phone Number"
            id="phoneNumber"
            immediateValidation
            placeholder="(###) ###-####"
            ref="phoneNumber"
            :error="$page.props.errors.phone_number"
            :error-messages="$page.props.errors.phone_number"
            />
        </div>
      </div>
      <div v-if="user.org_emails || user.can.add_email_account" class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <h5>Organizational Email</h5>
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
          <template v-for="(org_email, index) in user.org_emails" :key="index">
            <div class="row">
              <div class="flex flex-col xs11">
                <a :href="'mailto:'+org_email" class="mr-3">
                  <div class="py-1">{{ org_email }}</div>
                </a>
              </div>
              <div class="flex flex-col xs1">
                <a :href="'/users/'+user.id+'/remove/accountemail/'+org_email" class="ml-3">
                  <va-icon name="entypo-cancel"
                    title="Delete email"
                    color="danger"
                    />
                </a>
              </div>
            </div>
          </template>
          <template v-if="user.can.add_email_account">
            <template v-for="(domain, index) in email_domains" :key="index">
              <a :href="'/users/'+user.id+'/create/accountemail/'+domain.id">
                <div class="py-5">
                  Add email account for @{{ domain.name }}
                </div>
              </a>
            </template>
          </template>
        </div>
      </div>
      <div v-for="(storage, index) in user.additional_storage" :key="index" class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <h5>{{ storage.app_name }} Storage</h5>
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-select
            v-model="form['additional_storage'][storage.id]"
            :options="storage.options"
            immediateValidation
            text-by="text"
            value-by="value"
            placement="auto"
            />
          <div v-if="form['additional_storage'][storage.id] != storage.quantity" class="subscription_warning"><b>**Warning**</b> changing your {{ storage.name }} storage may affect your subscription price</div>
        </div>
      </div>
      <va-button type="submit" id="submit" class="mr-2 mb-2" :disabled="form.processing">Submit</va-button>
    </form>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(UserLayout, () => page))
  },
  props: {
    user: Object,
    errors: Object,
    organizations: Object,
    email_domains: Object
  },
  data () {
    const additionalStorage = {}
    Object.values(this.user.additional_storage).forEach((storage) => {
      additionalStorage[storage.id] = storage.quantity
    })

    return {
      form: useForm({
        id: this.user.id,
        first_name: this.user.first_name,
        last_name: this.user.last_name,
        phone_number: this.user.phone_number,
        personal_email: this.user.personal_email,
        additional_storage: additionalStorage,
        organization: this.user.organization
      })
    }
  }
}
</script>

<style lang="scss">
.subscription_warning {
 color: var(--va-red-800)
}
</style>
