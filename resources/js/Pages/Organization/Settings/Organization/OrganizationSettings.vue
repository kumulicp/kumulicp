<script setup lang="ts">
import CountryDropdown from '@/components/FormInputs/CountryDropdown.vue'
import StateDropdown from '@/components/FormInputs/StateDropdown.vue'
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useInputMask, createRegexMask } from 'vuestic-ui'


const orgPhoneNumber = ref()
useInputMask(createRegexMask(/(\+\d \(\d{3}\)|\d{3}) (\d){3}-(\d){4}/), orgPhoneNumber)

const phoneNumber = ref()
useInputMask(createRegexMask(/(\+\d \(\d{3}\)|\d{3}) (\d){3}-(\d){4}/), phoneNumber)
</script>
<template>
  <Head>
    <title>{{ $t('organization.organization') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('settings.settings') }}</va-card-title>
    <va-card-content class="m-0">
      <form @submit.prevent="form.put('/settings/organization')">
      <h6 class="va-h6 mb-2">{{ $t('organization.settings.about') }}</h6>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.name"
            id="name"
            :label="$t('organization.settings.organizationName')"
            immediateValidation
            :error="$page.props.errors.name"
            :error-messages="$page.props.errors.name"
            />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
        </div>
      </div>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.org_email"
            :label="$t('organization.settings.organizationEmail')"
            id="orgEmail"
            immediateValidation
            :error="$page.props.errors.org_email"
            :error-messages="$page.props.errors.org_email"
            />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
            <va-input v-model="form.org_phone_number"
              :label="$t('organization.settings.organizationPhoneNumber')"
              id="orgPhoneNumber"
              immediateValidation
              ref="orgPhoneNumber"
              placeholder="### ### ####"
              :mask="{ blocks: [3, 3, 4] }"
              :error="$page.props.errors.org_phone_number"
              :error-messages="$page.props.errors.org_phone_number"
              />
        </div>
      </div>
      <h6 class="va-h6 my-3">{{ $t('organization.settings.billingAddress') }}</h6>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.street"
            :label="$t('organization.street')"
            id="street"
            immediateValidation
            :error="$page.props.errors.street"
            :error-messages="$page.props.errors.street"
            />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.zipcode"
            :label="$t('organization.zipcode')"
            id="zipcode"
            immediateValidation
            :error="$page.props.errors.zipcode"
            :error-messages="$page.props.errors.zipcode"
            />
        </div>
      </div>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.city"
            :label="$t('organization.city')"
            id="city"
            immediateValidation
            :error="$page.props.errors.city"
            :error-messages="$page.props.errors.city"
            />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
          <state-dropdown :label="$t('organization.state')" class="va-input" id="province" :country="form.country" v-model:state="form.state" />
        </div>
      </div>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <country-dropdown :label="$t('organization.country')" required class="va-input" id="country" v-model:country="form.country" />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
        </div>
      </div>
      <h6 class="va-h6 my-3">{{ $t('organization.settings.billingContactInfo') }}</h6>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.user_first_name"
            :label="$t('organization.users.firstName')"
            id="user_first_name"
            immediateValidation
            :error="$page.props.errors.user_first_name"
            :error-messages="$page.props.errors.user_first_name"
            />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.user_last_name"
            :label="$t('organization.users.lastName')"
            id="user_last_name"
            immediateValidation
            :error="$page.props.errors.user_last_name"
            :error-messages="$page.props.errors.user_last_name"
            />
        </div>
      </div>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.user_phone_number"
            :label="$t('organization.users.phoneNumber')"
            id="user_phone_number"
            immediateValidation
            ref="phoneNumber"
            :error="$page.props.errors.user_phone_number"
            :error-messages="$page.props.errors.user_phone_number"
            />
        </div>
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-input v-model="form.user_email"
            :label="$t('auth.email')"
            id="user_email"
            immediateValidation
            :error="$page.props.errors.user_email"
            :error-messages="$page.props.errors.user_email"
            />
        </div>
      </div>
      <template v-if="registrationGloballyEnabled">
      <h6 class="va-h6 my-3">{{ $t('organization.settings.registration') }}</h6>
      <div class="row">
        <div class="flex flex-col xs12 lg6 mb-2">
          <va-switch v-model="form.self_registration_enabled"
            id="self_registration_enabled"
            left-label
            immediateValidation
            :error="$page.props.errors.self_registration_enabled"
            :error-messages="$page.props.errors.self_registration_enabled"
            >
            {{ $t('organization.settings.selfRegistrationEnabled') }}
          </va-switch>
          <p class="va-text-secondary">{{ $t('organization.settings.selfRegistrationDescription') }}</p>
        </div>
        <div v-if="form.self_registration_enabled" class="flex flex-col xs12 lg6 mb-2">
          <va-input
            readonly
            :model-value="registrationUrl"
            :label="$t('organization.settings.selfRegistrationUrl')"
          >
            <template #appendInner>
              <va-button size="small" preset="plain" @click="copyRegistrationUrl">
                {{ copied ? $t('organization.settings.copied') : $t('common.copy') }}
              </va-button>
            </template>
          </va-input>
        </div>
        <div v-else class="flex flex-col xs12 lg6 mb-2">
        </div>
      </div>
      </template>
      <div class="row justify">
        <div class="flex flex-col">
          <va-button type="submit" id="submit" class="mt-3">{{ $t('common.update') }}</va-button>
        </div>
      </div>
    </form>
    </va-card-content>
  </va-card>
</template>
<script lang="ts">
export default {
  props: {
    org: Object,
    users: Object,
    errors: Object,
    registrationGloballyEnabled: Boolean,
  },
  data () {
    return {
      contact_search: false,
      copied: false,
      form: useForm({
        _token: this.$page.props.csrf_token,
        name: this.org.name,
        description: this.org.description,
        org_email: this.org.email,
        org_phone_number: this.org.phone_number,
        street: this.org.street,
        zipcode: this.org.zipcode,
        city: this.org.city,
        state: this.org.state,
        country: this.org.country ?? 'US',
        user_id: this.org.main_contact.id,
        user_email: this.org.main_contact.email,
        user_phone_number: this.org.main_contact.phone_number,
        user_first_name: this.org.main_contact.first_name,
        user_last_name: this.org.main_contact.last_name,
        self_registration_enabled: this.org.self_registration_enabled
      })
    }
  },
  computed: {
    contact_url () {
      return '/users/' + this.form.user_id + '/edit'
    },
    contact_errors () {
      return [this.errors.user_phone_number]
    },
    has_contact_errors () {
      return (this.errors.user_id || this.errors.user_email || this.errors.user_phone_number || this.errors.first_name || this.errors.user_last_name)
    },
    registrationUrl () {
      return this.route('public.org.register', { organization: this.org.slug })
    }
  },
  methods: {
    copyRegistrationUrl () {
      navigator.clipboard.writeText(this.registrationUrl)
      this.copied = true
      setTimeout(() => { this.copied = false }, 2000)
    }
  }
}
</script>

<style lang="scss">
  .row-equal .flex {
    .va-card {
      height: 100%;
    }
  }

  .red {
    color: red;
  }

  .dashboard {
    .va-card {
      margin-bottom: 0 !important;

      &__title {
        display: flex;
        justify-content: space-between;
      }
    }
  }
</style>
